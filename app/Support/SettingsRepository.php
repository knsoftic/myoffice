<?php

declare(strict_types=1);

namespace App\Support;

use App\Events\SettingsChanged;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Every application setting, loaded in ONE query and cached forever.
 *
 * Bound as a singleton (see AppServiceProvider); reach it through the `setting()` /
 * `settings_repo()` helpers. A setting is addressed by its group and its key, and `get()` /
 * `has()` accept both ways of spelling that (phase-01 §3 fixes the second):
 *
 *   get('company.name')                 get('company', 'name')
 *   get('company.name', 'My Office')    get('company', 'name', 'My Office')
 *
 * In the dotted form the part before the first dot is the `settings.group` column and the
 * remainder is the `settings.key` column, so `mail.smtp.host` is group `mail`, key `smtp.host`.
 * Writes (`set()`, `setMany()`, `forget()`) take the dotted form only — there the second argument
 * is the value, so a two-part key would be ambiguous. They are low-level tools for an explicit
 * system context (`asSystem()`) and the test suite: a request writes through `SettingsService`, a
 * document counter is never written here (D62), and `forget()` deletes nothing outside the test
 * suite.
 *
 * The cached payload holds raw column values, so encrypted secrets are never cached in clear
 * text: `is_encrypted` rows are decrypted on read and encrypted on write, and values are cast
 * according to the `type` column.
 *
 * Install safety: reads degrade to defaults when the `settings` table (or the cache store) does
 * not exist yet, so boot-time `setting()` calls cannot break `artisan`. What a key *is* no longer
 * depends on whether it has been seeded either: `SettingsRegistry` declares every group and key in
 * code, so `get('mail', 'host')` is understood as group + key on a completely empty table instead
 * of handing back the literal string 'host' (phase-02 §2, carryover T14).
 */
final class SettingsRepository
{
    /** Set while setMany() runs, so a batch announces once instead of once per key. */
    private bool $deferAnnounce = false;

    /** Cache key for the whole payload. The Setting model forgets this key on save/delete. */
    public const CACHE_KEY = 'settings.all';

    /** Group used when a key arrives without a dotted prefix. */
    public const DEFAULT_GROUP = 'general';

    private const TABLE = 'settings';

    /**
     * 'group.key' => raw row.
     *
     * @var array<string, array{value: string|null, type: string, is_encrypted: bool, is_public: bool, options: string|null}>|null
     */
    private ?array $items = null;

    /** How many asSystem() callbacks are running right now; the registry/readonly guard lifts only above 0. */
    private int $systemDepth = 0;

    /**
     * Run `$callback` in the explicit system context — the one place a low-level write may touch a
     * key `SettingsRegistry` declares or a row stored `is_readonly`.
     *
     *   settings_repo()->asSystem(fn (SettingsRepository $settings) => $settings->set('mail.host', 'relay.local'));
     *
     * Phase 2 review low 4: being a console process is no longer enough. A queue worker is a console
     * process too, so "running in console" let any queued job write a declared or readonly key with
     * no audit trail; now a seeder, a migration or an artisan command has to ask for this by name,
     * and it is refused outright outside the console (an HTTP request, a `sync` job run inside one).
     * A document counter stays refused in here as everywhere else (D62).
     *
     * @template TReturn
     *
     * @param  callable(self): TReturn  $callback
     * @return TReturn
     *
     * @throws LogicException outside a console process
     */
    public function asSystem(callable $callback): mixed
    {
        if (! $this->runningInConsole()) {
            throw new LogicException('Settings may only be written in the system context from a console process.');
        }

        $this->systemDepth++;

        try {
            return $callback($this);
        } finally {
            $this->systemDepth--;
        }
    }

