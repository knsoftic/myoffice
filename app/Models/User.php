<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PanelType;
use App\Enums\ThemePreference;
use App\Enums\UserStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;
use Throwable;

/**
 * A single identity for all five panels (decision D1): one `users` table, one `web` guard,
 * panel access decided by the roles the user holds.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $whatsapp
 * @property string|null $avatar_path
 * @property UserStatus $status
 * @property string|null $status_reason
 * @property Carbon|null $status_changed_at
 * @property ThemePreference $theme
 * @property string $locale
 * @property string|null $timezone
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property Carbon|null $password_changed_at
 * @property bool $must_change_password
 * @property int|null $branch_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property-read string $avatar_url
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Role> $roles
 */
class User extends Authenticatable
{
    use Blameable;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use LogsActivityWithContext;
    use Notifiable;
    use SoftDeletes;

    /**
     * Name of the role that bypasses every permission check (phase-01 §5/§6).
     * Declared once here so nothing else has to spell it out.
     */
    public const SUPER_ADMIN_ROLE = 'Super Admin';

    /**
     * Mass-assignable attributes. Deliberately an explicit whitelist: `created_by`,
     * `updated_by`, `email_verified_at` and `remember_token` are written by the
     * Blameable trait / framework, never by a request payload.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'whatsapp',
        'avatar_path',
        'status',
        'status_reason',
        'status_changed_at',
        'theme',
        'locale',
        'timezone',
        'last_login_at',
        'last_login_ip',
        'password_changed_at',
        'must_change_password',
        'branch_id',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'status' => UserStatus::class,
            'theme' => ThemePreference::class,
            'must_change_password' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Activity log
    |--------------------------------------------------------------------------
    */

    protected function activityModule(): ?string
    {
        return 'users';
    }

