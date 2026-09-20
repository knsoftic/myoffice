<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\Ability;
use App\Enums\ModuleGroup;
use App\Support\PermissionRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * App\Support\PermissionRegistry — the single source of truth for modules and permissions
 * (phase-01 §4, CLAUDE.md §4, decision D4).
 *
 * The seeders, the sidebar builder, the role editor and `Gate::before` all read from here, so its
 * internal integrity is load-bearing: a duplicate name would make `syncPermissions()` silently
 * drop a grant, a module with no abilities would be ungrantable, and a permission whose ability is
 * not an Ability case would break `Permission::abilityCase()` and the matrix grid.
 *
 * Pure arrays by contract — no database, no cache, no facades, no container — so this is a real
 * unit test.
 */
final class PermissionRegistryTest extends TestCase
{
    /** Suffix marking the portal permission namespaces, whose abilities are free-form strings. */
    private const PORTAL_SUFFIX = '_portal';

    /*
    |--------------------------------------------------------------------------
    | Integrity
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function there_are_no_duplicate_permission_names(): void
    {
        $names = PermissionRegistry::permissionNames();
        $counts = array_count_values($names);
        $duplicates = array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));

        $this->assertSame([], $duplicates, 'A duplicate permission name would make a grant unsyncable.');
        $this->assertCount(count($names), array_unique($names));
    }

    #[Test]
    public function every_module_declares_at_least_one_ability(): void
    {
        foreach (PermissionRegistry::modules() as $slug => $definition) {
            $this->assertNotEmpty(
                $definition['abilities'],
                sprintf('Module %s declares no ability, so nothing about it could ever be granted.', $slug)
            );
        }
    }

    #[Test]
    public function every_module_declares_no_duplicate_ability(): void
    {
        foreach (PermissionRegistry::moduleSlugs() as $slug) {
            $abilities = PermissionRegistry::abilityValuesFor($slug);

            $this->assertSame(
                array_values(array_unique($abilities)),
                $abilities,
                sprintf('Module %s declares the same ability twice.', $slug)
            );
        }
    }

    #[Test]
    public function every_module_definition_is_completely_specified(): void
    {
        foreach (PermissionRegistry::modules() as $slug => $definition) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                (string) $slug,
                'Module slugs are snake_case (CLAUDE.md §3).'
            );

            foreach (['name', 'group', 'icon', 'is_core', 'sort', 'abilities'] as $key) {
                $this->assertArrayHasKey($key, $definition, $slug.' is missing '.$key);
            }

            $this->assertIsString($definition['name']);
            $this->assertNotSame('', trim($definition['name']), $slug.' has no name.');
            $this->assertInstanceOf(ModuleGroup::class, $definition['group'], $slug.' has no ModuleGroup.');
            $this->assertIsString($definition['icon']);
            $this->assertNotSame('', trim($definition['icon']), $slug.' has no icon.');
            $this->assertIsBool($definition['is_core']);
            $this->assertIsInt($definition['sort']);
            $this->assertGreaterThan(0, $definition['sort'], $slug.' has no sort order.');
        }
    }

    /**
     * Two modules sharing a sort order would make the sidebar and the permission matrix order
     * non-deterministic.
     */
    #[Test]
    public function module_sort_orders_are_unique(): void
    {
        $sorts = array_map(
            static fn (array $definition): int => (int) $definition['sort'],
            PermissionRegistry::modules(),
        );

        $this->assertSame(count($sorts), count(array_unique($sorts)));
    }

    #[Test]
    public function permission_sort_orders_are_unique(): void
    {
        $sorts = array_column(PermissionRegistry::permissions(), 'sort_order');

        $this->assertSame(count($sorts), count(array_unique($sorts)));
    }