    /**
     * Read a setting, decrypted and cast.
     *
     * Both call shapes are first-class:
     *
     *   get('company.name')                 get('company', 'name')
     *   get('company.name', 'My Office')    get('company', 'name', 'My Office')
     *
     * Three arguments are always (group, key, default) — the contract's own signature, and the
     * shape to reach for whenever a read could be read two ways. With two arguments the pair is
     * resolved by this ladder, first match wins:
     *
     *   1. the first argument carries a dot                  -> (dotted key, default)
     *   2. the second argument is not a non-empty string      -> (dotted key, default)
     *   3. `$a.$b` is a known setting (a stored row OR a      -> (group, key)
     *      `SettingsRegistry` field)
     *   4. `$a` is itself a known single-segment key           -> (key, default)
     *   5. `$a` is a known group                              -> (group, key)
     *   6. otherwise                                          -> (key, default)
     *
     * Step 3 is what makes `get('mail', 'host')` mean the SMTP host rather than the literal string
     * 'host', now even before the row is seeded — the registry knows the key exists. Step 4 is
     * carryover **T14**: a real single-segment key always beats a group that happens to share its
     * name, so storing a `localization` key in the default group no longer makes
     * `get('localization', 'en')` unreadable. Step 5 keeps an unknown key inside a real group
     * reading as "no value" instead of echoing its own name back as data — a key that does not
     * exist must never look like a value, because that value would go on to be a currency code, a
     * mail host or a rate.
     *
     * The one residual ambiguity is a dotless key whose name collides with a group name and which
     * is not stored: `get('localization', 'en')` reads as group + key and returns null. Spell the
     * group out — `get('general.localization', 'en')` — and the reading is never in doubt.
     *
     * @param  mixed  $keyOrDefault  the key, when the first argument is a group; the default
     *                               otherwise
     */
    public function get(string $key, mixed $keyOrDefault = null, mixed $default = null): mixed
    {
        [$key, $default] = $this->resolveReadArguments($key, $keyOrDefault, $default, func_num_args());

        $key = $this->normaliseKey($key);
        $row = $this->items()[$key] ?? null;

        if ($row === null) {
            return $default;
        }

        $value = $row['value'];

        if ($row['is_encrypted'] && $value !== null && $value !== '') {
            $value = $this->decrypt($value);
        }

        if ($value === null) {
            // A stored boolean row whose value is null reads as **false**, never as the caller's
            // default. A switch has two states: a row that exists and holds nothing is "off", and
            // handing back `$default` instead would make the answer depend on what each caller
            // happened to pass — so `setting('appearance.show_powered_by', true)` would report a
            // switch an administrator turned off as still on. `SettingsService::serialise()`
            // makes sure this row cannot be written null in the first place; this is the read-side
            // half of the same rule, for a row an older release or a raw SQL edit left null.
            return $row['type'] === 'boolean' ? false : $default;
        }

        return $this->cast($value, $row['type']);
    }

    /**
     * Is the setting present (even when its value is empty)?
     *
     * Takes the same two shapes as get(): `has('company.name')` or `has('company', 'name')`.
     */
    public function has(string $key, ?string $name = null): bool
    {
        if ($name !== null) {
            $key = $key.'.'.$name;
        }

        return array_key_exists($this->normaliseKey($key), $this->items());
    }