    /**
     * Login bookkeeping changes on every sign-in and is not an audit event.
     *
     * @return array<int, string>
     */
    protected function activityIgnoredAttributes(): array
    {
        return [
            'created_at',
            'updated_at',
            'updated_by',
            'remember_token',
            'last_login_at',
            'last_login_ip',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Avatar to render, always a usable src: the uploaded file on the `public` disk, or a
     * generated initials avatar (inline SVG data URI — no external service, works offline).
     *
     * @return Attribute<string, never>
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(function (): string {
            $path = trim((string) $this->avatar_path);

            if ($path === '') {
                return $this->initialsAvatar();
            }

            if (Str::startsWith($path, ['http://', 'https://', '//', 'data:', '/'])) {
                return $path;
            }

            try {
                $disk = Storage::disk('public');

                if ($disk->exists($path)) {
                    return $disk->url($path);
                }
            } catch (Throwable) {
                // Disk not configured / unreachable: fall through to the generated avatar.
            }

            return $this->initialsAvatar();
        });
    }

    /**
     * One or two letters for the avatar and compact UI chips.
     */
    public function initials(): string
    {
        $name = trim((string) $this->name);

        if ($name === '') {
            $name = trim(Str::before((string) $this->email, '@'));
        }

        $words = preg_split('/[\s._\-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '?';
        }

        $first = mb_substr((string) $words[0], 0, 1);
        $last = count($words) > 1 ? mb_substr((string) $words[count($words) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * Timezone to render dates in; falls back to the application timezone.
     */
    public function effectiveTimezone(): string
    {
        $timezone = trim((string) $this->timezone);

        return $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC');
    }

    /**
     * Locale to use for this user; falls back to the application locale.
     */
    public function effectiveLocale(): string
    {
        $locale = trim((string) $this->locale);

        return $locale !== '' ? $locale : (string) config('app.locale', 'en');
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    /**
     * Does this user hold the Super Admin role?
     */
    public function isSuperAdmin(): bool
    {
        try {
            return $this->hasRole(self::SUPER_ADMIN_ROLE);
        } catch (Throwable) {
            // Permission tables missing (fresh install / mid-migration).
            return false;
        }
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * May this account authenticate at all? (phase-01 §7)
     */
    public function canLogin(): bool
    {
        return $this->status instanceof UserStatus && $this->status->canLogin();
    }

    public function mustChangePassword(): bool
    {
        return (bool) $this->must_change_password;
    }

    /*
    |--------------------------------------------------------------------------
    | Panels
    |--------------------------------------------------------------------------
    */

    /**
     * Every panel this user can reach, taken from the `panel` column of their roles.
     *
     * @return Collection<int, PanelType>
     */
    public function panels(): Collection
    {
        try {
            $roles = $this->roles;
        } catch (Throwable) {
            return new Collection;
        }

        return $roles
            ->map(static function (object $role): ?PanelType {
                $panel = $role->panel ?? null;

                if ($panel instanceof PanelType) {
                    return $panel;
                }

                return is_string($panel) ? PanelType::tryFrom($panel) : null;
            })
            ->filter()
            ->unique(static fn (PanelType $panel): string => $panel->value)
            ->values();
    }

    /**
     * Where the user lands after login: the panel of their most powerful role
     * (lowest `roles.level` wins). Users without a usable role default to the staff panel.
     */
    public function primaryPanel(): PanelType
    {
        try {
            $roles = $this->roles;
        } catch (Throwable) {
            return PanelType::staffPanel();
        }

        $panel = $roles
            // Lowest level first (Super Admin is 1); the role id breaks ties deterministically.
            ->sortBy(static fn (object $role): array => [
                (int) ($role->level ?? 50),
                (int) ($role->getKey() ?? 0),
            ])
            ->map(static function (object $role): ?PanelType {
                $value = $role->panel ?? null;

                if ($value instanceof PanelType) {
                    return $value;
                }

                return is_string($value) ? PanelType::tryFrom($value) : null;
            })
            ->filter()
            ->first();

        return $panel instanceof PanelType ? $panel : PanelType::staffPanel();
    }

    /**
     * Panel isolation check used by the `panel:` middleware. Holding a role of that panel is
     * the only way in — Super Admin does not get a free pass into the student or client panel.
     */
    public function canAccessPanel(PanelType|string $panel): bool
    {
        $panel = $panel instanceof PanelType ? $panel : PanelType::tryFrom($panel);

        if ($panel === null) {
            return false;
        }

        return $this->panels()->contains(
            static fn (PanelType $available): bool => $available === $panel
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Active->value);
    }

    /**
     * Free-text search over name, email, phone and whatsapp.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $query->where(function (Builder $builder) use ($like): void {
            $builder->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('whatsapp', 'like', $like);
        });
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeForPanel(Builder $query, PanelType|string $panel): Builder
    {
        $value = $panel instanceof PanelType ? $panel->value : $panel;

        return $query->whereHas('roles', function (Builder $builder) use ($value): void {
            $builder->where('panel', $value);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<LoginHistory, $this>
     */
    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class)->latest('id');
    }

    /**
     * Rows of the `sessions` table belonging to this user (database session driver).
     *
     * @return HasMany<Session, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class, 'user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Deterministic initials avatar as an inline SVG data URI.
     */
    private function initialsAvatar(): string
    {
        $initials = $this->initials();

        $palette = ['#4f46e5', '#0ea5e9', '#059669', '#d97706', '#e11d48', '#7c3aed', '#0d9488', '#db2777'];
        $seed = mb_strtolower(trim((string) ($this->email ?: $this->name)));
        $background = $palette[abs(crc32($seed)) % count($palette)];

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" width="128" height="128">'
            .'<rect width="128" height="128" rx="64" fill="'.$background.'"/>'
            .'<text x="64" y="64" dy="0.35em" text-anchor="middle" fill="#ffffff" '
            .'font-family="Inter, ui-sans-serif, system-ui, sans-serif" font-size="52" font-weight="600">'
            .htmlspecialchars($initials, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            .'</text></svg>';

        return 'data:image/svg+xml;charset=UTF-8,'.rawurlencode($svg);
    }
}