    /*
    |--------------------------------------------------------------------------
    | Naming contract
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_permission_is_named_module_dot_ability(): void
    {
        foreach (PermissionRegistry::permissions() as $row) {
            $this->assertSame(
                $row['module'].'.'.$row['ability'],
                $row['name'],
                'Permission name = "{module}.{ability}" (CLAUDE.md §3).'
            );

            $this->assertArrayHasKey($row['module'], PermissionRegistry::modules());
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $row['ability']);
        }
    }

    #[Test]
    public function every_permission_label_is_the_ability_label_plus_the_module_name(): void
    {
        $modules = PermissionRegistry::modules();

        foreach (PermissionRegistry::permissions() as $row) {
            $moduleName = $modules[$row['module']]['name'];
            $ability = Ability::tryFrom($row['ability']);

            $expected = $ability instanceof Ability
                ? $ability->label().' '.$moduleName
                : ucwords(str_replace('_', ' ', $row['ability'])).' '.$moduleName;

            $this->assertSame($expected, $row['label'], $row['name'].' has the wrong label.');
        }
    }

    #[Test]
    public function every_permission_carries_its_modules_group(): void
    {
        $modules = PermissionRegistry::modules();

        foreach (PermissionRegistry::permissions() as $row) {
            $this->assertSame($modules[$row['module']]['group']->value, $row['group'], $row['name']);
            $this->assertInstanceOf(ModuleGroup::class, ModuleGroup::from($row['group']));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Abilities
    |--------------------------------------------------------------------------
    */

    /**
     * Real modules declare Ability cases, never bare strings — that is what keeps a renamed ability
     * a compile-time problem instead of a silent 403.
     */
    #[Test]
    public function every_business_module_ability_is_an_ability_enum_case(): void
    {
        foreach (PermissionRegistry::modules() as $slug => $definition) {
            if (str_ends_with((string) $slug, self::PORTAL_SUFFIX)) {
                continue;
            }

            foreach ($definition['abilities'] as $ability) {
                $this->assertInstanceOf(
                    Ability::class,
                    $ability,
                    sprintf('Module %s declares a bare-string ability; use an Ability case.', $slug)
                );
            }
        }
    }

    /**
     * Every ability value on a business module also has to resolve back through the enum — this is
     * what `Permission::abilityCase()` and `App\Models\Casts\AbilityCast` depend on.
     */
    #[Test]
    public function every_business_module_ability_value_maps_back_to_the_ability_enum(): void
    {
        foreach (PermissionRegistry::modules() as $slug => $definition) {
            if (str_ends_with((string) $slug, self::PORTAL_SUFFIX)) {
                continue;
            }

            foreach (PermissionRegistry::abilityValuesFor((string) $slug) as $value) {
                $this->assertInstanceOf(
                    Ability::class,
                    Ability::tryFrom($value),
                    sprintf('%s.%s is not an Ability case.', $slug, $value)
                );
            }
        }
    }

