<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ThemePreference;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\Concerns\WritesToConsole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 1 · §5 — the first account (DEVELOPMENT_LOG §9 Q8).
 *
 * Identity comes from the environment so an installation never has to ship a known password:
 *
 *   SUPERADMIN_EMAIL     default superadmin@myoffice.test
 *   SUPERADMIN_NAME      default "Super Admin"
 *   SUPERADMIN_PASSWORD  when absent, a random 16-character password is generated and printed
 *                        exactly once — the account is flagged `must_change_password`
 *
 * The account is Active, e-mail verified, attached to the default branch and holds the
 * Super Admin role (which `Gate::before` short-circuits).
 *
 * Idempotent: matched on `users.email`. An existing account keeps its password — the seeder
 * never resets a live credential — and is only brought back to a usable state (active,
 * verified, role attached).
 */
class SuperAdminSeeder extends Seeder
{
    use WritesToConsole;

    public const DEFAULT_EMAIL = 'superadmin@myoffice.test';

    public const DEFAULT_NAME = 'Super Admin';

    /** Length of the generated password when SUPERADMIN_PASSWORD is not set. */
    private const GENERATED_PASSWORD_LENGTH = 16;

    public function run(): void
    {
        $email = $this->envString('SUPERADMIN_EMAIL', self::DEFAULT_EMAIL);
        $name = $this->envString('SUPERADMIN_NAME', self::DEFAULT_NAME);

        $configuredPassword = $this->envString('SUPERADMIN_PASSWORD', '');
        $wasGenerated = $configuredPassword === '';

        // Letters + digits only: the password is read off a console and typed in by hand.
        $password = $wasGenerated
            ? Str::password(self::GENERATED_PASSWORD_LENGTH, true, true, false, false)
            : $configuredPassword;

        $created = DB::transaction(function () use ($email, $name, $password): bool {
            // withTrashed: users are soft-deleted but `email` is unique, so a trashed row
            // would make a plain insert collide.
            $user = User::withTrashed()->where('email', $email)->first();
            $created = $user === null;

            if ($user === null) {
                $user = new User;
                $user->email = $email;
                $user->password = $password;              // hashed by the model cast
                $user->must_change_password = true;       // forced change on first sign-in
                $user->password_changed_at = null;
                $user->locale = 'en';
            }

            if ($user->trashed()) {
                $user->restore();
            }

            $user->name = $name;
            $user->status = UserStatus::Active;
            $user->status_reason = null;

            if ($user->status_changed_at === null) {
                $user->status_changed_at = now();
            }

            if ($user->theme === null) {
                $user->theme = ThemePreference::System;
            }

            if ($user->email_verified_at === null) {
                $user->email_verified_at = now();
            }

            if ($user->branch_id === null) {
                $user->branch_id = Branch::default()?->getKey();
            }

            $user->save();

            $this->attachSuperAdminRole($user);

            return $created;
        });

        $this->announce($email, $password, $created, $wasGenerated);
    }

    /**
     * Give the account the role the Gate short-circuits for, without touching any other role
     * it may already hold.
     */
    private function attachSuperAdminRole(User $user): void
    {
        $guard = (string) config('auth.defaults.guard', 'web');

        $role = Role::query()
            ->where('name', User::SUPER_ADMIN_ROLE)
            ->where('guard_name', $guard)
            ->first();

        if ($role === null) {
            $this->seedWarning(
                'Super admin: the "'.User::SUPER_ADMIN_ROLE.'" role does not exist — run RoleSeeder first. '
                .'The account was created without it.'
            );

            return;
        }

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
    }

    /**
     * Print the credentials — once, and loudly.
     */
    private function announce(string $email, string $password, bool $created, bool $wasGenerated): void
    {
        $this->seedWarning('================ SUPER ADMIN ================');
        $this->seedLine('  Email:    '.$email);

        if ($created && $wasGenerated) {
            $this->seedLine('  Password: '.$password);
            $this->seedWarning('  Shown only once — copy it now. The account must change it at first sign-in.');
        } elseif ($created) {
            $this->seedLine('  Password: the value of SUPERADMIN_PASSWORD');
            $this->seedWarning('  The account must change it at first sign-in.');
        } else {
            $this->seedLine('  Password: unchanged — the account already existed.');
        }

        $this->seedWarning('=============================================');
    }

    /**
     * A trimmed environment value, falling back to the default when absent or empty.
     */
    private function envString(string $key, string $default): string
    {
        $value = env($key);

        if ($value === null || ! is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value === '' ? $default : $value;
    }
}