    /**
     * Create or update a setting and flush the cache — a **low-level** write for the console and
     * the test suite, never for a request.
     *
     * `SettingsService` is the only write path an application request may use (phase-02 §3): it
     * validates, authorizes, audits, stamps `updated_by` and honours `readonly` (D62). This method
     * does none of that, so it refuses (LogicException):
     *
     *   · a document counter (`*_next_number`) in EVERY context — only DocumentNumberService
     *     advances a counter, under a row lock (D62, D27);
     *   · outside asSystem() or the test suite: any key `SettingsRegistry` declares, and any stored
     *     row flagged `is_readonly` (a superseded or reserved key). A console process that did not
     *     ask for asSystem() — a queued job included — is refused like a request.
     *
     * Meta is applied on insert and, where supplied, on update:
     * `type`, `options`, `is_encrypted`, `is_public`, `label`, `description`, `sort_order`.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws LogicException
     */
    public function set(string $key, mixed $value, array $meta = []): void
    {
        [$group, $name] = $this->split($key);

        $this->assertLowLevelWriteAllowed($group, $name);

        $existing = $this->items()[$group.'.'.$name] ?? null;

        $type = (string) ($meta['type'] ?? $existing['type'] ?? $this->inferType($value));
        $encrypted = (bool) ($meta['is_encrypted'] ?? $existing['is_encrypted'] ?? false);

        $serialised = $this->serialise($value, $type);

        if ($encrypted && $serialised !== null && $serialised !== '') {
            $serialised = Crypt::encryptString($serialised);
        }

        $now = Carbon::now();

        $payload = [
            'value' => $serialised,
            'type' => $type,
            'is_encrypted' => $encrypted,
            'updated_at' => $now,
        ];

        if (array_key_exists('options', $meta)) {
            $payload['options'] = is_string($meta['options']) || $meta['options'] === null
                ? $meta['options']
                : json_encode($meta['options'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        foreach (['is_public' => 'bool', 'label' => 'string', 'description' => 'string', 'sort_order' => 'int'] as $column => $shape) {
            if (! array_key_exists($column, $meta)) {
                continue;
            }

            $payload[$column] = match ($shape) {
                'bool' => (bool) $meta[$column],
                'int' => (int) $meta[$column],
                default => $meta[$column] === null ? null : (string) $meta[$column],
            };
        }

        $query = DB::table(self::TABLE)->where('group', $group)->where('key', $name);

        if ($query->clone()->exists()) {
            $query->update($payload);
        } else {
            DB::table(self::TABLE)->insert(array_merge([
                'group' => $group,
                'key' => $name,
                'is_public' => false,
                'sort_order' => 0,
                'created_at' => $now,
            ], $payload));
        }

        $this->flush();
        $this->announce([$group.'.'.$name]);
    }

    /**
     * Write several settings at once. Same refusals as set(), checked for every key BEFORE any of
     * them is written, so a refused key never leaves the others half-applied.
     *
     * @param  array<string, mixed>  $values  dotted key => value
     * @param  array<string, array<string, mixed>>  $meta  dotted key => meta
     *
     * @throws LogicException
     */
    public function setMany(array $values, array $meta = []): void
    {
        foreach (array_keys($values) as $key) {
            [$group, $name] = $this->split((string) $key);

            $this->assertLowLevelWriteAllowed($group, $name);
        }

        $this->deferAnnounce = true;

        try {
            foreach ($values as $key => $value) {
                $this->set((string) $key, $value, $meta[$key] ?? []);
            }
        } finally {
            $this->deferAnnounce = false;
        }

        $this->announce(array_map(
            fn (string $key): string => implode('.', $this->split($key)),
            array_map('strval', array_keys($values)),
        ));
    }

    /**
     * Every setting, cast and decrypted.
     *
     * Without a group: keyed by the full dotted key (`company.name`).
     * With a group: keyed relative to it (`name`), like `config('app')`.
     *
     * @return array<string, mixed>
     */
    public function all(?string $group = null): array
    {
        $values = [];

        foreach (array_keys($this->items()) as $key) {
            if ($group !== null) {
                $prefix = $group.'.';

                if (! str_starts_with($key, $prefix)) {
                    continue;
                }

                $values[substr($key, strlen($prefix))] = $this->get($key);

                continue;
            }

            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * Only the settings flagged `is_public` — safe to expose to the public website.
     *
     * @return array<string, mixed>
     */
    public function allPublic(?string $group = null): array
    {
        $values = [];

        foreach ($this->items() as $key => $row) {
            if (! $row['is_public']) {
                continue;
            }

            if ($group !== null) {
                $prefix = $group.'.';

                if (! str_starts_with($key, $prefix)) {
                    continue;
                }

                $values[substr($key, strlen($prefix))] = $this->get($key);

                continue;
            }

            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * Does any setting live in this group?
     */
    public function hasGroup(string $group): bool
    {
        $prefix = trim($group).'.';

        foreach (array_keys($this->items()) as $key) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The groups that exist.
     *
     * @return array<int, string>
     */
    public function groups(): array
    {
        $groups = [];

        foreach (array_keys($this->items()) as $key) {
            $group = explode('.', $key, 2)[0];

            if (! in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * Delete a throwaway fixture row — in the test suite only, and only for a key the registry
     * does not declare and that is not a document counter.
     *
     * No settings row is ever deleted by application code: a value the business entered outlives
     * the code that read it, and a deleted counter would read its default of 1 again (D62). Outside
     * the test suite this refuses, in every context, console included.
     *
     * @throws LogicException
     */
    public function forget(string $key): void
    {
        [$group, $name] = $this->split($key);
        $dotted = $group.'.'.$name;

        if (! $this->runningUnitTests() || SettingsRegistry::has($dotted) || $this->isDocumentCounter($name)) {
            throw new LogicException(sprintf(
                'Setting [%s] may not be deleted: settings rows are never removed by application code.',
                $dotted,
            ));
        }

        DB::table(self::TABLE)->where('group', $group)->where('key', $name)->delete();

        $this->flush();
        $this->announce([$dotted]);
    }

    /**
     * Say which keys moved, the way `SettingsService::afterWrite()` does for the settings screen.
     *
     * These three writers are the console-and-tests door into the table (`assertLowLevelWriteAllowed()`
     * keeps everything else out), and they used to close it silently: a public key written here — say
     * `seo.robots_indexable` from a deploy script — flushed the settings cache and left Phase 3's cached
     * pages serving the old value for up to `website.cache_ttl_minutes`. `flush()` stays what its name
     * says: a cache clear, announcing nothing, because clearing a cache is not a change.
     *
     * @param  list<string>  $keys  dotted keys
     */
    private function announce(array $keys): void
    {
        if ($this->deferAnnounce || $keys === []) {
            return;
        }

        SettingsChanged::dispatch(array_values($keys), null);
    }

    /**
     * Drop the cached payload; the next read reloads it in one query.
     */
    public function flush(): void
    {
        $this->items = null;

        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // store unavailable — nothing to forget
        }
    }

    /**
     * The raw rows, keyed 'group.key'. One query, then cached forever.
     *
     * Three steps, each failing on its own terms:
     *
     *   1. the cache is read — a store that cannot be read is simply a miss;
     *   2. on a miss the table is loaded — a table that does not exist yet (fresh install) reads as
     *      "no settings stored" and is NOT memoised, because the migration that creates it may run
     *      later in this same process;
     *   3. what was loaded is memoised in-process FIRST and only then written to the cache, so a
     *      store that refuses the payload (oversized, full, unavailable) is reported and costs one
     *      query per process — instead of every setting silently reading its default.
     *
     * @return array<string, array{value: string|null, type: string, is_encrypted: bool, is_public: bool, options: string|null}>
     */
    private function items(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        try {
            $cached = Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            $cached = null; // store unavailable — read the table instead
        }

        if (is_array($cached)) {
            /** @var array<string, array{value: string|null, type: string, is_encrypted: bool, is_public: bool, options: string|null}> $cached */
            return $this->items = $cached;
        }

        try {
            $items = $this->load();
        } catch (Throwable) {
            // Table missing (fresh install): behave like "no settings stored".
            return [];
        }

        // Phase 2 review low 5: memoise the loaded payload even when the cache write below fails.
        $this->items = $items;

        try {
            Cache::forever(self::CACHE_KEY, $items);
        } catch (Throwable $exception) {
            $this->reportQuietly($exception);
        }

        return $items;
    }

    /**
     * report() without letting a half-booted container (an install, a bare unit test) turn a
     * cache failure into a crash.
     */
    private function reportQuietly(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // nothing left to tell
        }
    }

    /**
     * The single query.
     *
     * @return array<string, array{value: string|null, type: string, is_encrypted: bool, is_public: bool, options: string|null}>
     */
    private function load(): array
    {
        $items = [];

        $rows = DB::table(self::TABLE)
            ->select('group', 'key', 'value', 'type', 'is_encrypted', 'is_public', 'options')
            ->get();

        foreach ($rows as $row) {
            $items[$row->group.'.'.$row->key] = [
                'value' => $row->value === null ? null : (string) $row->value,
                'type' => (string) ($row->type ?: 'string'),
                'is_encrypted' => (bool) $row->is_encrypted,
                'is_public' => (bool) $row->is_public,
                'options' => $row->options === null ? null : (string) $row->options,
            ];
        }

        return $items;
    }

    /**
     * Work out which of get()'s two call shapes was used, and return [dotted key, default].
     *
     * @return array{0: string, 1: mixed}
     */
    private function resolveReadArguments(string $first, mixed $second, mixed $third, int $count): array
    {
        // get($group, $key, $default) — unambiguous, the contract's own signature.
        if ($count >= 3) {
            return [$first.'.'.(is_string($second) ? $second : (string) $second), $third];
        }

        if ($count === 2 && $this->readsAsGroupAndKey($first, $second)) {
            /** @var string $second */
            return [$first.'.'.$second, null];
        }

        // get($dottedKey) / get($dottedKey, $default).
        return [$first, $count >= 2 ? $second : null];
    }

    /**
     * Is `get($a, $b)` asking for group `$a`, key `$b` rather than for key `$a` with `$b` as the
     * default? The ladder is documented on get(); this is steps 1 to 5 of it.
     */
    private function readsAsGroupAndKey(string $first, mixed $second): bool
    {
        if (! is_string($second) || $second === '') {
            return false;
        }

        $first = trim($first);

        // A group name never carries a dot; a dotted key always does.
        if ($first === '' || str_contains($first, '.')) {
            return false;
        }

        // The strongest signal there is: the pair names a setting that exists.
        if ($this->knowsKey($first.'.'.$second)) {
            return true;
        }

        // T14: a real single-segment key beats a group that happens to share its name, so the
        // second argument is that key's default rather than a key of the group.
        if ($this->knowsKey(self::DEFAULT_GROUP.'.'.$first)) {
            return false;
        }

        return $this->knowsGroup($first);
    }

    /**
     * Is this dotted key a setting the system knows about — stored in the table, or declared in
     * `SettingsRegistry` and simply not seeded yet?
     */
    private function knowsKey(string $key): bool
    {
        $key = $this->normaliseKey($key);

        if (array_key_exists($key, $this->items())) {
            return true;
        }

        return SettingsRegistry::has($key);
    }

    /**
     * Is this a group the system knows about — stored, or declared in the registry?
     */
    private function knowsGroup(string $group): bool
    {
        return $this->hasGroup($group) || SettingsRegistry::hasGroup($group);
    }

    /**
     * The guard in front of set() / setMany() (see set() for the rules).
     *
     * @throws LogicException
     */
    private function assertLowLevelWriteAllowed(string $group, string $name): void
    {
        $dotted = $group.'.'.$name;

        if ($this->isDocumentCounter($name)) {
            throw new LogicException(sprintf(
                'Setting [%s] is a document counter: only DocumentNumberService may advance it, under a row lock (D62).',
                $dotted,
            ));
        }

        // Phase 2 review low 4: only the explicit system context (or the test suite) is exempt — not
        // every console process, so a queue worker cannot write a declared or readonly key unaudited.
        if ($this->runningUnitTests() || ($this->systemDepth > 0 && $this->runningInConsole())) {
            return;
        }

        if (SettingsRegistry::has($dotted)) {
            throw new LogicException(sprintf(
                'Setting [%s] is declared by SettingsRegistry: write it through SettingsService, which validates, authorizes and audits the change.',
                $dotted,
            ));
        }

        if ($this->isStoredReadonly($group, $name)) {
            throw new LogicException(sprintf(
                'Setting [%s] is stored read-only and may only be changed from the console, inside SettingsRepository::asSystem().',
                $dotted,
            ));
        }
    }

    /**
     * D62: every `*_next_number` key is a document counter, declared or not.
     */
    private function isDocumentCounter(string $name): bool
    {
        return str_ends_with($name, '_next_number');
    }

    /**
     * Does a stored row carry `is_readonly = 1`? A missing row, column or table reads as no.
     */
    private function isStoredReadonly(string $group, string $name): bool
    {
        try {
            return (bool) DB::table(self::TABLE)
                ->where('group', $group)
                ->where('key', $name)
                ->value('is_readonly');
        } catch (Throwable) {
            return false;
        }
    }

    private function runningInConsole(): bool
    {
        try {
            return app()->runningInConsole();
        } catch (Throwable) {
            return false;
        }
    }

    private function runningUnitTests(): bool
    {
        try {
            return app()->runningUnitTests();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 'company.name' => ['company', 'name']; 'mail.smtp.host' => ['mail', 'smtp.host'].
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $key): array
    {
        $key = trim($key);

        if (! str_contains($key, '.')) {
            return [self::DEFAULT_GROUP, $key];
        }

        $parts = explode('.', $key, 2);

        return [$parts[0], $parts[1]];
    }

    private function normaliseKey(string $key): string
    {
        [$group, $name] = $this->split($key);

        return $group.'.'.$name;
    }

    /**
     * Cast a stored string by the `type` column. Decimals stay strings so money never meets a float.
     */
    private function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'integer' => (int) $value,
            'decimal' => $value === '' ? '0' : $value,
            'json' => $this->decodeJson($value),
            default => $value,
        };
    }

    /**
     * Serialise a PHP value for the `value` column.
     *
     * A boolean is decided before the null check, for the reason spelled out on `get()`: a switch
     * is on or off, so a null written onto a boolean row stores '0' rather than a third state that
     * would read back as whatever default the next caller passes.
     */
    private function serialise(mixed $value, string $type): ?string
    {
        if ($type === 'boolean') {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer' => (string) (int) $value,
            'json' => is_string($value)
                ? $value
                : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => is_array($value)
                ? (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (string) $value,
        };
    }

    private function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_array($value) => 'json',
            default => 'string',
        };
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function decodeJson(string $value): ?array
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Decrypt tolerantly: the value may have been written with encryptString() or encrypt().
     */
    private function decrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            try {
                $decrypted = Crypt::decrypt($value);

                return is_scalar($decrypted) ? (string) $decrypted : (string) json_encode($decrypted);
            } catch (Throwable) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }
    }
}
