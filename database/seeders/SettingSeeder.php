<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\SettingsRegistry;
use Database\Seeders\Concerns\WritesToConsole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The settings catalogue, seeded from `App\Support\SettingsRegistry` (phase-02 §2).
 *
 * Definitions live in code and values live in the table — the same relationship
 * `PermissionRegistry` has with the `permissions` table. This seeder is the one place those two
 * halves meet, and it is built around a single promise:
 *
 *   **A value an administrator has changed is never overwritten.**
 *
 * How that promise is kept:
 *
 *   · rows are matched on the natural key (`group`, `key`);
 *   · `value` is written **only on insert**, from the registry default. Re-running the seeder can
 *     never reset a company name, an SMTP host, a commission rate or an uploaded logo path;
 *   · the metadata columns — `type`, `options`, `label`, `description`, `sort_order`,
 *     `is_encrypted`, `is_public`, `is_readonly` — are refreshed every run, so the settings screen
 *     always renders the current catalogue even for a row seeded by an older release;
 *   · nothing is ever deleted. A key the registry no longer declares (a Phase 1 row whose owning
 *     phase has not redeclared it yet: `localization.currency_decimals`,
 *     `collaborator.wallet_hold_days`, …) keeps its value and is reported in the console as
 *     "unmaintained", exactly as `PermissionSeeder` reports a permission that left the registry.
 *
 * **Every registry key is declared here and nothing else is.** `run()` walks
 * `SettingsRegistry::all()`, so the catalogue in the table is the catalogue in code — there is no
 * hand-written key list to drift. Two things follow from that, and both are enforced rather than
 * assumed:
 *
 *   · a **superseded** row is never handed back its editability.
 *     `2026_09_12_060400_supersede_relocated_setting_keys` marks every relocated Phase 1 key
 *     `is_readonly = true` and renames its label
 *     "Deprecated - use <canonical key>"; those keys are absent from the registry, so this seeder
 *     never touches them and the marker survives every re-seed. Should a future phase re-declare
 *     one of them, refreshing metadata would silently clear `is_readonly` and restore the label —
 *     resurrecting a key whose concept now lives somewhere else, and recreating the split-truth
 *     defect. `assertNotResurrectingDeprecatedKey()` refuses that by name instead;
 *   · the console reports superseded rows separately from genuinely unmaintained ones, so the
 *     output says which stored keys have a home elsewhere and which are simply waiting for their
 *     owning phase.
 *
 * Idempotent: `php artisan db:seed --class=SettingSeeder` can run on a live database as often as
 * you like. The whole pass is one transaction, and the cached settings payload is flushed at the
 * end so the next read matches the table.
 *
 * Secrets: `is_encrypted` comes from the registry's `encrypted` flag, and the `Setting` model
 * encrypts and decrypts that column transparently — which is why model events are deliberately not
 * suppressed here (see `DatabaseSeeder`).
 */
class SettingSeeder extends Seeder
{
    use WritesToConsole;

    /** `settings.label` is varchar(150). */
    private const LABEL_LENGTH = 150;

    /** `settings.description` is varchar(255); the registry's help text can be longer. */
    private const DESCRIPTION_LENGTH = 255;

    /**
     * The label prefix `2026_09_12_060400_supersede_relocated_setting_keys` writes onto a row
     * whose concept moved to another key. Recognised here so a superseded row can never be
     * re-seeded as an editable field.
     */
    private const DEPRECATED_PREFIX = 'Deprecated - use ';

    /**
     * The label prefix `2026_09_12_060500_supersede_or_reserve_remaining_legacy_setting_keys`
     * writes onto a Phase 1 row whose owning phase has not declared it yet. Unlike a superseded
     * row, a reserved row may be declared: the metadata refresh then replaces the marker.
     */
    private const RESERVED_PREFIX = 'Reserved for ';

    public function run(): void
    {
        $catalogue = SettingsRegistry::all();

        $hasReadonly = Schema::hasColumn('settings', 'is_readonly');

        $created = 0;
        $updated = 0;
        $total = 0;

        DB::transaction(function () use ($catalogue, $hasReadonly, &$created, &$updated, &$total): void {
            foreach ($catalogue as $group => $fields) {
                foreach ($fields as $key => $field) {
                    $total++;

                    /** @var Setting $setting */
                    $setting = Setting::query()->firstOrNew([
                        'group' => (string) $group,
                        'key' => (string) $key,
                    ]);

                    $existed = $setting->exists;

                    if ($existed) {
                        $this->assertNotResurrectingDeprecatedKey($setting);
                    }

                    $setting->fill($this->metadata($field, $hasReadonly));

                    if (! $existed) {
                        // Only a brand new row is given the registry default.
                        $setting->value = $this->serialise($field['default'], (string) $field['storage']);
                    }

                    $isDirty = $setting->isDirty();

                    if (! $existed || $isDirty) {
                        $setting->save();
                    }

                    if (! $existed) {
                        $created++;
                    } elseif ($isDirty) {
                        $updated++;
                    }
                }
            }
        });

        // The Setting model flushes on save; flush again so a no-op run still leaves the cached
        // payload consistent with the table.
        settings_repo()->flush();

        $this->seedInfo(sprintf(
            'Settings: %d keys declared in %d groups — %d created, %d metadata refreshed, %d already current (no value overwritten).',
            $total,
            count($catalogue),
            $created,
            $updated,
            $total - $created - $updated,
        ));

        $this->reportUnmaintained();
    }

