<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ModuleGroup;
use App\Models\Module;
use App\Support\Modules;
use App\Support\PermissionRegistry;
use Database\Seeders\Concerns\WritesToConsole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 · §1.3 / §5 — one `modules` row per PermissionRegistry module definition.
 *
 * The registry is the source of truth for slug, name, group, icon, is_core and sort order;
 * this seeder projects it into the table that the module gate reads.
 *
 * Idempotent and non-destructive:
 *   · matched on the natural key `modules.slug`;
 *   · `is_enabled` is written **only when the row is created** — re-running the seeder must
 *     never switch a module an administrator deliberately disabled back on. The one exception is
 *     a module the registry now declares `is_core`: core means "can never be disabled" (§1.3), so
 *     a stored `is_enabled = false` on such a row is not a choice to preserve but a state the
 *     registry no longer permits, and it is converged back to true. That is what repairs a row
 *     seeded before a module became core — the four `*_portal` panels — and it touches no other
 *     module's switch;
 *   · `description` and the `settings` json are left alone for the same reason;
 *   · modules that exist in the table but no longer in the registry are reported, not deleted.
 */
class ModuleSeeder extends Seeder
{
    use WritesToConsole;

    public function run(): void
    {
        DB::transaction(function (): void {
            $created = 0;
            $updated = 0;

            /** @var array<int, string> $reEnabled */
            $reEnabled = [];

            foreach (PermissionRegistry::modules() as $slug => $definition) {
                $module = Module::query()->firstOrNew(['slug' => $slug]);

                $existed = $module->exists;
                $isCore = (bool) $definition['is_core'];

                $module->fill([
                    'name' => (string) $definition['name'],
                    'group' => $definition['group'] instanceof ModuleGroup
                        ? $definition['group']
                        : ModuleGroup::System,
                    'icon' => (string) $definition['icon'],
                    'is_core' => $isCore,
                    'sort_order' => (int) $definition['sort'],
                ]);

                if (! $existed) {
                    // New modules arrive enabled; core modules can never be turned off anyway.
                    $module->is_enabled = true;
                } elseif ($isCore && ! (bool) $module->is_enabled) {
                    // A stored "off" on a module that is core cannot be honoured (§1.3), so the
                    // row is converged instead of leaving the switch contradicting the registry.
                    $module->is_enabled = true;
                    $reEnabled[] = $slug;
                }

                $isDirty = $module->isDirty();

                $module->save();

                if (! $existed) {
                    $created++;
                } elseif ($isDirty) {
                    $updated++;
                }
            }

            $this->reportUnregistered();

            $this->seedInfo(sprintf(
                'Modules: %d registered, %d created, %d updated.',
                count(PermissionRegistry::modules()),
                $created,
                $updated,
            ));

            if ($reEnabled !== []) {
                $this->seedWarning(sprintf(
                    'Modules: %d core module(s) were stored as disabled and have been re-enabled (core modules can never be off): %s.',
                    count($reEnabled),
                    implode(', ', $reEnabled),
                ));
            }
        });

        // The Module model flushes this on save; flush again so a run that changed nothing
        // still leaves the gate map warm and consistent.
        Modules::flushCache();
    }

    /**
     * Modules stored in the database that the registry no longer declares.
     *
     * Reported only: deleting one would drop an administrator's enable/disable choice and
     * orphan nothing useful (phase-01 §5 — never delete, report).
     */
    private function reportUnregistered(): void
    {
        $registered = PermissionRegistry::moduleSlugs();

        /** @var array<int, string> $stored */
        $stored = Module::query()->orderBy('slug')->pluck('slug')->all();

        $unregistered = array_values(array_diff($stored, $registered));

        if ($unregistered === []) {
            return;
        }

        $this->seedWarning(sprintf(
            'Modules: %d row(s) in the database are not declared in PermissionRegistry and were left untouched:',
            count($unregistered),
        ));

        foreach ($unregistered as $slug) {
            $this->seedWarning('  - '.$slug);
        }
    }
}
