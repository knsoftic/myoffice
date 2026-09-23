<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Take back a permission that was granted in error (phase-19-23 §9.4).
 *
 * **§9.4 says `messages.view_any` is "granted to nobody by default".** It is the compliance
 * reader's permission: it makes a thread readable, never writable, and every read it allows is
 * logged as an activity row. `RoleSeeder` nevertheless handed the whole `messages` module to
 * **Admin** and **Support Agent**, because both were seeded with `permissionNamesFor('messages')`
 * and that returns every ability the module declares.
 *
 * The effect was that every support agent in the installation could read every private conversation
 * in it — a student's thread with their teacher, a collaborator's with staff, a client's with their
 * project manager. Nothing announced this; the §94 matrix decides who may *talk* to whom, and a
 * blanket read makes that decision cosmetic. Phase 22's policy probe found it by asking the question
 * the contract asks, which is the whole reason for asking it.
 *
 * **The seeder cannot fix an existing install, by design.** D65 is explicit: converge additively,
 * grant what a later phase adds, never revoke what an administrator granted. That rule is right —
 * it is what stops a seeder run undoing somebody's deliberate configuration — so the correction
 * belongs here, where it is visible, dated and reversible, rather than hidden inside a seeder that
 * promises not to do this.
 *
 * **Super Admin is left alone deliberately.** `Gate::before` allows that role everything before any
 * permission is consulted, so removing the row would change nothing except the impression it gives.
 * A Super Admin can read any thread; pretending otherwise in the permissions table would be worse
 * than saying so.
 *
 * `down()` restores exactly what was taken, from the same list, so the rollback is faithful.
 */
return new class extends Migration
{
    private const PERMISSION = 'messages.view_any';

    /** The roles the seeder gave it to. Super Admin is not among them — see the class note. */
    private const ROLES = ['Admin', 'Support Agent'];

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
        $id = DB::table('roles')->where('name', $name)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Spatie caches the permission map; a revocation nothing flushed would keep working until the
     * next deploy cleared it, which is exactly the window this migration exists to close.
     */
    private function flushPermissionCache(): void
    {
        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (Throwable) {
            // No container binding in a bare migration run; the next request rebuilds it anyway.
        }
    }
};
