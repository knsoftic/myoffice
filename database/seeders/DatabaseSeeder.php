<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Phase 1 · §5 — the install sequence.
 *
 * Order matters:
 *   1. BranchSeeder      the default branch every user and institute row can point at
 *   2. ModuleSeeder      the module rows the permission gate reads
 *   3. PermissionSeeder  one permission per PermissionRegistry entry (+ cache flush)
 *   4. RoleSeeder        the 18 roles and their grants (needs the permissions to exist)
 *   5. SettingSeeder     the settings catalogue
 *   6. SuperAdminSeeder  the first account (needs the Super Admin role and the branch)
 *   7. DemoUserSeeder    one demo account per remaining role (needs the roles)
 *   8. WebsiteCmsSeeder  the day-one public site: menus, system pages, home sections (needs settings)
 *
 * Every seeder is idempotent: `php artisan db:seed` can be run on an existing database as often
 * as you like. Nothing is truncated and nothing is deleted — values an administrator changed
 * (a disabled module, an edited setting, a password) are preserved.
 *
 * Note: model events are deliberately NOT suppressed here (no `WithoutModelEvents`). The seeders
 * rely on them: the Setting model encrypts the SMTP password on save and the Module model flushes
 * the cached module gate map.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BranchSeeder::class,
            ModuleSeeder::class,
            PermissionSeeder::class,
            RoleSeeder::class,
            SettingSeeder::class,
            SuperAdminSeeder::class,
            DemoUserSeeder::class,
            WebsiteCmsSeeder::class,
            // phase-13 §2.3. After the settings seeder, because the reserved `salaries` category is
            // what `RecordPayrollExpense` posts into and a fresh install should have it before
            // anything can try.
            FinanceCategorySeeder::class,
        ]);
    }
}
