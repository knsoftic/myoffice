<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Support\PermissionRegistry;
use Database\Seeders\Concerns\WritesToConsole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 1 · §4 / §5 — one `permissions` row per PermissionRegistry entry.
 *
 * Every permission name in the system is generated as `{module}.{ability}` by the registry
 * (decision D4); nothing here is typed by hand.
 *
 * Idempotent and non-destructive:
 *   · matched on the natural key (`name`, `guard_name`);
 *   · meta columns (module, ability, group, label, sort_order) are refreshed on every run, so
 *     renaming a module label in the registry propagates;
 *   · permissions present in the database but missing from the registry are **reported on the
 *     console, never deleted** — dropping one would silently revoke it from every role;
 *   · the spatie permission cache is flushed at the end so the roles seeded next resolve the
 *     fresh rows.
 */
class PermissionSeeder extends Seeder
{
    use WritesToConsole;

    public function run(): void
    {
        $guard = $this->guardName();

        DB::transaction(function () use ($guard): void {
            $created = 0;
            $updated = 0;

            foreach (PermissionRegistry::permissions() as $definition) {
                $permission = Permission::query()->firstOrNew([
                    'name' => $definition['name'],
                    'guard_name' => $guard,
                ]);

                $existed = $permission->exists;

                $permission->fill([
                    'module' => $definition['module'],
                    'ability' => $definition['ability'],
                    'group' => $definition['group'],
                    'label' => $definition['label'],
                    'sort_order' => $definition['sort_order'],
                ]);

                $isDirty = $permission->isDirty();

                // A clean existing model issues no UPDATE, so a repeat run is read-mostly.
                $permission->save();

                if (! $existed) {
                    $created++;
                } elseif ($isDirty) {
                    $updated++;
                }
            }

            $this->reportUnregistered();

            $this->seedInfo(sprintf(
                'Permissions: %d declared, %d created, %d updated.',
                count(PermissionRegistry::permissions()),
                $created,
                $updated,
            ));
        });

        $this->flushPermissionCache();
    }

    /**
     * Permissions stored in the database that the registry no longer declares.
     */
    private function reportUnregistered(): void
    {
        $declared = PermissionRegistry::permissionNames();

        /** @var array<int, string> $stored */
        $stored = Permission::query()->orderBy('name')->pluck('name')->all();

        $unregistered = array_values(array_diff($stored, $declared));

        if ($unregistered === []) {
            return;
        }

        $this->seedWarning(sprintf(
            'Permissions: %d row(s) are not declared in PermissionRegistry. They were NOT deleted — '
            .'remove them deliberately once no role needs them:',
            count($unregistered),
        ));

        foreach ($unregistered as $name) {
            $this->seedWarning('  - '.$name);
        }
    }

    /**
     * Drop spatie's cached permission map so role grants resolve the rows just written.
     */
    private function flushPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function guardName(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }
}
