<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Events\SettingsChanged;
use App\Models\Setting;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Support\ConfigureFromSettings;
use App\Support\Money;
use App\Support\SettingsRegistry;
use App\Support\SettingsRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * The only write path into the `settings` table (phase-02 §3).
 *
 * Nothing else — no controller, no seeder, no later phase — may write `settings.value`. Everything
 * that makes a settings write safe lives here, once:
 *
 *   · **Validated** against `SettingsRegistry::rulesFor($group)`, filtered to the keys actually
 *     submitted so a partial save cannot trip a `required` rule on a field the form did not send.
 *     `UpdateSettingsRequest` runs the same rules; this repeats them because `Gate::before` waves
 *     a Super Admin past every policy and later phases will call this service from new places.
 *   · **Uploads** land under `settings/` with a random hashed name — never the name the browser
 *     sent — on the disk the field's own `public` flag dictates (D21: a public logo on the
 *     `public` disk, anything private on the private disk for a controller to stream). The file
 *     being replaced is deleted, and a file written for a transaction that then fails is deleted
 *     again, so neither an orphan nor a dangling path survives.
 *   · **Secrets** flagged `encrypted` in the registry are stored through the `Setting` model's
 *     transparent `Crypt` layer, are never echoed back into a form, and are logged as the literal
 *     `[encrypted]` — the old value too, because "the password used to be hunter2" is as much of a
 *     leak as the new one.
 *   · **One transaction** per group, `updated_by` stamped on every row it touches, the cached
 *     settings payload flushed, and the runtime configuration re-applied so the response the
 *     administrator gets back already reflects what they saved.
 *   · **One activity entry per changed key**, with old and new values (phase-02 §6 "Settings
 *     write"). An unchanged key writes nothing at all.
 *
 * **A write with no actor fails closed.** `update()`, `resetGroup()` and `deleteFile()` authorize
 * the given actor (or the signed-in user); with neither, they refuse — unless the caller asked for
 * `asSystem()` from a console process (a seeder, a command, a test).
 *
 * Two refusals that are not permission checks, and therefore raise `ActionNotAllowedException`
 * rather than a 403:
 *
 *   · a `readonly` key — declared readonly by the registry (`security.two_factor_enabled`, every
 *     `*_next_number` counter, D62) **or** stored with `is_readonly = 1` — submitted with a value
 *     that differs from the stored one. Those keys move through the console or the environment
 *     only. A readonly key submitted with its current value is dropped silently, so a form that
 *     echoes disabled fields back is not punished for a no-op;
 *   · an upload whose file name carries an executable extension in **any** segment
 *     (`logo.php.png`), on top of the registry's own `image` / `mimes` rules.
 *
 * ### The Admin SMTP carve-out (phase-01 §5, delivered here)
 *
 * phase-01 §5 promised the Admin role "everything except … `settings.edit` of SMTP" and Phase 1
 * never delivered it. It is a **group-level permission**: `settings.edit` still governs every
 * group, and the `mail` group additionally demands `settings.edit_mail`, which only Super Admin
 * holds. `permissionFor()` is the single resolver — it honours a narrower permission declared by
 * `SettingsRegistry::groups()` and otherwise falls back to this class's own carve-out map, so the
 * rule holds whether or not the registry has caught up. Enforced here in the service as well as
 * in the controller and the routes, because a permission checked in one place is a permission
 * waiting to be bypassed.
 */
final class SettingsService
{
    use WritesAuditTrail;

    /** Activity-log module for every entry this service writes. */
    public const MODULE = 'settings';

    /** Directory that holds every uploaded settings file, on whichever disk. */
    public const DIRECTORY = 'settings';

    /** Disk for a file whose field is flagged `public` — served straight off /storage. */
    public const PUBLIC_DISK = 'public';

    /** Disk for everything else: private, streamed by a controller that re-runs the permissions. */
    public const PRIVATE_DISK = 'local';

    /** The settings group carved out of the Admin role. */
    public const MAIL_GROUP = 'mail';

    /**
     * The SMTP carve-out permission — Super Admin only.
     *
     * Must be declared on the `settings` module in `PermissionRegistry` (a narrow ability
     * `edit_mail`, the same shape as `project_payments.link_invoice`, D43) and excluded from the
     * Admin role in `RoleSeeder`. Until the permission row exists, spatie resolves it to false for
     * everyone and `Gate::before` still lets Super Admin through, so the carve-out holds either
     * way — but the role editor cannot show it.
     */
    public const EDIT_MAIL_PERMISSION = 'settings.edit_mail';

    /** What an encrypted value is logged as, in the activity log and nowhere else. */
    public const REDACTED = '[encrypted]';

    /**
     * Group => the extra permission that group demands on top of `settings.edit`.
     *
     * @var array<string, string>
     */
    private const GROUP_PERMISSIONS = [
        self::MAIL_GROUP => self::EDIT_MAIL_PERMISSION,
    ];

    /**
     * Extensions refused in any segment of an uploaded file name, whatever the MIME type says.
     *
     * One list for the whole application, declared by the registry (it also strips them from
     * `security.allowed_file_types`), so the two can never disagree.
     *
     * @var list<string>
     */
    private const BLOCKED_EXTENSIONS = SettingsRegistry::NEVER_UPLOADABLE_EXTENSIONS;

    /**
     * True only on a clone made by asSystem() inside a console process: the one case in which a
     * write with no authenticated actor is authorized.
     */
    private bool $system = false;

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * A copy of this service that may write with no signed-in actor — for a seeder, an artisan
     * command, a queued job or a test, and for nothing reachable over HTTP.
     *
     * Without it a missing actor FAILS CLOSED (canEditGroup() answers no). Before, a null actor was
     * waved through as "the console is trusted", which meant any later code path that lost track of
     * the user — a queued listener, a helper called before authentication — could write settings
     * with no permission check at all. Being in the console is now necessary but not sufficient:
     * the caller has to ask for it by name.
     *
     * @throws AuthorizationException outside a console process
     */
    public function asSystem(): self
    {
        if (! app()->runningInConsole()) {
            throw new AuthorizationException('Settings may only be written without a signed-in user from the console.');
        }

        $system = clone $this;
        $system->system = true;

        return $system;
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    /**
     * The permission required to edit one group.
     *
     * A narrower permission declared by the registry wins; otherwise the carve-out map applies;
     * otherwise it is plain `settings.edit`.
     */
    public function permissionFor(string $group): string
    {
        $declared = SettingsRegistry::group($group)['permission'] ?? null;
        $declared = is_string($declared) && $declared !== '' ? $declared : SettingsRegistry::PERMISSION;

        if ($declared !== SettingsRegistry::PERMISSION) {
            return $declared;
        }

        return self::GROUP_PERMISSIONS[$group] ?? $declared;
    }

    /**
     * Does this group need more than `settings.edit`?
     */
    public function isRestrictedGroup(string $group): bool
    {
        return $this->permissionFor($group) !== SettingsRegistry::PERMISSION;
    }

    /**
     * May this user edit this group?
     *
     * Both halves are required for a restricted group: `settings.edit` **and** the group
     * permission. With no authenticated user the answer is **no** — unless this instance came from
     * asSystem() and the process really is a console process (checked again here, so a clone that
     * somehow outlives its command still cannot write over HTTP).
     */
    public function canEditGroup(string $group, ?Authenticatable $user = null): bool
    {
        $user = $user ?? Auth::user();

        if ($user === null) {
            return $this->system && app()->runningInConsole();
        }

        $gate = Gate::forUser($user);

        if (! $gate->check(SettingsRegistry::PERMISSION)) {
            return false;
        }

        $permission = $this->permissionFor($group);

        return $permission === SettingsRegistry::PERMISSION || $gate->check($permission);
    }

    /**
     * @throws AuthorizationException
     */
    public function assertCanEditGroup(string $group, ?Authenticatable $user = null): void
    {
        if ($this->canEditGroup($group, $user)) {
            return;
        }

        throw new AuthorizationException(sprintf(
            'You are not allowed to change the %s settings.',
            SettingsRegistry::group($group)['label'] ?? $group,
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    /**
     * Save one group.
     *
     * `$input` is keyed by bare field key within the group (`['name' => 'Acme']`); a dotted key
     * (`'company.name'`) is accepted too and normalised. Values are whatever the form sent —
     * strings, arrays, booleans, or an `UploadedFile` for an `image` / `file` field. A key that is
     * absent is left untouched, which is how a password survives a save that did not retype it:
     * an encrypted field submitted empty, or submitted as the mask, means "unchanged".
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array{old: mixed, new: mixed}> dotted key => what moved, for the keys that
     *                                                      actually changed; count() of it is the number
     *                                                      of values written
     *
     * @throws InvalidArgumentException unknown group
     * @throws AuthorizationException the actor may not edit this group
     * @throws ValidationException a submitted value fails the registry rules
     * @throws ActionNotAllowedException a readonly key or a refused upload
     */
    public function update(string $group, array $input, ?Authenticatable $actor = null): array
    {
        $fields = SettingsRegistry::fields($group);

        $actor = $actor ?? Auth::user();

        $this->assertCanEditGroup($group, $actor);

        $input = $this->normaliseKeys($group, $input);

        $readonly = $this->readonlyKeys($group);

        $this->assertNoReadonlyChange($group, $fields, $input, $readonly);

        $payload = $this->prunePayload($fields, $input, $readonly);

        if ($payload === []) {
            return [];
        }

        $payload = $this->followCurrencySymbol($group, $fields, $payload);

        // Restate every decimal at its scale BEFORE validating, so the rules, the 100 % cap and the
        // stored column all judge the same value ('5000.' is judged as 5000.0000, not skipped).
        // Phase 2 review low 1: only an exact restatement — a value with more decimals than its
        // scale is left as submitted and refused by validate(), never rounded.
        foreach ($payload as $key => $value) {
            $payload[$key] = SettingsRegistry::normaliseDecimal($fields[$key], $value);
        }

        $this->validate($group, $payload);

        /** @var array<string, array{old: mixed, new: mixed}> $changes */
        $changes = [];

        /** @var list<array{disk: string, path: string}> $written */
        $written = [];

        /** @var list<array{disk: string, path: string}> $obsolete */
        $obsolete = [];

        try {
            DB::transaction(function () use ($group, $fields, $payload, $actor, &$changes, &$written, &$obsolete): void {
                foreach ($payload as $key => $value) {
                    $field = $fields[$key];

                    if ($value instanceof UploadedFile) {
                        $stored = $this->storeUpload($field, $value);
                        $written[] = $stored;
                        $value = $stored['path'];
                    }

                    $change = $this->writeKey($group, $key, $field, $value, $actor, $obsolete);

                    if ($change !== null) {
                        $changes[$group.'.'.$key] = $change;
                    }
                }
            });
        } catch (Throwable $exception) {
            // Storage is not transactional: a file written for a transaction that rolled back
            // must not be left behind pointing at nothing.
            foreach ($written as $file) {
                $this->deleteFileFromDisk($file['disk'], $file['path']);
            }

            throw $exception;
        }

        if ($changes !== []) {
            foreach ($obsolete as $file) {
                $this->deleteFileFromDisk($file['disk'], $file['path']);
            }

            $this->afterWrite(array_keys($changes), $actor);
        }

        return $changes;
    }

    /**
     * Restore every key in a group to its registry default.
     *
     * Readonly keys are left alone; an uploaded file is removed from its disk as well as from the
     * row, because a default of "no logo" that still has a file on disk is not a default. Each key
     * that actually moves writes its own activity entry, exactly as an edit does.
     *
     * @return array<string, array{old: mixed, new: mixed}> dotted key => what moved, for the keys that
     *                                                      actually changed; count() of it is the number
     *                                                      of values written
     *
     * @throws InvalidArgumentException|AuthorizationException
     */
    public function resetGroup(string $group, ?Authenticatable $actor = null): array
    {
        $fields = SettingsRegistry::fields($group);

        $actor = $actor ?? Auth::user();

        $this->assertCanEditGroup($group, $actor);

        /** @var array<string, array{old: mixed, new: mixed}> $changes */
        $changes = [];

        /** @var list<array{disk: string, path: string}> $obsolete */
        $obsolete = [];

        $readonly = $this->readonlyKeys($group);

        DB::transaction(function () use ($group, $fields, $actor, $readonly, &$changes, &$obsolete): void {
            foreach ($fields as $key => $field) {
                if (in_array((string) $key, $readonly, true)) {
                    continue;
                }

                $change = $this->writeKey(
                    $group,
                    (string) $key,
                    $field,
                    $field['default'],
                    $actor,
                    $obsolete,
                    'Setting reset to default',
                );

                if ($change !== null) {
                    $changes[$group.'.'.$key] = $change;
                }
            }
        });

        if ($changes !== []) {
            foreach ($obsolete as $file) {
                $this->deleteFileFromDisk($file['disk'], $file['path']);
            }

            $this->afterWrite(array_keys($changes), $actor);
        }

        return $changes;
    }

    /**
     * Remove the file behind one `image` / `file` setting (the `settings.file.destroy` route).
     *
     * @return bool false when there was nothing stored
     *
     * @throws InvalidArgumentException|AuthorizationException|ActionNotAllowedException
     */
    public function deleteFile(string $group, string $key, ?Authenticatable $actor = null): bool
    {
        $fields = SettingsRegistry::fields($group);
        $field = $fields[$key] ?? null;

        if ($field === null || ! SettingsRegistry::isFileType((string) $field['type'])) {
            throw new InvalidArgumentException(sprintf('[%s.%s] is not a file setting.', $group, $key));
        }

        $actor = $actor ?? Auth::user();

        $this->assertCanEditGroup($group, $actor);

        if (in_array($key, $this->readonlyKeys($group), true)) {
            throw new ActionNotAllowedException(sprintf(
                '"%s" may only be changed through the console.',
                (string) $field['label'],
            ));
        }

        /** @var list<array{disk: string, path: string}> $obsolete */
        $obsolete = [];

        // A full closure, not an arrow function: `$obsolete` is an out-parameter and an arrow
        // function would capture it by value, leaving the replaced file on disk for ever.
        $change = DB::transaction(function () use ($group, $key, $field, $actor, &$obsolete): ?array {
            return $this->writeKey($group, $key, $field, null, $actor, $obsolete, 'Setting file removed');
        });

        if ($change === null) {
            return false;
        }

        foreach ($obsolete as $file) {
            $this->deleteFileFromDisk($file['disk'], $file['path']);
        }

        $this->afterWrite([$group.'.'.$key], $actor);

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | File helpers (shared with the controller that streams private files)
    |--------------------------------------------------------------------------
    */

    /**
     * Which disk a file field lives on (D21).
     *
     * A field the public website may read is public and lands on the `public` disk; everything
     * else is private and is streamed by a controller that re-runs the permission chain. Takes a
     * field definition or a dotted `group.key`.
     *
     * @param  array<string, mixed>|string  $field
     */
    public static function diskFor(array|string $field): string
    {
        if (is_string($field)) {
            $field = SettingsRegistry::field($field) ?? [];
        }

        return ($field['public'] ?? false) === true ? self::PUBLIC_DISK : self::PRIVATE_DISK;
    }

    /**
     * The bare keys of one group that may not be written from the screen or this service: every
     * key the registry declares `readonly`, **plus** every stored row flagged `is_readonly`.
     *
     * The stored flag is honoured as well as the registry's, so a row a migration or a console
     * command locked (a superseded key, a reserved key, a counter) stays locked even when the
     * registry has not caught up — and it is only ever cleared by `SettingSeeder`'s metadata refresh
     * from the console, never by a save. `UpdateSettingsRequest` and the settings screen ask this
     * same question, so the form, the request and the service cannot disagree.
     *
     * @return list<string>
     */
    public function readonlyKeys(string $group): array
    {
        $keys = [];

        foreach (SettingsRegistry::fields($group) as $key => $field) {
            if ($field['readonly'] === true) {
                $keys[] = (string) $key;
            }
        }

        $stored = Setting::query()
            ->forGroup($group)
            ->where('is_readonly', true)
            ->pluck('key')
            ->map(static fn (mixed $key): string => (string) $key)
            ->all();

        return array_values(array_unique(array_merge($keys, $stored)));
    }

    /**
     * The stored path behind a file setting, or null when nothing is stored.
     */
    public function pathFor(string $dottedKey): ?string
    {
        $value = $this->settings->get($dottedKey);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals — writing one key
    |--------------------------------------------------------------------------
    */

    /**
     * Write one key and, when the stored value actually moves, log it.
     *
     * @param  array<string, mixed>  $field
     * @param  list<array{disk: string, path: string}>  $obsolete  files to delete after commit
     * @return array{old: mixed, new: mixed}|null null when nothing changed
     */
    private function writeKey(
        string $group,
        string $key,
        array $field,
        mixed $value,
        ?Authenticatable $actor,
        array &$obsolete,
        string $description = 'Setting updated',
    ): ?array {
        $storage = (string) $field['storage'];
        $encrypted = $field['encrypted'] === true;
        $isFile = SettingsRegistry::isFileType((string) $field['type']);

        /** @var Setting $setting */
        $setting = Setting::query()->firstOrNew(['group' => $group, 'key' => $key]);

        $existed = $setting->exists;
        $previous = $existed ? $setting->value : null;
        $previous = $previous === null ? null : (string) $previous;

        // A decimal is stored at its scale whichever path it came through (a reset writes the
        // registry default, a programmatic caller may pass an int or a float). Phase 2 review low 1:
        // a value that could only be stored by rounding it is refused here too, never rounded.
        if (SettingsRegistry::exceedsScale($field, $value)) {
            throw ValidationException::withMessages([$key => SettingsRegistry::scaleErrorMessage($field)]);
        }

        $next = $this->serialise(SettingsRegistry::normaliseDecimal($field, $value), $storage);

        $metadata = $this->metadataFor($field, $existed, $existed && $setting->isReadonly());

        $unchanged = $existed && $previous === $next;

        if ($unchanged) {
            // Still let the row converge on the registry's metadata, but never log a no-op.
            $setting->fill($metadata);

            if ($setting->isDirty()) {
                $setting->save();
            }

            return null;
        }

        if ($isFile && $previous !== null && $previous !== '' && $previous !== $next) {
            $obsolete[] = ['disk' => self::diskFor($field), 'path' => $previous];
        }

        $setting->fill($metadata);
        $setting->value = $next;
        $setting->updated_by = $actor instanceof User ? $actor->getKey() : null;
        $setting->save();

        $old = $encrypted ? ($previous === null || $previous === '' ? null : self::REDACTED) : $this->typed($previous, $storage);
        $new = $encrypted ? ($next === null || $next === '' ? null : self::REDACTED) : $this->typed($next, $storage);

        $this->audit(
            $setting,
            $description,
            [
                'key' => $group.'.'.$key,
                'group' => $group,
                'field' => $key,
                'label' => (string) $field['label'],
                'old' => ['value' => $old],
                'attributes' => ['value' => $new],
            ],
            self::MODULE,
            sprintf('%s — %s.%s', $description, $group, $key),
        );

        return ['old' => $old, 'new' => $new];
    }

    /**
     * The metadata columns a row inherits from the registry.
     *
     * Display text (`label`, `description`, `sort_order`) is written on insert only — the seeder
     * owns refreshing it, and a save should not rewrite a column it was not asked about. The
     * columns that decide how the value is *read back* (`type`, `is_encrypted`) are always
     * applied: a stale `type` would silently change what the value means.
     *
     * `is_readonly` is only ever **raised** here, never cleared: a stored lock is lifted by
     * `SettingSeeder` from the console, not as a side effect of somebody saving the group.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function metadataFor(array $field, bool $existed, bool $storedReadonly = false): array
    {
        $metadata = [
            'type' => (string) $field['storage'],
            'is_encrypted' => $field['encrypted'] === true,
            'is_public' => $field['public'] === true,
            'is_readonly' => $field['readonly'] === true || $storedReadonly,
        ];

        if (! $existed) {
            // Static lists only — mirroring a provider-backed list (timezones, currencies,
            // branches) would freeze live data into a metadata column. SettingSeeder does the
            // same, so a row created here and a row created there are identical.
            $options = $field['options'] ?? null;
            $options = is_array($options) && ! is_callable($options) && $options !== [] ? $options : null;

            $metadata['options'] = $options;
            $metadata['label'] = mb_substr((string) $field['label'], 0, 150);
            $metadata['description'] = $field['help'] === null ? null : mb_substr((string) $field['help'], 0, 255);
            $metadata['sort_order'] = (int) $field['sort'];
        }

        return $metadata;
    }

    /**
     * Move an upload onto its disk under a random hashed name.
     *
     * @param  array<string, mixed>  $field
     * @return array{disk: string, path: string}
     *
     * @throws ActionNotAllowedException
     */
    private function storeUpload(array $field, UploadedFile $file): array
    {
        $this->assertUploadIsNotExecutable($file);

        $disk = self::diskFor($field);

        $path = $file->store(self::DIRECTORY, $disk);

        if (! is_string($path) || $path === '') {
            throw new ActionNotAllowedException(sprintf(
                'The file for "%s" could not be written to the %s disk.',
                (string) $field['label'],
                $disk,
            ));
        }

        return ['disk' => $disk, 'path' => $path];
    }

    /**
     * Refuse an upload whose name carries an executable extension in any segment.
     *
     * The registry's `image` / `mimes` rules already read the file's real content, so this is the
     * second lock rather than the first: it is what stops `logo.php.png` even if a MIME sniffer is
     * ever fooled.
     *
     * @throws ActionNotAllowedException
     */
    private function assertUploadIsNotExecutable(UploadedFile $file): void
    {
        $name = strtolower($file->getClientOriginalName());

        $segments = array_map(
            static fn (string $segment): string => trim($segment),
            explode('.', $name),
        );

        $segments[] = strtolower((string) $file->guessExtension());

        foreach ($segments as $segment) {
            if ($segment !== '' && in_array($segment, self::BLOCKED_EXTENSIONS, true)) {
                throw new ActionNotAllowedException(sprintf(
                    'Files of type "%s" are never accepted as an upload.',
                    $segment,
                ));
            }
        }
    }

    private function deleteFileFromDisk(string $disk, string $path): void
    {
        $path = trim($path);

        if ($path === '' || str_starts_with($path, 'http') || str_starts_with($path, '/')) {
            return;
        }

        try {
            $storage = Storage::disk($disk);

            if ($storage->exists($path)) {
                $storage->delete($path);
            }
        } catch (Throwable $exception) {
            // The row already points somewhere else; a file that cannot be removed must not fail
            // the request, but it must not be invisible either.
            report($exception);
        }
    }

    /**
     * Flush the cached payload, bring the runtime configuration back in line, and tell listeners which
     * keys moved (`SettingsChanged` — the public website cache is one: its pages embed these values).
     *
     * @param  list<string>  $keys  dotted keys whose stored value changed
     */
    private function afterWrite(array $keys, ?Authenticatable $actor): void
    {
        $this->settings->flush();

        ConfigureFromSettings::apply();

        SettingsChanged::dispatch(array_values($keys), $actor instanceof User ? (int) $actor->getKey() : null);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals — payload handling
    |--------------------------------------------------------------------------
    */

    /**
     * Accept both `['name' => …]` and `['company.name' => …]`.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normaliseKeys(string $group, array $input): array
    {
        $normalised = [];
        $prefix = $group.'.';

        foreach ($input as $key => $value) {
            $key = (string) $key;

            if (str_starts_with($key, $prefix)) {
                $key = substr($key, strlen($prefix));
            }

            $normalised[$key] = $value;
        }

        return $normalised;
    }

    /**
     * Drop everything that is not a declared, writable, actually-submitted value.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $input
     * @param  list<string>  $readonly  readonlyKeys() for this group
     * @return array<string, mixed>
     */
    private function prunePayload(array $fields, array $input, array $readonly): array
    {
        $payload = [];

        foreach ($input as $key => $value) {
            $field = $fields[$key] ?? null;

            if ($field === null || in_array((string) $key, $readonly, true)) {
                continue;
            }

            $isFile = SettingsRegistry::isFileType((string) $field['type']);

            if ($isFile) {
                // A file only ever changes by upload; it is cleared through deleteFile() or a
                // group reset, never by an empty text value posted over the top of a path.
                if ($value instanceof UploadedFile) {
                    $payload[$key] = $value;
                }

                continue;
            }

            if ($value instanceof UploadedFile) {
                continue;
            }

            if ($this->meansUnchanged($field, $value)) {
                continue;
            }

            $payload[$key] = $value;
        }

        return $payload;
    }

    /**
     * Keep the currency symbol in step with the currency.
     *
     * The Localization screen offers a currency select, and `money()` prints the symbol. With the
     * symbol stored separately (and required), switching PKR to USD alone left every amount saying
     * "Rs" — the select changed nothing anyone could see. So when a save changes `currency` and
     * does not change `currency_symbol`, a symbol that was still the **old currency's standard
     * symbol** moves to the new currency's standard symbol. A symbol the administrator customised,
     * or typed in this same save, is never touched. The move is an ordinary change of that key:
     * validated, written and logged like any other.
     *
     * Applies to whichever group declares both `currency` and `currency_symbol`.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function followCurrencySymbol(string $group, array $fields, array $payload): array
    {
        if (! array_key_exists('currency', $fields) || ! array_key_exists('currency_symbol', $fields) || ! array_key_exists('currency', $payload)) {
            return $payload;
        }

        $scalar = static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';

        $oldCode = strtoupper($scalar($this->settings->get($group.'.currency')));
        $newCode = strtoupper($scalar($payload['currency']));

        if ($newCode === '' || $newCode === $oldCode) {
            return $payload;
        }

        $storedSymbol = $scalar($this->settings->get($group.'.currency_symbol'));

        if (array_key_exists('currency_symbol', $payload) && $scalar($payload['currency_symbol']) !== $storedSymbol) {
            return $payload;
        }

        if ($storedSymbol !== '' && $oldCode !== '' && $storedSymbol !== Money::standardSymbol($oldCode)) {
            return $payload;
        }

        $payload['currency_symbol'] = Money::standardSymbol($newCode);

        return $payload;
    }

    /**
     * Is this submission of a secret really "I did not retype it"?
     *
     * A password field renders masked and never echoes the stored value, so an empty submission —
     * or the mask coming back unchanged — must leave the stored secret alone. Clearing a secret is
     * done by resetting the group to its defaults.
     *
     * @param  array<string, mixed>  $field
     */
    private function meansUnchanged(array $field, mixed $value): bool
    {
        $isSecret = $field['encrypted'] === true || $field['type'] === SettingsRegistry::TYPE_PASSWORD;

        if (! $isSecret) {
            return false;
        }

        if ($value === null) {
            return true;
        }

        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);

        return $value === '' || $value === Setting::MASK;
    }

    /**
     * A readonly key may never be moved from here.
     *
     * Judged against the registry flag AND the stored `is_readonly` flag (readonlyKeys()).
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $input
     * @param  list<string>  $readonly  readonlyKeys() for this group
     *
     * @throws ActionNotAllowedException
     */
    private function assertNoReadonlyChange(string $group, array $fields, array $input, array $readonly): void
    {
        foreach ($input as $key => $value) {
            $field = $fields[$key] ?? null;

            if ($field === null || ! in_array((string) $key, $readonly, true)) {
                continue;
            }

            if ($value instanceof UploadedFile) {
                throw $this->readonlyRefusal($field);
            }

            $current = $this->settings->get($group.'.'.$key);
            $storage = (string) $field['storage'];

            if ($this->serialise($value, $storage) !== $this->serialise($current, $storage)) {
                throw $this->readonlyRefusal($field);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function readonlyRefusal(array $field): ActionNotAllowedException
    {
        return new ActionNotAllowedException(sprintf(
            '"%s" is read-only and can only be changed through the console.',
            (string) $field['label'],
        ));
    }

    /**
     * Validate the submitted subset against the registry's own rules.
     *
     * Rule keys are bare field keys (`'name'`, `'business_hours.*.open'`), matching the payload
     * this service takes. A rule whose field was not submitted is dropped, so saving one field of
     * a group is not blocked by a `required` rule belonging to another.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    private function validate(string $group, array $payload): void
    {
        $rules = [];

        foreach (SettingsRegistry::rulesFor($group) as $ruleKey => $ruleSet) {
            $top = explode('.', (string) $ruleKey, 2)[0];

            if (array_key_exists($top, $payload)) {
                $rules[$ruleKey] = $ruleSet;
            }
        }

        $names = [];

        $fields = SettingsRegistry::fields($group);

        foreach ($fields as $key => $field) {
            $names[$key] = (string) $field['label'];
        }

        $validator = Validator::make($payload, $rules, [], $names);

        $validator->after(function (ValidatorContract $validator) use ($group, $payload, $fields): void {
            // Phase 2 review low 1: a decimal with more decimals than its scale is refused, never
            // rounded — enforced here, not left to whether the field happens to carry `decimal:`.
            foreach ($payload as $key => $value) {
                $key = (string) $key;

                if (isset($fields[$key]) && ! $validator->errors()->has($key) && SettingsRegistry::exceedsScale($fields[$key], $value)) {
                    $validator->errors()->add($key, SettingsRegistry::scaleErrorMessage($fields[$key]));
                }
            }

            $this->assertTransportIsUsable($group, $payload, $validator);

            // The same cross-field verdict UpdateSettingsRequest reaches: a commission rate is at
            // most 100 while its type is a percentage, judged on the effective values.
            $crossField = SettingsRegistry::crossFieldErrors(
                $group,
                fn (string $key): mixed => array_key_exists($key, $payload) ? $payload[$key] : $this->settings->get($group.'.'.$key),
                array_map('strval', array_keys($payload)),
            );

            foreach ($crossField as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * The one rule the registry cannot express: SMTP needs a host.
     *
     * `SettingsRegistry::rulesFor()` is per-field by design — it sees one key at a time — so
     * `mail.host`'s own help text ("Required once the transport is SMTP") was enforced nowhere and
     * `update('mail', ['mailer' => 'smtp'])` would save a transport that cannot send, failing
     * later as a driver error rather than now as a field error.
     *
     * Judged on the **effective** value: the submitted value where the key is in this payload, the
     * stored value otherwise. A partial save is the normal shape here (`update()` leaves an absent
     * key untouched), so looking only at the payload would let `['mailer' => 'smtp']` and
     * `['host' => '']` each pass while together leaving the pair broken.
     *
     * Derived from the registry rather than hardcoded to the group name: it applies to whichever
     * group declares both `mailer` and `host`. `UpdateSettingsRequest::withValidator()` enforces
     * the same rule on the HTTP path — two checks, because a rule checked in one place is a rule
     * waiting to be bypassed by the next caller.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertTransportIsUsable(string $group, array $payload, ValidatorContract $validator): void
    {
        $fields = SettingsRegistry::fields($group);

        if (! array_key_exists('mailer', $fields) || ! array_key_exists('host', $fields)) {
            return;
        }

        $effective = function (string $key) use ($group, $payload): string {
            $value = array_key_exists($key, $payload)
                ? $payload[$key]
                : $this->settings->get($group.'.'.$key);

            return is_scalar($value) ? trim((string) $value) : '';
        };

        $mailer = $effective('mailer');

        if ($mailer !== 'smtp' || ConfigureFromSettings::isUsableMailer($mailer, $effective('host'))) {
            return;
        }

        $validator->errors()->add('host', sprintf(
            'The %s is required when the transport is SMTP.',
            mb_strtolower((string) $fields['host']['label']),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals — value shaping (mirrors SettingsRepository)
    |--------------------------------------------------------------------------
    */

    /**
     * Turn a submitted value into the string stored in `settings.value`.
     *
     * Decimals stay strings so money never meets a float (CLAUDE.md §1.4).
     *
     * **A boolean is coerced before the null check, deliberately.** A switch has two states, not
     * three: there is no such thing as a `maintenance_mode` that is neither on nor off. If a null
     * were stored, `SettingsRepository::get()` would find a row whose value is null and hand back
     * the *caller's* default instead — so `setting('appearance.show_powered_by', true)` would read
     * `true` for a switch an administrator had turned off, and every later caller would get
     * whichever default it happened to pass. The HTTP path never sends a null here
     * (`x-ui.form.toggle` emits a hidden `value="0"` before the checkbox and
     * `UpdateSettingsRequest::prepareForValidation()` coerces with `?? false`), but a programmatic
     * caller — a later phase's service, a console command, a seeder — can and does, and the read
     * side must not depend on which door the write came through. `null` therefore means `false`
     * for a boolean and only for a boolean; every other type keeps null as "no value".
     */
    private function serialise(mixed $value, string $storage): ?string
    {
        if ($storage === 'boolean') {
            return $this->boolish($value) ? '1' : '0';
        }

        if ($value === null) {
            return null;
        }

        return match ($storage) {
            'integer' => (string) (int) $value,
            'decimal' => $this->numberString($value),
            'json' => is_string($value)
                ? $value
                : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => is_array($value)
                ? (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (string) $value,
        };
    }

    /**
     * The stored string cast back by its storage type — what goes in the activity log, so a
     * boolean reads as true rather than as "1".
     */
    private function typed(?string $value, string $storage): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($storage) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'integer' => (int) $value,
            'decimal' => $value === '' ? '0' : $value,
            'json' => is_array($decoded = json_decode($value, true)) ? $decoded : null,
            default => $value,
        };
    }

    private function boolish(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    /**
     * A numeric value as a string, without ever routing it through a float's decimal expansion.
     */
    private function numberString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }

        return (string) $value;
    }
}
