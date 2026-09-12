<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\PanelType;
use App\Models\Role;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Update a role and its permission matrix.
 *
 * A protected (`is_system`) role keeps its identity: the edit form disables `name`, `panel` and
 * `level`, and this request **rejects** a submission that changes them anyway. That check matters
 * precisely because `Gate::before` grants Super Admin every policy, so `RolePolicy::update()`
 * — which refuses protected roles outright — never runs for them (phase-01 §10 "Role protection").
 * Label, description and the permission matrix of a system role stay editable.
 */
final class UpdateRoleRequest extends StoreRoleRequest
{
    public function authorize(): bool
    {
        $role = $this->target();

        return $role instanceof Role && Gate::allows('update', $role);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $role = $this->target();

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:150',
                Rule::unique('roles', 'name')
                    ->where('guard_name', $this->guardName())
                    ->ignore($role?->getKey()),
            ],

            'label' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],

            'panel' => ['required', Rule::enum(PanelType::class)],
            'level' => ['required', 'integer', 'min:1', 'max:65535'],

            'is_default' => ['nullable', 'boolean'],

            'reason' => ['nullable', 'string', 'max:255'],

            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:191'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertPermissionsAreGrantable($validator, $this->submittedPermissions());

            $role = $this->target();

            if (! $role instanceof Role) {
                return;
            }

            if ($role->isProtected()) {
                $this->assertProtectedFieldsUnchanged($validator, $role);

                // A protected role's level is fixed, so the "weaker than me" rule has nothing
                // left to police here.
                return;
            }

            $this->assertLevelIsWeakerThanActor($validator);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = parent::payload();

        // The service also refuses these for a protected role; dropping them here keeps the
        // "nothing changed" path clean so no spurious activity row is written.
        $role = $this->target();

        if ($role instanceof Role && $role->isProtected()) {
            unset($data['name'], $data['panel'], $data['level']);
        }

        return $data;
    }

    /**
     * `name`, `panel` and `level` are frozen on a system role.
     */
    private function assertProtectedFieldsUnchanged(Validator $validator, Role $role): void
    {
        if ((string) $this->input('name') !== (string) $role->name) {
            $validator->errors()->add('name', 'A system role cannot be renamed.');
        }

        $currentPanel = $role->panel instanceof PanelType ? $role->panel->value : (string) $role->panel;

        if ((string) $this->input('panel') !== $currentPanel) {
            $validator->errors()->add('panel', 'A system role cannot change panel.');
        }

        if ($this->filled('level') && (int) $this->input('level') !== (int) $role->level) {
            $validator->errors()->add('level', 'A system role cannot change level.');
        }
    }

    private function target(): ?Role
    {
        $role = $this->route('role');

        return $role instanceof Role ? $role : null;
    }
}
