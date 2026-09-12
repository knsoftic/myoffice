<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\PanelType;
use App\Http\Requests\Admin\Concerns\ValidatesPermissionGrants;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Create a role together with its permission matrix.
 *
 * Not `final`: UpdateRoleRequest extends it, because the two share every rule except the unique
 * check and the protections a system role carries.
 *
 * Two rules that only a custom check can express:
 *   · a role may not be granted a permission that does not exist, and an actor may not grant a
 *     permission they do not hold themselves (ValidatesPermissionGrants);
 *   · `level` is a rank where lower is stronger, so nobody may mint a role at or above their own
 *     level — otherwise creating a role would be a way to promote yourself.
 */
class StoreRoleRequest extends FormRequest
{
    use ValidatesPermissionGrants;

    /** Weaker than any real role (`roles.level` is an unsignedSmallInteger). */
    private const WEAKEST_LEVEL = 65535;

    public function authorize(): bool
    {
        return Gate::allows('create', Role::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'min:2', 'max:150',
                // spatie's unique index is (name, guard_name).
                Rule::unique('roles', 'name')->where('guard_name', $this->guardName()),
            ],

            'label' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],

            'panel' => ['required', Rule::enum(PanelType::class)],
            'level' => ['required', 'integer', 'min:1', 'max:'.self::WEAKEST_LEVEL],

            'is_default' => ['nullable', 'boolean'],

            'reason' => ['nullable', 'string', 'max:255'],

            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:191'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'is_default' => 'default role',
            'level' => 'level',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_default' => $this->boolean('is_default'),
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertPermissionsAreGrantable($validator, $this->submittedPermissions());
            $this->assertLevelIsWeakerThanActor($validator);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->safe()->except(['permissions']);

        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['level'] = (int) ($data['level'] ?? 50);
        $data['guard_name'] = $this->guardName();

        return $data;
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return $this->submittedPermissions();
    }

    /**
     * A new role must sit strictly below the actor — unless the actor is a Super Admin, who is
     * already above everything.
     */
    protected function assertLevelIsWeakerThanActor(Validator $validator): void
    {
        $actor = $this->user();

        if (! $actor instanceof User || $actor->isSuperAdmin()) {
            return;
        }

        $level = (int) $this->input('level');
        $best = $this->actorLevel($actor);

        if ($level <= $best) {
            $validator->errors()->add('level', sprintf(
                'Choose a level above %d — you cannot create a role as powerful as your own.',
                $best,
            ));
        }
    }

    /**
     * The strongest (lowest) level among the actor's roles.
     */
    protected function actorLevel(User $actor): int
    {
        $levels = $actor->roles
            ->map(static fn (object $role): ?int => is_numeric($role->level ?? null) ? (int) $role->level : null)
            ->reject(static fn (?int $level): bool => $level === null);

        return $levels->isEmpty() ? self::WEAKEST_LEVEL : (int) $levels->min();
    }

    protected function guardName(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }
}
