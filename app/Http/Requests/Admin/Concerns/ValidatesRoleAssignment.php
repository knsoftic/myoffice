<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Concerns;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Nobody hands out power they do not have.
 *
 * Used by the user forms: every role id in the payload is resolved and checked against
 * `UserPolicy::assignRoles()`, which requires `users.assign`, that the actor outranks the
 * **target user**, and — through `RolePolicy::assign()` — `roles.assign` plus a role level strictly
 * below the actor's own. A Super Admin passes through `Gate::before` and may grant anything, which
 * is why the requests repeat the self-grant check outside the Gate.
 *
 * The target matters as much as the role: checking only the role's level let an actor tick a
 * stronger role on **their own** edit screen, because the role was numerically weaker than their
 * own while the account receiving it was themselves.
 *
 * The ids themselves are validated by an `exists` rule in the request; this adds the
 * authorization half, which a rule cannot express.
 */
trait ValidatesRoleAssignment
{
    /**
     * The submitted role ids, normalised to ints.
     *
     * @return array<int, int>
     */
    protected function submittedRoleIds(): array
    {
        $ids = $this->input('roles', []);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * Reject the payload when the actor may not grant one of the roles in `$ids` **to this target**.
     *
     * `$target` is the account that would receive the roles: an existing row on the edit screen, or
     * a fresh unsaved `User` on the create screen (it has no rank yet, and `UserPolicy` says so).
     *
     * @param  array<int, int>  $ids
     */
    protected function assertRolesAreGrantable(Validator $validator, array $ids, User $target): void
    {
        if ($ids === []) {
            return;
        }

        foreach ($this->rolesById($ids) as $role) {
            if (Gate::allows('assignRoles', [$target, $role])) {
                continue;
            }

            $validator->errors()->add('roles', sprintf(
                'You may not grant the "%s" role.',
                $role->displayName(),
            ));
        }
    }

    /**
     * @param  array<int, int>  $ids
     * @return EloquentCollection<int, Role>
     */
    protected function rolesById(array $ids): EloquentCollection
    {
        if ($ids === []) {
            /** @var EloquentCollection<int, Role> $empty */
            $empty = Role::query()->whereRaw('1 = 0')->get();

            return $empty;
        }

        /** @var EloquentCollection<int, Role> $roles */
        $roles = Role::query()->whereIn('id', $ids)->get();

        return $roles;
    }

    /**
     * The authenticated actor, or null outside a session.
     */
    protected function actor(): ?User
    {
        $user = $this->user();

        return $user instanceof User ? $user : null;
    }
}