    /**
     * The metadata columns one registry field writes, every run.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function metadata(array $field, bool $hasReadonly): array
    {
        $metadata = [
            'type' => (string) $field['storage'],
            'options' => $this->options($field),
            'is_encrypted' => $field['encrypted'] === true,
            'is_public' => $field['public'] === true,
            'label' => mb_substr((string) $field['label'], 0, self::LABEL_LENGTH),
            'description' => $field['help'] === null
                ? null
                : mb_substr((string) $field['help'], 0, self::DESCRIPTION_LENGTH),
            'sort_order' => (int) $field['sort'],
        ];

        if ($hasReadonly) {
            $metadata['is_readonly'] = $field['readonly'] === true;
        }

        return $metadata;
    }

    /**
     * Static option lists are mirrored into the column; a provider-backed list (timezones,
     * currencies, branches) is not.
     *
     * Mirroring a live list would make the seeder's output depend on the `branches` table and
     * report a metadata change on every run. The screen resolves options through the registry
     * anyway — the column is a convenience for an admin reading the table directly.
     *
     * @param  array<string, mixed>  $field
     * @return array<array-key, mixed>|null
     */
    private function options(array $field): ?array
    {
        $options = $field['options'] ?? null;

        if (! is_array($options) || is_callable($options)) {
            return null;
        }

        return $options === [] ? null : $options;
    }

    /**
     * A key may be canonical or superseded, never both.
     *
     * A row carrying the deprecation marker has had its concept moved to another key, and its
     * value has already been carried forward. Refreshing the registry metadata over the top of it
     * would set `is_readonly` back to false and overwrite the "Deprecated - use …" label, handing
     * the settings screen a field that writes a row nothing reads — the exact defect the
     * supersede migration closed. Refuse it, loudly, naming the way out.
     *
     * @throws RuntimeException
     */
    private function assertNotResurrectingDeprecatedKey(Setting $setting): void
    {
        if (! str_starts_with((string) $setting->label, self::DEPRECATED_PREFIX)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Setting [%s.%s] is stored as superseded (%s) but SettingsRegistry declares it again. '.
            'A key has one home: either drop the declaration, or — if the key really is canonical '.
            'again — clear the deprecation marker on that row first (label and is_readonly).',
            (string) $setting->group,
            (string) $setting->key,
            (string) $setting->label,
        ));
    }

    /**
     * Name the stored keys the registry does not declare. Reported, never deleted — a value the
     * business entered outlives the code that used to read it.
     *
     * Two lists, because they mean different things to whoever reads the console: a **superseded**
     * key has a home somewhere else and is kept only as history, while an **unmaintained** key is
     * still waiting for the phase that owns it to declare it.
     */
    private function reportUnmaintained(): void
    {
        $declared = SettingsRegistry::keys();

        $rows = DB::table('settings')
            ->select('group', 'key', 'label')
            ->orderBy('group')
            ->orderBy('key')
            ->get();

        $superseded = [];
        $reserved = [];
        $unmaintained = [];

        foreach ($rows as $row) {
            $key = $row->group.'.'.$row->key;

            if (in_array($key, $declared, true)) {
                continue;
            }

            if (str_starts_with((string) $row->label, self::DEPRECATED_PREFIX)) {
                $superseded[] = $key;

                continue;
            }

            if (str_starts_with((string) $row->label, self::RESERVED_PREFIX)) {
                $reserved[] = $key;

                continue;
            }

            $unmaintained[] = $key;
        }

        if ($superseded !== []) {
            $this->seedComment(sprintf(
                'Settings: %d stored key(s) are superseded — kept as history, readonly, not editable: %s',
                count($superseded),
                $this->keyList($superseded),
            ));
        }

        if ($reserved !== []) {
            $this->seedComment(sprintf(
                'Settings: %d stored key(s) are reserved for a later phase — readonly until that phase declares them: %s',
                count($reserved),
                $this->keyList($reserved),
            ));
        }

        if ($unmaintained !== []) {
            $this->seedComment(sprintf(
                'Settings: %d stored key(s) are not declared in SettingsRegistry and were left untouched: %s',
                count($unmaintained),
                $this->keyList($unmaintained),
            ));
        }
    }

    /**
     * At most fifteen keys, then a count.
     *
     * @param  list<string>  $keys
     */
    private function keyList(array $keys): string
    {
        $shown = array_slice($keys, 0, 15);

        return implode(', ', $shown)
            .(count($keys) > count($shown) ? sprintf(' … and %d more', count($keys) - count($shown)) : '');
    }

    /**
     * Turn a registry default into the string stored in `settings.value`.
     *
     * Decimals stay strings so money never meets a float (CLAUDE.md §1.4).
     */
    private function serialise(mixed $value, string $storage): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($storage) {
            'boolean' => $value ? '1' : '0',
            'integer' => (string) (int) $value,
            'json' => is_string($value)
                ? $value
                : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => is_array($value)
                ? (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (string) $value,
        };
    }
}