    /**
     * The portal namespaces are permission namespaces, not CRUD modules, so their abilities are
     * deliberately free-form — but still snake_case, because they become permission names.
     */
    #[Test]
    public function portal_abilities_are_snake_case_strings(): void
    {
        foreach (PermissionRegistry::modules() as $slug => $definition) {
            if (! str_ends_with((string) $slug, self::PORTAL_SUFFIX)) {
                continue;
            }

            foreach ($definition['abilities'] as $ability) {
                $this->assertIsString($ability, $slug.' portal abilities are plain strings.');
                $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $ability, $slug.'.'.$ability);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The contract's module list (phase-01 §4)
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: ModuleGroup, 1: array<int, string>}>
     */
    public static function contractModuleProvider(): array
    {
        return [
            'system' => [ModuleGroup::System, [
                'dashboard', 'users', 'roles', 'permissions', 'modules', 'settings',
                'activity_log', 'login_history', 'backups', 'global_search',
            ]],
            'software house' => [ModuleGroup::SoftwareHouse, [
                'leads', 'clients', 'projects', 'project_milestones', 'tasks', 'time_tracking',
            ]],
            'hr' => [ModuleGroup::Hr, [
                'employees', 'departments', 'attendance', 'leaves', 'payroll',
            ]],
            'finance' => [ModuleGroup::Finance, [
                'invoices', 'payments', 'expenses', 'income', 'payment_methods',
            ]],
            'collaborator' => [ModuleGroup::Collaborator, [
                'collaborators', 'collaborator_commission_settings', 'collaborator_commissions',
                'collaborator_wallets', 'collaborator_payouts', 'collaborator_referrals',
            ]],
            'institute' => [ModuleGroup::Institute, [
                'course_categories', 'courses', 'course_outline', 'course_materials', 'students',
                'admissions', 'course_inquiries', 'demo_classes', 'teachers', 'batches', 'timetable',
                'student_attendance', 'student_progress', 'student_fees', 'installments',
                'fee_discounts', 'assignments', 'exams', 'results', 'certificates',
                'student_id_cards',
            ]],
            'website' => [ModuleGroup::Website, [
                'website_sections', 'menus', 'pages', 'services', 'portfolio', 'team', 'testimonials',
                'student_reviews', 'success_stories', 'faqs', 'blog_categories', 'blog_posts', 'jobs',
                'job_applications', 'contact_inquiries', 'seo',
                // phase-03 §4.1
                'website_cta_blocks', 'website_media', 'faq_categories',
            ]],
            'shared' => [ModuleGroup::Shared, [
                'support_tickets', 'meetings', 'messages', 'files', 'notifications', 'reports',
            ]],
        ];
    }

    /**
     * @param  array<int, string>  $slugs
     */
    #[Test]
    #[DataProvider('contractModuleProvider')]
    public function the_contracted_modules_are_registered_in_the_right_group(ModuleGroup $group, array $slugs): void
    {
        $modules = PermissionRegistry::modules();

        foreach ($slugs as $slug) {
            $this->assertArrayHasKey($slug, $modules, sprintf('Module %s is not registered.', $slug));
            $this->assertSame($group, $modules[$slug]['group'], $slug.' is in the wrong group.');
        }
    }

    /**
     * Core means "can never be disabled", so an accidental `is_core` on a business module would
     * make it undisableable for ever. Exactly two families are core:
     *
     *  1. the System group (phase-01 §4);
     *  2. the four `*_portal` namespaces. Those are permission *prefixes* for the four non-admin
     *     panels, not feature areas — a single toggle on one of them would make
     *     `student_portal.dashboard` (and the rest) return false for everyone, Super Admin
     *     included, locking every student, teacher, client or collaborator out of their own
     *     panel with a bare 403. The collaborator panel is gated on the real business module
     *     `collaborators` (routes/collaborator.php) instead.
     */
    #[Test]
    public function exactly_the_system_modules_and_the_portal_namespaces_are_core(): void
    {
        $this->assertSame(
            [
                'dashboard', 'users', 'roles', 'permissions', 'modules', 'settings',
                'activity_log', 'login_history', 'backups', 'global_search',
                'collaborator_portal', 'student_portal', 'teacher_portal', 'client_portal',
            ],
            PermissionRegistry::coreSlugs(),
        );

        foreach (PermissionRegistry::modules() as $slug => $definition) {
            $mustBeCore = $definition['group'] === ModuleGroup::System
                || str_ends_with($slug, '_portal');

            $this->assertSame(
                $mustBeCore,
                $definition['is_core'],
                sprintf('%s: is_core must be true exactly for the System group and the *_portal namespaces.', $slug)
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Portal namespaces (phase-01 §4, spec §59)
    |--------------------------------------------------------------------------
    */

    /**
     * The collaborator portal list is spelled out in the contract, so it is spelled out here too.
     */
    #[Test]
    public function every_contracted_collaborator_portal_permission_exists(): void
    {
        $expected = [
            'collaborator_portal.dashboard',
            'collaborator_portal.students',
            'collaborator_portal.student_fee_status',
            'collaborator_portal.student_commission',
            'collaborator_portal.projects',
            'collaborator_portal.project_client',
            'collaborator_portal.project_value',
            'collaborator_portal.project_payments',
            'collaborator_portal.project_commission',
            'collaborator_portal.tasks',
            'collaborator_portal.tasks_update',
            'collaborator_portal.files_upload',
            'collaborator_portal.files_download',
            'collaborator_portal.comments',
            'collaborator_portal.meetings',
            'collaborator_portal.messages',
            'collaborator_portal.payout_request',
            'collaborator_portal.statement_download',
        ];

        $names = PermissionRegistry::permissionNames();

        foreach ($expected as $permission) {
            $this->assertContains($permission, $names, $permission.' is missing from the registry.');
        }
    }

    #[Test]
    public function every_panel_has_a_portal_namespace_with_a_dashboard_and_a_profile(): void
    {
        $names = PermissionRegistry::permissionNames();

        foreach (['collaborator_portal', 'student_portal', 'teacher_portal', 'client_portal'] as $portal) {
            $this->assertArrayHasKey($portal, PermissionRegistry::modules());
            $this->assertContains($portal.'.dashboard', $names, $portal.' has no dashboard permission.');

            if ($portal !== 'collaborator_portal') {
                $this->assertContains($portal.'.profile', $names, $portal.' has no profile permission.');
            }
        }
    }

    /**
     * A portal namespace must never leak an admin-side ability name, or a portal role would look
     * like it holds a module permission.
     */
    #[Test]
    public function a_portal_permission_is_never_confusable_with_a_module_permission(): void
    {
        foreach (PermissionRegistry::permissionNames() as $name) {
            [$module] = explode('.', $name, 2);

            if (! str_ends_with($module, self::PORTAL_SUFFIX)) {
                continue;
            }

            $this->assertStringStartsWith($module.'.', $name);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Query helpers
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function permission_names_for_narrows_to_the_given_modules_and_abilities(): void
    {
        $this->assertSame(
            ['users.view_any', 'users.view'],
            PermissionRegistry::permissionNamesFor('users', [Ability::ViewAny, Ability::View]),
        );

        $both = PermissionRegistry::permissionNamesFor(['departments', 'roles']);

        $this->assertContains('departments.view_any', $both);
        $this->assertContains('roles.view_any', $both);
        $this->assertNotContains('users.view_any', $both);

        $this->assertSame([], PermissionRegistry::permissionNamesFor('a_module_that_does_not_exist'));
        $this->assertSame([], PermissionRegistry::permissionNamesFor('users', []));
    }

    #[Test]
    public function permission_names_for_never_repeats_a_name(): void
    {
        $names = PermissionRegistry::permissionNamesFor(['users', 'users', 'roles']);

        $this->assertSame(array_values(array_unique($names)), $names);
    }

    #[Test]
    public function permission_names_for_group_covers_every_module_in_that_group(): void
    {
        $finance = PermissionRegistry::permissionNamesForGroup(ModuleGroup::Finance);

        foreach (['invoices', 'payments', 'expenses', 'income', 'payment_methods'] as $slug) {
            $this->assertContains($slug.'.view_any', $finance);
        }

        $this->assertNotContains('users.view_any', $finance);
    }

    #[Test]
    public function module_lookups_are_consistent_with_each_other(): void
    {
        $slugs = PermissionRegistry::moduleSlugs();

        $this->assertSame(array_keys(PermissionRegistry::modules()), $slugs);
        $this->assertNull(PermissionRegistry::module('a_module_that_does_not_exist'));
        $this->assertSame([], PermissionRegistry::abilitiesFor('a_module_that_does_not_exist'));

        foreach ($slugs as $slug) {
            $this->assertIsArray(PermissionRegistry::module($slug));
        }
    }

    /**
     * Financial modules carry `view_financial`, so money figures can be hidden from a role that may
     * still see the record (phase-01 §4).
     */
    #[Test]
    public function financial_modules_declare_the_money_ability(): void
    {
        foreach (['invoices', 'payments', 'expenses', 'income', 'student_fees', 'installments', 'collaborator_payouts'] as $slug) {
            $this->assertContains(
                Ability::ViewFinancial->value,
                PermissionRegistry::abilityValuesFor($slug),
                $slug.' must declare view_financial.'
            );
        }
    }

    /**
     * Financial history is immutable (CLAUDE.md rule 3), so the commission ledger and the wallets are
     * never editable or deletable — a correction is a reversing entry that references the original.
     *
     * **`collaborator_commissions.create` is deliberately allowed** from Phase 10 (phase-10-12 §4.2,
     * spine §6.2): it is the manual adjustment and write-off path, a human act that **appends** an
     * audited `manual_adjustment` or `write_off` row through `LedgerWriter` — which is precisely what
     * rule 3 prescribes, not an exception to it. What must never exist is `edit` or `delete`, and the
     * model's own guards refuse both whatever a role holds.
     *
     * `collaborator_wallets` keeps all three refusals: a wallet is derived from the ledger and is
     * rebuilt, never authored.
     */
    #[Test]
    public function the_immutable_financial_modules_declare_no_write_abilities(): void
    {
        foreach (['collaborator_commissions', 'collaborator_wallets'] as $slug) {
            $abilities = PermissionRegistry::abilityValuesFor($slug);

            $this->assertNotContains(Ability::Edit->value, $abilities, $slug.' must not be editable.');
            $this->assertNotContains(Ability::Delete->value, $abilities, $slug.' must not be deletable.');
        }

        $this->assertNotContains(
            Ability::Create->value,
            PermissionRegistry::abilityValuesFor('collaborator_wallets'),
            'A wallet is derived from the ledger. Nobody authors one.'
        );
    }

    /**
     * The four money modules phase-10-12 §4.1 adds carry no `edit` and no `delete`, **for ever**
     * (INV-8, INV-5). A received payment is never editable: voiding it is `change_status`, and the
     * void leaves the original visible.
     *
     * `collaborator_commission_settings` is deliberately **not** in this list even though a rule version
     * is equally immutable. It declared `edit` and `delete` in Phase 1, and seeders converge additively
     * (D65) — taking an ability away here would revoke a permission somebody has already been granted.
     * They are frozen and unused instead: INV-17 refuses the write at the model, and no route, service
     * or screen offers an edit form. That is a guarantee the registry cannot make and the model can.
     */
    #[Test]
    public function the_money_modules_never_declare_edit_or_delete(): void
    {
        foreach (['student_fee_payments', 'project_payments', 'payment_reversals', 'wallet_reconciliation'] as $slug) {
            $abilities = PermissionRegistry::abilityValuesFor($slug);

            $this->assertNotContains(Ability::Delete->value, $abilities, $slug.' must never be deletable.');
            $this->assertNotContains(
                Ability::Edit->value,
                $abilities,
                $slug.' must never be editable — the correct act is a void and a fresh row.'
            );
        }
    }

    /**
     * D43's narrow ability, pinned where it can be seen: on one slug, in no preset, and never widened.
     */
    #[Test]
    public function link_invoice_is_declared_on_project_payments_and_nowhere_else(): void
    {
        $holders = [];

        foreach (array_keys(PermissionRegistry::modules()) as $slug) {
            if (in_array(Ability::LinkInvoice->value, PermissionRegistry::abilityValuesFor($slug), true)) {
                $holders[] = $slug;
            }
        }

        $this->assertSame(['project_payments'], $holders,
            'link_invoice permits exactly one field move on one table (D43). A second slug declaring it '
            .'would be a second thing it permits.');

        $this->assertNotContains(
            Ability::ViewFinancial->value,
            [Ability::LinkInvoice->value],
            'It is not a money ability: holding it reveals no amount by itself.'
        );
    }
}
