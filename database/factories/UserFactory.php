<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ThemePreference;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password = null;

    /**
     * Default state: an active, verified staff-shaped account with no branch and no role.
     *
     * Roles are never implied — a test states what it needs with `withRole()`, so a factory
     * user can never accidentally pass a permission check.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'phone' => fake()->numerify('+92 3## ### ####'),
            'whatsapp' => null,
            'avatar_path' => null,
            'status' => UserStatus::Active,
            'status_reason' => null,
            'status_changed_at' => null,
            'theme' => ThemePreference::System,
            'locale' => 'en',
            'timezone' => null,
            'must_change_password' => false,
            'branch_id' => null,
        ];
    }

    /**
     * The model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Suspended — cannot sign in (UserStatus::canLogin() is false).
     */
    public function suspended(string $reason = 'Suspended by an administrator.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UserStatus::Suspended,
            'status_reason' => $reason,
            'status_changed_at' => now(),
        ]);
    }

    /**
     * Deactivated — cannot sign in.
     */
    public function inactive(string $reason = 'Account deactivated.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UserStatus::Inactive,
            'status_reason' => $reason,
            'status_changed_at' => now(),
        ]);
    }

    /**
     * Invited but not yet activated — cannot sign in.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UserStatus::Pending,
            'status_changed_at' => now(),
        ]);
    }

    /**
     * Forced through the change-password screen on the next request.
     */
    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes): array => [
            'must_change_password' => true,
            'password_changed_at' => null,
        ]);
    }

    /**
     * Pick an interface theme explicitly.
     */
    public function theme(ThemePreference $theme): static
    {
        return $this->state(fn (array $attributes): array => [
            'theme' => $theme,
        ]);
    }

    /**
     * Attach the user to a branch; defaults to the seeded default branch.
     */
    public function forBranch(Branch|int|null $branch = null): static
    {
        return $this->state(function (array $attributes) use ($branch): array {
            $id = match (true) {
                $branch instanceof Branch => $branch->getKey(),
                is_int($branch) => $branch,
                default => Branch::default()?->getKey(),
            };

            return ['branch_id' => $id];
        });
    }

    /**
     * Give the created user one or more roles by name.
     *
     *   User::factory()->withRole('Admin')->create();
     *   User::factory()->withRole('Teacher')->create();
     *
     * The role must already exist (RoleSeeder), so a typo fails loudly with spatie's
     * RoleDoesNotExist instead of silently creating a role with the wrong panel.
     */
    public function withRole(string $role, string ...$moreRoles): static
    {
        $roles = array_merge([$role], $moreRoles);

        return $this->afterCreating(function (User $user) use ($roles): void {
            $user->assignRole($roles);
        });
    }
}
