<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Enums\PanelType;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Core\Concerns\WritesAuditTrail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Role writes: the row itself plus its permission matrix.
 *
 * The matrix is synchronised with spatie's `syncPermissions()` inside a transaction and the
 * added / removed permission names are recorded as an audit entry — the pivot write fires no
 * model event, so without this the most security-relevant change in the system would leave no
 * trace (phase-01 §10).
 *
 * Invariants enforced here rather than in the policy, because `Gate::before` lets a Super Admin
 * past every policy: a protected (`is_system`) role is never renamed or deleted, and a role that
 * still has members is never deleted out from under them.
 */
final class RoleService
{
    use WritesAuditTrail;

    private const MODULE = 'roles';

    /**
     * @param  array<string, mixed>  $data  validated payload from StoreRoleRequest
     * @param  array<int, string>  $permissions  permission names
     */
    public function create(array $data, array $permissions = []): Role
    {
        return DB::transaction(function () use ($data, $permissions): Role {
            /** @var Role $role */
            $role = Role::query()->create([
                'name' => $data['name'],
                'guard_name' => $data['guard_name'] ?? config('auth.defaults.guard', 'web'),
                'label' => $data['label'] ?? null,
                'description' => $data['description'] ?? null,
                'panel' => $data['panel'],
                'level' => $data['level'],
                // Roles created through the UI are never protected; only seeded roles are.
                'is_system' => false,
                'is_default' => (bool) ($data['is_default'] ?? false),
            ]);

            $this->syncMatrix($role, $permissions, $data['reason'] ?? null);

            return $role;
        });
    }

    /**
     * Update the row and, when `$permissions` is given, the whole matrix.
     *
     * @param  array<string, mixed>  $data  validated payload from UpdateRoleRequest
     * @param  array<int, string>|null  $permissions  null leaves the matrix untouched
     */
    public function update(Role $role, array $data, ?array $permissions = null): Role
    {
        if ($role->isProtected()) {
            $this->assertProtectedFieldsUnchanged($role, $data);
        }

        return DB::transaction(function () use ($role, $data, $permissions): Role {
            $attributes = [];

            foreach (['label', 'description', 'is_default'] as $field) {
                if (array_key_exists($field, $data)) {
                    $attributes[$field] = $field === 'is_default' ? (bool) $data[$field] : $data[$field];
                }
            }

            // name / panel / level move only on roles that are not protected.
            if (! $role->isProtected()) {
                foreach (['name', 'panel', 'level'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $attributes[$field] = $data[$field];
                    }
                }
            }

            $reason = $this->clean($data['reason'] ?? null);

            if ($attributes !== []) {
                $role->withReason($reason ?? 'Role updated')->fill($attributes)->save();
            }

            if ($permissions !== null) {
                $this->syncMatrix($role, $permissions, $reason);
            }

            return $role->refresh();
        });
    }

    /**
     * Delete a role.
     *
     * @throws ActionNotAllowedException when the role is protected or still has members
     */
    public function delete(Role $role): void
    {
        if ($role->isProtected()) {
            throw ActionNotAllowedException::protectedRole((string) $role->name);
        }

        $members = $role->users()->count();

        if ($members > 0) {
            throw ActionNotAllowedException::roleInUse((string) $role->name, $members);
        }

        DB::transaction(function () use ($role): void {
            $held = $role->permissions()->pluck('name')->all();

            $this->audit(
                $role,
                'Role deleted',
                [
                    'old' => [
                        'name' => $role->name,
                        'label' => $role->label,
                        'panel' => $role->panel instanceof PanelType ? $role->panel->value : $role->panel,
                        'level' => $role->level,
                        'permissions' => $held,
                    ],
                ],
                self::MODULE,
                sprintf('Role "%s" deleted with %d permission(s)', (string) $role->name, count($held)),
            );

            $role->syncPermissions([]);
            $role->delete();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Replace the role's permission set and record what moved.
     *
     * Every submitted name must exist: a role can never hold a permission that is not in the
     * `permissions` table (phase-01 §10 "Role protection"). The Form Request checks this too;
     * the service repeats it so no other caller can slip past.
     *
     * @param  array<int, string>  $permissions
     *
     * @throws ActionNotAllowedException
     */
    private function syncMatrix(Role $role, array $permissions, ?string $reason = null): void
    {
        $wanted = array_values(array_unique(array_filter(array_map(
            static fn (mixed $name): string => trim((string) $name),
            $permissions,
        ), static fn (string $name): bool => $name !== '')));

        $before = $role->permissions()->pluck('name')->map(static fn (mixed $n): string => (string) $n)->all();

        /** @var Collection<int, Permission> $models */
        $models = $wanted === []
            ? Permission::query()->whereRaw('1 = 0')->get()
            : Permission::query()->whereIn('name', $wanted)->get();

        $resolved = $models->pluck('name')->map(static fn (mixed $n): string => (string) $n)->all();

        foreach ($wanted as $name) {
            if (! in_array($name, $resolved, true)) {
                throw ActionNotAllowedException::unknownPermission($name);
            }
        }

        $role->syncPermissions($models);

        sort($before);
        sort($resolved);

        $added = array_values(array_diff($resolved, $before));
        $removed = array_values(array_diff($before, $resolved));

        if ($added === [] && $removed === []) {
            return;
        }

        $this->audit(
            $role,
            'Role permissions updated',
            [
                'old' => ['permissions' => $before],
                'attributes' => ['permissions' => $resolved],
                'added' => $added,
                'removed' => $removed,
            ],
            self::MODULE,
            $reason ?? $this->describeMatrixChange($added, $removed),
        );
    }

    /**
     * @param  array<int, string>  $added
     * @param  array<int, string>  $removed
     */
    private function describeMatrixChange(array $added, array $removed): string
    {
        $parts = [];

        if ($added !== []) {
            $parts[] = sprintf('granted %d (%s)', count($added), implode(', ', array_slice($added, 0, 10)));
        }

        if ($removed !== []) {
            $parts[] = sprintf('revoked %d (%s)', count($removed), implode(', ', array_slice($removed, 0, 10)));
        }

        return 'Permissions '.implode('; ', $parts);
    }

    /**
     * A protected role keeps its identity even when a Super Admin submits the form.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ActionNotAllowedException
     */
    private function assertProtectedFieldsUnchanged(Role $role, array $data): void
    {
        $currentPanel = $role->panel instanceof PanelType ? $role->panel->value : (string) $role->panel;

        $changed = (array_key_exists('name', $data) && (string) $data['name'] !== (string) $role->name)
            || (array_key_exists('panel', $data) && (string) $data['panel'] !== $currentPanel)
            || (array_key_exists('level', $data) && (int) $data['level'] !== (int) $role->level);

        if ($changed) {
            throw ActionNotAllowedException::protectedRole((string) $role->name);
        }
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 500);
    }
}
