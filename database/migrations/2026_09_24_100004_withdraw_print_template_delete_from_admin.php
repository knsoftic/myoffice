<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Take back a permission that was granted in error (phase-19-23 §4.3).
 *
 * **§4.3 says Admin is "everything in §4.1 and §4.2 **except** `print_templates.delete`".** The
 * seeder's `everythingExcept()` list did not carry that exclusion, so every Admin role in every
 * installation was handed it. `RoleSeeder` has now been corrected, which fixes a fresh install and,
 * by D65's additive rule, cannot fix an existing one — a seeder that revoked would be a seeder that
 * could undo an administrator's deliberate configuration on the next deployment. So the correction
 * belongs here: visible, dated, reversible, and run exactly once.
 *
 * **Why this one act is withheld while create, edit and deactivate are not.** A print template is
 * the layout every certificate and every ID card is rendered through. §4.1 already treats it as
 * unusually powerful — its `body_html` made it a separately grantable, separately audited module of
 * its own. Deleting one does not merely remove a design: every certificate already issued against
 * it loses the only record of how it looked, and the public verification page, which re-renders
 * from the template, has nothing left to re-render from. That is not an administrative action with
 * an undo; it is the quiet destruction of evidence about documents that have already left the
 * building. Deactivating a template achieves everything a day-to-day administrator actually wants —
 * nothing new is issued against it — and keeps the history readable.
 *
 * **Super Admin is left alone deliberately**, for the same reason the Phase 22 correction left it
 * alone: `Gate::before` allows that role everything before any permission is consulted, so removing
 * the row would change no behaviour and only create the impression that it had.
 *
 * `down()` restores exactly what was taken, so the rollback is faithful rather than approximate.
 */
return new class extends Migration
{
    private const PERMISSION = 'print_templates.delete';

    /** The role the seeder gave it to. Super Admin is not among them — see the class note. */
    private const ROLES = ['Admin'];

    public function up(): void
    {
        $permissionId = $this->permissionId();

        if ($permissionId === null) {
            return;
        }

        foreach (self::ROLES as $name) {
            $roleId = $this->roleId($name);

            if ($roleId === null) {
                continue;
            }

            DB::table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->where('role_id', $roleId)
                ->delete();
        }

        $this->flushPermissionCache();
    }

    public function down(): void
    {
        $permissionId = $this->permissionId();

        if ($permissionId === null) {
            return;
        }

        foreach (self::ROLES as $name) {
            $roleId = $this->roleId($name);

            if ($roleId === null) {
                continue;
            }

            $held = DB::table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->where('role_id', $roleId)
                ->exists();

            if (! $held) {
                DB::table('role_has_permissions')->insert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        $this->flushPermissionCache();
    }

    private function permissionId(): ?int
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_has_permissions')) {
            return null;
        }

        $id = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function roleId(string $name): ?int
    {
        if (! Schema::hasTable('roles')) {
            return null;
        }

        $id = DB::table('roles')->where('name', $name)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Spatie caches the whole permission map; a row removed behind its back stays allowed until
     * something else clears it. Guarded because the container has no registrar during a
     * `migrate` run that bootstraps a bare application.
     */
    private function flushPermissionCache(): void
    {
        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (Throwable) {
            // Nothing to flush is not a failure.
        }
    }
};
