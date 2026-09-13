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
 * Phase 1 · §5 — one demo account per seeded role so every panel is testable immediately.
 *
 * Email pattern `{role-slug}@myoffice.test` (admin@myoffice.test, project-manager@myoffice.test,
 * student@myoffice.test, …), password `password`, Active, `must_change_password` false.
 * Super Admin is skipped — that account is owned by SuperAdminSeeder and has a real password.
 *
 * These accounts are convenience fixtures, never production data:
 *   · in `production` they are never seeded, even with `SEED_DEMO=true` (D65);
 *   · elsewhere `SEED_DEMO=true|false` decides explicitly when it is set;
 *   · otherwise they are seeded.
 *
 * (The contract words this as "local or SEED_DEMO (default true)". Any path that seeds production
 * would create accounts whose password is literally `password`, so D65 makes that impossible rather
 * than merely off by default; `local` and `testing` behave as specified.)
 *
 * Idempotent: matched on `users.email`; an existing demo account keeps its password and is only
 * re-activated and re-attached to its role.
 */
class DemoUserSeeder extends Seeder
{
    use WritesToConsole;

    /** The one password every demo account shares. */
    public const DEMO_PASSWORD = 'password';

    /** Mail domain the demo accounts live on. */
    public const DEMO_DOMAIN = 'myoffice.test';

    public function run(): void
    {
        if (! $this->shouldSeed()) {
            $this->seedComment(app()->environment('production')
                ? 'Demo users: skipped (never seeded in production, D65).'
                : 'Demo users: skipped (set SEED_DEMO=true to create them in this environment).');

            return;
        }

        $guard = (string) config('auth.defaults.guard', 'web');

        /** @var array<int, array<int, string>> $rows */
        $rows = [];
        $created = 0;

        DB::transaction(function () use ($guard, &$rows, &$created): void {
            $branchId = Branch::default()?->getKey();

            $roles = Role::query()
                ->where('guard_name', $guard)
                ->where('name', '!=', User::SUPER_ADMIN_ROLE)
                ->ordered()
                ->get();

            foreach ($roles as $role) {
                $email = Str::slug((string) $role->name).'@'.self::DEMO_DOMAIN;

                $user = User::withTrashed()->where('email', $email)->first();
                $isNew = $user === null;

                if ($user === null) {
                    $user = new User;
                    $user->email = $email;
                    $user->password = self::DEMO_PASSWORD;   // hashed by the model cast
                    $user->password_changed_at = now();
                    $user->locale = 'en';
                    $created++;
                }

                if ($user->trashed()) {
                    $user->restore();
                }

                $user->name = 'Demo '.$role->displayName();
                $user->status = UserStatus::Active;
                $user->status_reason = null;
                $user->must_change_password = false;

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
                    $user->branch_id = $branchId;
                }

                $user->save();

                if (! $user->hasRole($role)) {
                    $user->assignRole($role);
                }

                $rows[] = [
                    (string) $role->name,
                    $role->panelType()->value,
                    $email,
                    $isNew ? self::DEMO_PASSWORD : 'unchanged',
                ];
            }
        });

        if ($rows === []) {
            $this->seedWarning('Demo users: no roles found — run RoleSeeder first.');

            return;
        }

        $this->seedInfo(sprintf('Demo users: %d account(s), %d created.', count($rows), $created));
        $this->seedTable(['Role', 'Panel', 'Email', 'Password'], $rows);
    }

    /**
     * Never in production, whatever SEED_DEMO says (D65): a weak demo password must be impossible
     * there, not merely off by default. Elsewhere an explicit SEED_DEMO wins; otherwise seed.
     */
    private function shouldSeed(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        $flag = env('SEED_DEMO');

        if ($flag !== null && $flag !== '') {
            return filter_var($flag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        }

        return ! app()->environment('production');
    }
}
