<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\SettingsRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use LogicException;
use Throwable;

/**
 * One configuration value (phase-01 §1.4, decision D10).
 *
 * Reads normally go through App\Support\SettingsRepository / the `setting()` helper — one
 * cached query for the whole table. This model is the Eloquent face used by the settings
 * screen, and it owns two behaviours the repository relies on:
 *
 *   · transparent encryption — `value` is stored encrypted whenever `is_encrypted` is true
 *     and handed back in clear text when read, so a secret never sits in the cache or in a
 *     Blade view in plain form by accident. Only ciphertext loaded from the database is ever
 *     decrypted; a value assigned from input never is (no decryption oracle);
 *   · cache invalidation — any save flushes the cached settings payload;
 *   · no deletes — application code may not delete a settings row (the test suite may).
 *
 * Encryption happens on `saving` (not in a mutator) so the order of mass assignment cannot
 * matter: `Setting::create(['value' => 'x', 'is_encrypted' => true])` works either way round.
 *
 * @property int $id
 * @property string $group
 * @property string $key
 * @property string|null $value decrypted on read, encrypted on write when is_encrypted
 * @property string $type
 * @property array<array-key, mixed>|null $options
 * @property bool $is_encrypted
 * @property bool $is_public
 * @property string|null $label
 * @property string|null $description
 * @property int $sort_order
 * @property int|null $updated_by who last wrote this key (phase-02 §1, stamped by SettingsService)
 * @property bool $is_readonly key that may only change through the console or the environment
 */
class Setting extends Model
{
    public const TYPE_STRING = 'string';

    public const TYPE_TEXT = 'text';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_DECIMAL = 'decimal';

    public const TYPE_JSON = 'json';

    public const TYPE_FILE = 'file';

    public const TYPE_SELECT = 'select';

    /**
     * Every type the `type` column accepts (phase-01 §1.4).
     *
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_STRING,
        self::TYPE_TEXT,
        self::TYPE_BOOLEAN,
        self::TYPE_INTEGER,
        self::TYPE_DECIMAL,
        self::TYPE_JSON,
        self::TYPE_FILE,
        self::TYPE_SELECT,
    ];

    /** Placeholder shown instead of a secret. */
    public const MASK = '••••••••';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
        'options',
        'is_encrypted',
        'is_public',
        'label',
        'description',
        'sort_order',
        'updated_by',
        'is_readonly',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_encrypted' => 'boolean',
            'is_public' => 'boolean',
            'is_readonly' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Bring the stored column in line with the is_encrypted flag, whichever of the two
        // the caller changed.
        static::saving(static function (Setting $setting): void {
            $setting->applyEncryptionToRawValue();
        });

        static::saved(static function (Setting $setting): void {
            $setting->flushSettingsCache();
        });

        // No settings row is ever deleted by application code: a value the business entered
        // outlives the code that read it (SettingSeeder reports such rows, it never removes them),
        // and a deleted document counter would read its default of 1 again (D62). The test suite
        // may still delete a fixture row.
        static::deleting(static function (Setting $setting): void {
            if (! app()->runningUnitTests()) {
                throw new LogicException(sprintf(
                    'Setting [%s.%s] may not be deleted: settings rows are never removed by application code.',
                    (string) $setting->group,
                    (string) $setting->key,
                ));
            }
        });

