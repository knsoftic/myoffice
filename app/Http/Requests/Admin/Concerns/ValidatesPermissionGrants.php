<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Concerns;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Throwable;

/**
 * The two rules that guard the role permission matrix (phase-01 §10):
 *
 *   1. a role may not be granted a permission that does not exist — the submitted names are
 *      matched against the `permissions` table, and anything unknown fails validation;
 *   2. a user may not grant a permission they do not themselves hold, unless they are a
 *      Super Admin.
 *
 * Rule 2 deliberately asks `hasPermissionTo()` rather than `can()`. `can()` runs through
 * `Gate::before`, which denies every ability of a disabled module — an admin who genuinely holds
 * `projects.edit` would otherwise be unable to grant it while the Projects module is switched
 * off, even though the grant is about future access, not access right now.
 */
trait ValidatesPermissionGrants
{
    /**
     * The submitted permission names, trimmed and de-duplicated.
     *
     * @return array<int, string>
     */
    protected function submittedPermissions(): array
    {
        $permissions = $this->input('permissions', []);

        if (! is_array($permissions)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $name): string => trim((string) $name), $permissions),
            static fn (string $name): bool => $name !== '',
        )));
    }

    /**
     * @param  array<int, string>  $names
     */
    protected function assertPermissionsAreGrantable(Validator $validator, array $names): void
    {
        if ($names === []) {
            return;
        }

        $known = Permission::query()
            ->whereIn('name', $names)
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();

        foreach (array_values(array_diff($names, $known)) as $unknown) {
            $validator->errors()->add('permissions', sprintf(
                'The permission "%s" does not exist.',
                $unknown,
            ));
        }

        $actor = $this->user();

        if (! $actor instanceof User || $actor->isSuperAdmin()) {
            return;
        }

        foreach ($known as $name) {
            if ($this->actorHolds($actor, $name)) {
                continue;
            }

            $validator->errors()->add('permissions', sprintf(
                'You may not grant "%s" because you do not hold it yourself.',
                $name,
            ));
        }
    }

    /**
     * Does the actor hold this permission through any of their roles (or directly)?
     */
    private function actorHolds(User $actor, string $permission): bool
    {
        try {
            return $actor->hasPermissionTo($permission);
        } catch (Throwable) {
            // Unknown permission name: already reported by the existence check above.
            return false;
        }
    }
}