        static::deleted(static function (Setting $setting): void {
            $setting->flushSettingsCache();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Value handling
    |--------------------------------------------------------------------------
    */

    /**
     * Clear-text value: decrypted on the way out when the row was LOADED encrypted.
     *
     * Assignment stores the plain string as-is; `saving` does the encrypting.
     *
     * **Only ciphertext that came out of the database is ever decrypted.** A value assigned from
     * input is returned exactly as assigned, even when it happens to be a valid Laravel payload.
     * The model used to trial-decrypt whatever it held, which made it a decryption oracle: post any
     * ciphertext the application key produced (an encrypted cookie, another encrypted column) as a
     * setting and it came back — on the form, on the public site, or as the SMTP password handed to
     * a host of the poster's choosing — as plain text.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function value(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): ?string {
                if ($value === null) {
                    return null;
                }

                $raw = (string) $value;

                if ($raw === '' || ! $this->holdsStoredCiphertext($raw)) {
                    return $raw;
                }

                return self::decryptOrNull($raw) ?? $raw;
            },
            set: static fn (mixed $value): ?string => $value === null ? null : (string) $value,
        );
    }

    /**
     * The value cast according to the `type` column.
     *
     * Decimals stay strings so money never meets a float (CLAUDE.md §1.4).
     */
    public function typedValue(): mixed
    {
        $value = $this->value;

        if ($value === null) {
            return null;
        }

        return match ($this->type) {
            self::TYPE_BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            self::TYPE_INTEGER => (int) $value,
            self::TYPE_DECIMAL => $value === '' ? '0' : $value,
            self::TYPE_JSON => self::decodeJson($value),
            default => $value,
        };
    }

    /**
     * What to show in a form: secrets are never rendered, only masked.
     */
    public function maskedValue(): ?string
    {
        if (! $this->isEncrypted()) {
            $value = $this->value;

            return $value === null ? null : (string) $value;
        }

        $raw = $this->getRawOriginal('value');

        return $raw === null || $raw === '' ? null : self::MASK;
    }

    /**
     * Options for a `select` setting.
     *
     * @return array<array-key, mixed>
     */
    public function optionList(): array
    {
        $options = $this->options;

        return is_array($options) ? $options : [];
    }

    public function isEncrypted(): bool
    {
        return (bool) ($this->attributes['is_encrypted'] ?? false);
    }

    public function isPublic(): bool
    {
        return (bool) ($this->attributes['is_public'] ?? false);
    }

    /**
     * A read-only key is rendered disabled by the settings screen and refused by the Form
     * Request: it changes only through the console or the environment (phase-02 §1).
     */
    public function isReadonly(): bool
    {
        return (bool) ($this->attributes['is_readonly'] ?? false);
    }

    /**
     * Dotted name used by the `setting()` helper — "company.name".
     *
     * @return Attribute<string, never>
     */
    protected function fullKey(): Attribute
    {
        return Attribute::get(fn (): string => $this->group.'.'.$this->key);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * The user who last wrote this key — feeds each settings group's "last updated by X" footer
     * (phase-02 §5). Null for a seeded value nobody has edited yet.
     *
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<Setting>  $query
     * @return Builder<Setting>
     */
    public function scopeForGroup(Builder $query, string $group): Builder
    {
        return $query->where('group', $group);
    }

    /**
     * @param  Builder<Setting>  $query
     * @return Builder<Setting>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * @param  Builder<Setting>  $query
     * @return Builder<Setting>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('group')->orderBy('sort_order')->orderBy('key');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Encrypt (or decrypt) the stored column so it matches the `is_encrypted` flag.
     *
     * Whether the column holds ciphertext is decided by **where the value came from**, never by
     * trying to decrypt it (see value()):
     *
     *   · a value assigned from input is plain text by definition. Flagged encrypted, it is
     *     encrypted — even if it looks like ciphertext, it is encrypted again, so reading it back
     *     returns exactly what was submitted and never its decryption. Not flagged, it is stored
     *     exactly as given;
     *   · a value loaded from the database and left untouched is ciphertext if and only if the row
     *     was loaded with `is_encrypted`. Only then may it be decrypted — when the flag is being
     *     switched off — and a loaded plain value is encrypted when the flag is being switched on.
     */
    protected function applyEncryptionToRawValue(): void
    {
        $raw = $this->attributes['value'] ?? null;

        if ($raw === null) {
            return;
        }

        $raw = (string) $raw;

        if ($raw === '') {
            return;
        }

        $storedCiphertext = $this->holdsStoredCiphertext($raw);

        if ($this->isEncrypted()) {
            if (! $storedCiphertext) {
                $encrypted = self::encryptOrNull($raw);

                if ($encrypted !== null) {
                    $this->attributes['value'] = $encrypted;
                }
            }

            return;
        }

        // Flag turned off on a row that was loaded encrypted: store its clear text again.
        if ($storedCiphertext) {
            $plain = self::decryptOrNull($raw);

            if ($plain !== null) {
                $this->attributes['value'] = $plain;
            }
        }
    }

    /**
     * Is this raw column value the untouched ciphertext of a row loaded with `is_encrypted`?
     *
     * True only for a persisted model whose `value` has not been reassigned since it was read and
     * whose ORIGINAL `is_encrypted` flag was set. A value assigned from input — including one
     * shaped exactly like a Laravel payload — is never treated as ciphertext.
     */
    private function holdsStoredCiphertext(string $raw): bool
    {
        if (! $this->exists) {
            return false;
        }

        $original = $this->getRawOriginal('value');

        if ($original === null || (string) $original !== $raw) {
            return false;
        }

        return (bool) $this->getRawOriginal('is_encrypted', false);
    }

    /**
     * Drop the cached settings payload so the next read reloads it.
     */
    protected function flushSettingsCache(): void
    {
        try {
            app(SettingsRepository::class)->flush();
        } catch (Throwable) {
            // Container or cache store unavailable (console bootstrap): nothing to flush.
        }
    }

    /**
     * Plain text when the string is a decryptable payload, null when it is not encrypted.
     */
    private static function decryptOrNull(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function encryptOrNull(string $value): ?string
    {
        try {
            return Crypt::encryptString($value);
        } catch (Throwable) {
            // No APP_KEY yet: better to store the value than to lose it.
            return null;
        }
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decodeJson(string $value): ?array
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
