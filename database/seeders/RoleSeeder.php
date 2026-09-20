<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Ability;
use App\Enums\ModuleGroup;
use App\Enums\PanelType;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionRegistry;
use Database\Seeders\Concerns\WritesToConsole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 1 · §5 — the 18 system roles and their permission grants.
 *
 * Every grant is composed from App\Support\PermissionRegistry helpers and module slug lists:
 * there is not a single hand-typed permission string in this file (CLAUDE.md §1.8, D4). That
 * way a module whose ability set changes in the registry automatically widens or narrows the
 * roles that own it.
 *
 * Idempotent: roles are matched on the natural key (`name`, `guard_name`) and their meta columns
 * are refreshed. Grants converge **additively** (D65): a role this run creates receives exactly the
 * seeded grant; a role that already exists receives only the seeded permissions it is missing, and
 * nothing an administrator granted in the role editor is ever revoked. Nothing is ever deleted.
 *
 * Super Admin additionally holds every permission in the registry — belt and braces next to
 * the `Gate::before` short-circuit in AppServiceProvider.
 */
class RoleSeeder extends Seeder
{
    use WritesToConsole;

    /** Suffix that marks the non-admin portal permission prefixes in the registry. */
    private const PORTAL_SUFFIX = '_portal';

    /*
    |--------------------------------------------------------------------------
    | Ability bundles used when a role only needs part of a module
    |--------------------------------------------------------------------------
    */

    /** @var array<int, Ability> */
    private const READ = [Ability::ViewAny, Ability::View];

    /** @var array<int, Ability> */
    private const READ_EDIT = [Ability::ViewAny, Ability::View, Ability::Edit];

    /** @var array<int, Ability> */
    private const READ_CREATE = [Ability::ViewAny, Ability::View, Ability::Create];

    /** @var array<int, Ability> */
    private const READ_CREATE_EDIT = [Ability::ViewAny, Ability::View, Ability::Create, Ability::Edit];

    /** Read a module's list/detail and run its reports. @var array<int, Ability> */
    private const REPORTING = [
        Ability::ViewAny,
        Ability::View,
        Ability::ViewReports,
        Ability::Export,
        Ability::Print,
    ];

    /** Reporting plus the money figures. @var array<int, Ability> */
    private const FINANCIAL_REPORTING = [...self::REPORTING, Ability::ViewFinancial];

    /** What a doer needs on work assigned to them. @var array<int, Ability> */
    private const WORK_ON = [
        Ability::ViewAny,
        Ability::View,
        Ability::Edit,
        Ability::ChangeStatus,
        Ability::Upload,
        Ability::Download,
    ];

    /** Log and maintain own entries. @var array<int, Ability> */
    private const OWN_ENTRIES = [Ability::ViewAny, Ability::View, Ability::Create, Ability::Edit];

    /** Share files. @var array<int, Ability> */
    private const FILE_WORK = [
        Ability::ViewAny,
        Ability::View,
        Ability::Create,
        Ability::Upload,
        Ability::Download,
    ];

    public function run(): void
    {
        $guard = $this->guardName();
        $definitions = $this->roleDefinitions();

        DB::transaction(function () use ($guard, $definitions): void {
            $created = 0;
            $granted = 0;
            $rows = [];

            foreach ($definitions as $definition) {
                $role = Role::query()->firstOrNew([
                    'name' => $definition['name'],
                    'guard_name' => $guard,
                ]);

                $existed = $role->exists;

                $role->fill([
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'panel' => $definition['panel'],
                    'level' => $definition['level'],
                    'is_system' => $definition['is_system'],
                    'is_default' => $definition['is_default'],
                ]);

                $role->save();

                if (! $existed) {
                    // A fresh install gets exactly the seeded grant (phase-01 §5).
                    $role->syncPermissions($definition['permissions']);
                    $created++;
                } else {
                    // D65: converge additively on an existing role. Grant what the registry now adds
                    // (a later phase's new permissions), never revoke what an administrator granted.
                    $held = $role->permissions()->pluck('name')->map(static fn (mixed $name): string => (string) $name)->all();
                    $missing = array_values(array_diff($definition['permissions'], $held));

                    if ($missing !== []) {
                        $role->givePermissionTo($missing);
                        $granted += count($missing);
                    }
                }

                $rows[] = [
                    $definition['name'],
                    $definition['panel']->value,
                    (string) $definition['level'],
                    $definition['is_system'] ? 'yes' : 'no',
                    (string) count($definition['permissions']),
                ];
            }

            $this->seedInfo(sprintf(
                'Roles: %d seeded (%d created), %d missing permission grant(s) added, none revoked.',
                count($definitions),
                $created,
                $granted,
            ));

            $this->seedTable(['Role', 'Panel', 'Level', 'System', 'Permissions'], $rows);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The 18 roles of phase-01 §5, in contract order.
     *
     * @return array<int, array{
     *     name: string,
     *     label: string,
     *     description: string,
     *     panel: PanelType,
     *     level: int,
     *     is_system: bool,
     *     is_default: bool,
     *     permissions: array<int, string>
     * }>
     */
    private function roleDefinitions(): array
    {
        // Every staff role needs the dashboard and the global search box.
        // phase-07 §4.4: everybody who works here has their own attendance, leave and slips, so the HR
        // self-service window belongs beside the dashboard rather than in ten separate role definitions.
        $staffBase = PermissionRegistry::permissionNamesFor([
            'dashboard',
            'global_search',
            'employee_self_service',
        ]);

        return [
            [
                'name' => User::SUPER_ADMIN_ROLE,
                'label' => 'Super Admin',
                'description' => 'Unrestricted access to every module, setting and audit trail.',
                'panel' => PanelType::Admin,
                'level' => 1,
                'is_system' => true,
                'is_default' => false,
                'permissions' => PermissionRegistry::permissionNames(),
            ],
            [
                'name' => 'Admin',
                'label' => 'Admin',
                'description' => 'Day-to-day administration of every module except module toggles, backups, role deletion and the SMTP credentials.',
                'panel' => PanelType::Admin,
                'level' => 5,
                'is_system' => true,
                'is_default' => false,
                'permissions' => $this->everythingExcept(
                    PermissionRegistry::permissionNamesFor(['modules', 'backups']),
                    PermissionRegistry::permissionNamesFor('roles', [Ability::Delete]),
                    // phase-02 §3 / §6 "Mail test": the mail group is Super-Admin-only. Whoever holds
                    // the SMTP credentials can receive every password-reset mail in the system, so
                    // Admin keeps `settings.edit` for the other twelve groups and is withheld this.
                    PermissionRegistry::permissionNamesFor('settings', ['edit_mail']),
                ),
            ],
            [
                'name' => 'HR',
                'label' => 'Human Resources',
                'description' => 'Employees, departments, attendance, leave, payroll and hiring, with HR reporting.',
                'panel' => PanelType::Admin,
                'level' => 20,
                'is_system' => false,
                'is_default' => false,
                'permissions' => $this->merge(
                    $staffBase,
                    PermissionRegistry::permissionNamesFor([
                        'employees',
                        'departments',
                        'attendance',
                        'leaves',
                        'payroll',
                        // phase-07 §4.4: HR owns every module this phase adds, including payroll.approve
                        // (the lock) and every money ability.
                        'designations',
                        'employee_documents',
                        'work_shifts',
                        'holidays',
                        'leave_types',
                        'leave_balances',
                        'salary_components',
                        'salary_structures',
                        'salary_slips',
                        'employee_advances',
                        'employee_self_service',
                    ]),
                    // phase-04 §9.1 / §13 (H7): hiring sits with HR — every opening and every application, CVs included.
                    PermissionRegistry::permissionNamesFor(['jobs', 'job_applications']),
                    PermissionRegistry::permissionNamesFor('reports', self::REPORTING),
                ),
            ],
            [
                'name' => 'Accountant',
                'label' => 'Accountant',
                'description' => 'Invoices, payments, expenses, income, student fees, installments and collaborator payouts, including money figures.',
                'panel' => PanelType::Admin,
                'level' => 20,
                'is_system' => false,
                'is_default' => false,
                'permissions' => $this->merge(
                    $staffBase,
                    PermissionRegistry::permissionNamesFor([
                        'invoices',
                        'payments',
                        'expenses',
                        'income',
                        'student_fees',
                        'installments',
                        'collaborator_payouts',
                    ]),
                    // phase-07 §4.4. Deliberately **not** payroll.approve: whoever locks a run is not
                    // whoever pays it, and collapsing the two would let one person decide and disburse.
                    PermissionRegistry::permissionNamesFor('payroll', $this->abilitiesExcept('payroll', [
                        'approve', 'reject', 'create', 'edit', 'delete', 'restore',
                    ])),
                    PermissionRegistry::permissionNamesFor('salary_slips'),
                    PermissionRegistry::permissionNamesFor('employee_advances', self::READ_MONEY),
                    PermissionRegistry::permissionNamesFor('employees', self::READ_MONEY),
                    PermissionRegistry::permissionNamesFor('reports', self::FINANCIAL_REPORTING),
                    PermissionRegistry::permissionNamesFor('website_media', [Ability::ViewAny, Ability::View, Ability::Upload]),
                    // phase-08-09 §4.3: whoever pays a partner registers and verifies where the money
                    // goes. No ability on this module reveals the encrypted details — only the last four
                    // digits are ever rendered (INV-C6).
                    PermissionRegistry::permissionNamesFor('collaborator_payout_accounts'),
                    // phase-10-12 §4.1: the four money modules the spine adds. `project_payments`
                    // carries `link_invoice`, and the Accountant is the **only** seeded role that
                    // receives it — it is the role that already holds `invoices.edit` and records
                    // project receipts, and the route demands both together (D43). Widening it later is
                    // an explicit, audited role edit, never a preset change.
                    PermissionRegistry::permissionNamesFor([
                        'student_fee_payments',
                        'project_payments',
                        'payment_reversals',
                        'wallet_reconciliation',
                    ]),
                ),
            ],
            [
                'name' => 'Project Manager',
                'label' => 'Project Manager',
                'description' => 'Owns projects, milestones, tasks and time tracking; reads clients and leads.',
                'panel' => PanelType::Admin,
                'level' => 20,
                'is_system' => false,
                'is_default' => false,
                'permissions' => $this->merge(
                    $staffBase,
                    PermissionRegistry::permissionNamesFor([
                        'projects',
                        'project_milestones',
                        'tasks',
                        'time_tracking',
                        // phase-06 §4.1: discussing a task is its own slug, so a role can comment without
                        // holding tasks.edit.
                        'task_comments',
                        'meetings',
                        'files',
                    ]),
                    PermissionRegistry::permissionNamesFor(['clients', 'leads'], self::READ),
                ),
            ],
            [
                'name' => 'Developer',
                'label' => 'Developer',
                'description' => 'Works on assigned projects and tasks, logs time and shares files.',
                'panel' => PanelType::Admin,
                'level' => 40,
                'is_system' => false,
                'is_default' => false,
                'permissions' => $this->merge($staffBase, $this->deliveryTeamGrant()),
            ],
            [
                'name' => 'Designer',
                'label' => 'Designer',
                'description' => 'Works on assigned projects and tasks, logs time and shares files.',
                'panel' => PanelType::Admin,
                'level' => 40,
                'is_system' => false,
                'is_default' => false,
                'permissions' => $this->merge($staffBase, $this->deliveryTeamGrant()),
            ],
            [
                'name' => 'SEO Expert',
                'label' => 'SEO Expert',
                'description' => 'Blog, SEO metadata, website section and page copy and the media library, plus own tasks.',
                'panel' => PanelType::Admin,
                'level' => 40,
                'is_system' => false,
                'is_default' => false,
                'permissions' => $this->merge(
                    $staffBase,
                    // phase-04 §9.1 / §13: an author, not an editor — never blog_posts.approve; the catalogue copy read/edit;
                    // never job_applications or contact_inquiries (F-12.4).
                    PermissionRegistry::permissionNamesFor('blog_posts', $this->abilitiesExcept('blog_posts', ['approve', 'reject'])),
                    PermissionRegistry::permissionNamesFor(['blog_categories', 'blog_tags', 'seo']),
                    PermissionRegistry::permissionNamesFor(['services', 'portfolio', 'team'], self::READ_EDIT),
                    PermissionRegistry::permissionNamesFor('website_sections', self::READ_EDIT),
                    // phase-03 §9: page copy and the whole media library. Never *.change_status: an SEO
                    // edit goes live when someone with publish rights publishes it.
                    PermissionRegistry::permissionNamesFor('pages', self::READ_EDIT),
                    PermissionRegistry::permissionNamesFor('website_media'),
                    PermissionRegistry::permissionNamesFor('tasks', self::WORK_ON),
                    // phase-06 §4.3 lists the SEO Expert with the delivery team for task comments.
                    PermissionRegistry::permissionNamesFor('task_comments'),
                ),
            ],
            [
                'name' => 'Digital Marketer',
                'label' => 'Digital Marketer',
                'description' => 'Leads, blog, website sections, CTA blocks, FAQs, media uploads and course inquiries.',
                'panel' => PanelType::Admin,
                'level' => 40,
                'is_system' => false,
                'is_default' => false,
                'permissions' => $this->merge(
                    $staffBase,
                    PermissionRegistry::permissionNamesFor(['leads', 'blog_categories', 'blog_tags', 'course_inquiries']),
                    // phase-04 §9.1: author-level blog; inquiries assigned to it only — no view_any, no view_logs (F-12.4).
                    PermissionRegistry::permissionNamesFor('blog_posts', $this->abilitiesExcept('blog_posts', ['approve', 'reject'])),
                    PermissionRegistry::permissionNamesFor('contact_inquiries', [Ability::View, Ability::Edit, Ability::ChangeStatus]),
                    PermissionRegistry::permissionNamesFor('website_sections', self::READ_EDIT),
                    // phase-03 §9: CTA blocks and FAQs in full; media view + upload only. No pages, no
                    // seo.edit, no publish.
                    PermissionRegistry::permissionNamesFor(['website_cta_blocks', 'faqs']),
                    PermissionRegistry::permissionNamesFor('website_media', [Ability::ViewAny, Ability::View, Ability::Upload]),
                ),
            ],
            [
                'name' => 'Sales Executive',
                'label' => 'Sales Executive',
                'description' => 'Full lead pipeline plus client onboarding, course inquiries, demo classes and meetings.',
                'panel' => PanelType::Admin,
                'level' => 30,
                'is_system' => false,
                'is_default' => false,
                'permissions' => $this->merge(
                    $staffBase,
                    PermissionRegistry::permissionNamesFor('leads'),
                    PermissionRegistry::permissionNamesFor('clients', self::READ_CREATE_EDIT),
                    PermissionRegistry::permissionNamesFor(['course_inquiries', 'demo_classes', 'meetings']),
                    // phase-04 §9.1.2: its own assigned inquiries, and it may hand one on.
                    PermissionRegistry::permissionNamesFor('contact_inquiries', [Ability::View, Ability::Assign]),
                    // phase-08-09 §4.3: enough to pick the right partner when entering a lead, and
                    // deliberately **not** `collaborators.view_financial` — what a partner earns is
                    // nobody's business on the sales floor.
                    PermissionRegistry::permissionNamesFor('collaborators', self::READ),
                    PermissionRegistry::permissionNamesFor('collaborator_referrals', [Ability::Create]),
                ),
            ],
            [
                'name' => 'Receptionist',
                'label' => 'Receptionist',
                'description' => 'Front desk: course inquiries, admissions, demo classes, student records and fee collection.',
                'panel' => PanelType::Admin,
                'level' => 35,
                'is_system' => false,
                'is_default' => false,
                'permissions' => $this->merge(
                    $staffBase,
                    PermissionRegistry::permissionNamesFor(['course_inquiries', 'admissions', 'demo_classes']),
                    PermissionRegistry::permissionNamesFor('students', self::READ_CREATE_EDIT),
                    PermissionRegistry::permissionNamesFor('student_fees', self::READ_CREATE),
                    // phase-04 §9.1.2: view the inquiries assigned to the front desk.
                    PermissionRegistry::permissionNamesFor('contact_inquiries', [Ability::View]),
                    // phase-08-09 §4.3: the front desk picks the referring partner at admission, and
                    // that manual pick outranks every captured code (INV-R3). No `view_financial`.
                    PermissionRegistry::permissionNamesFor('collaborators', self::READ),
                    PermissionRegistry::permissionNamesFor('collaborator_referrals', [Ability::Create]),
                    // phase-10-12 §4.1: the front desk takes fee money and prints the receipt. It does
                    // **not** get `change_status` — voiding a receipt is an accounting decision — and
                    // there is no `edit` on the module at all, for anybody (INV-8).
                    PermissionRegistry::permissionNamesFor('student_fee_payments', [
                        Ability::ViewAny, Ability::View, Ability::Create, Ability::Print,
                    ]),
                ),
            ],
            [
                'name' => 'Support Agent',
                'label' => 'Support Agent',
                'description' => 'Support tickets, messages and meetings.',
                'panel' => PanelType::Admin,
                'level' => 35,
                'is_system' => false,
                'is_default' => false,
                'permissions' => $this->merge(
                    $staffBase,
                    PermissionRegistry::permissionNamesFor(['support_tickets', 'messages', 'meetings']),
                ),
            ],
            [
                'name' => 'Institute Manager',
                'label' => 'Institute Manager',
                'description' => 'Runs the training institute: every institute module plus institute reporting.',
                'panel' => PanelType::Admin,
                'level' => 15,
                'is_system' => false,
                'is_default' => false,
                'permissions' => $this->merge(
                    $staffBase,
                    PermissionRegistry::permissionNamesFor($this->businessModulesIn(ModuleGroup::Institute)),
                    PermissionRegistry::permissionNamesFor('reports', self::FINANCIAL_REPORTING),
                    // phase-04 §13 (Q2): the blog editor role beside Admin.
                    PermissionRegistry::permissionNamesFor(['blog_posts', 'blog_categories', 'blog_tags']),
                ),
            ],
            [
                'name' => 'Course Coordinator',
                'label' => 'Course Coordinator',
                'description' => 'Courses, outlines, batches, timetable, materials and student attendance.',
                'panel' => PanelType::Admin,
                'level' => 25,
                'is_system' => false,
                'is_default' => false,
                'permissions' => $this->merge(
                    $staffBase,
                    PermissionRegistry::permissionNamesFor([
                        'courses',
                        'course_outline',
                        'course_materials',
                        'batches',
                        'timetable',
                        'student_attendance',
                    ]),
                    PermissionRegistry::permissionNamesFor('students', self::READ_EDIT),
                ),
            ],
            [
                'name' => 'Teacher',
                'label' => 'Teacher',
                'description' => 'Teacher portal: batches, attendance, materials, assignments, exams and results.',
                'panel' => PanelType::Teacher,
                'level' => 50,
                'is_system' => false,
                'is_default' => true,
                'permissions' => PermissionRegistry::permissionNamesFor('teacher_portal'),
            ],
            [
                'name' => 'Student',
                'label' => 'Student',
                'description' => 'Student portal: courses, attendance, assignments, results, fees and certificates.',
                'panel' => PanelType::Student,
                'level' => 60,
                'is_system' => false,
                'is_default' => true,
                'permissions' => PermissionRegistry::permissionNamesFor('student_portal'),
            ],
            [
                'name' => 'Client',
                'label' => 'Client',
                'description' => 'Client portal: projects, milestones, invoices, payments, files and messages.',
                'panel' => PanelType::Client,
                'level' => 60,
                'is_system' => false,
                'is_default' => true,
                'permissions' => PermissionRegistry::permissionNamesFor('client_portal'),
            ],
            [
                'name' => 'Collaborator',
                'label' => 'Collaborator',
                'description' => 'Collaborator portal: referred students and projects, commissions, wallet and statements.',
                'panel' => PanelType::Collaborator,
                'level' => 60,
                'is_system' => false,
                'is_default' => true,
                // Requesting a payout is an opt-in the administrator grants (collaborator.payout_request_enabled).
                'permissions' => PermissionRegistry::permissionNamesFor(
                    'collaborator_portal',
                    $this->abilitiesExcept('collaborator_portal', ['payout_request']),
                ),
            ],
        ];
    }

    /**
     * The shared grant for Developer and Designer: read the projects they are on, move their
     * tasks forward, log time, exchange files and attend meetings.
     *
     * @return array<int, string>
     */
    /**
     * Read a module, money included, and write nothing (phase-07 §4.4's Accountant shape).
     *
     * @var list<string>
     */
    private const READ_MONEY = ['view_any', 'view', 'view_financial'];

    private function deliveryTeamGrant(): array
    {
        return $this->merge(
            PermissionRegistry::permissionNamesFor(['projects', 'project_milestones'], self::READ),
            PermissionRegistry::permissionNamesFor('tasks', self::WORK_ON),
            // phase-06 §4.3: the delivery team may hold a whole conversation on a task.
            PermissionRegistry::permissionNamesFor('task_comments'),
            PermissionRegistry::permissionNamesFor('time_tracking', self::OWN_ENTRIES),
            PermissionRegistry::permissionNamesFor('files', self::FILE_WORK),
            PermissionRegistry::permissionNamesFor('meetings', self::READ),
        );
    }

    /**
     * Every declared permission minus the given sets.
     *
     * @param  array<int, string>  ...$excluded
     * @return array<int, string>
     */
    private function everythingExcept(array ...$excluded): array
    {
        return array_values(array_diff(
            PermissionRegistry::permissionNames(),
            $this->merge(...$excluded),
        ));
    }

    /**
     * Real business modules of a group — the portal permission prefixes are namespaces for the
     * non-admin panels, not modules an admin-side role should receive.
     *
     * @return array<int, string>
     */
    private function businessModulesIn(ModuleGroup $group): array
    {
        $slugs = [];

        foreach (PermissionRegistry::modules() as $slug => $definition) {
            if ($definition['group'] !== $group) {
                continue;
            }

            if (str_ends_with((string) $slug, self::PORTAL_SUFFIX)) {
                continue;
            }

            $slugs[] = (string) $slug;
        }

        return $slugs;
    }

    /**
     * A module's declared abilities minus a few ability values.
     *
     * Keeps grants registry-driven: only the ability value is named here, never a whole
     * permission string.
     *
     * @param  array<int, string>  $except
     * @return array<int, string>
     */
    private function abilitiesExcept(string $module, array $except): array
    {
        return array_values(array_filter(
            PermissionRegistry::abilityValuesFor($module),
            static fn (string $ability): bool => ! in_array($ability, $except, true),
        ));
    }

    /**
     * Flatten permission-name lists, preserving order and dropping duplicates.
     *
     * @param  array<int, string>  ...$sets
     * @return array<int, string>
     */
    private function merge(array ...$sets): array
    {
        $names = [];

        foreach ($sets as $set) {
            foreach ($set as $name) {
                $names[(string) $name] = true;
            }
        }

        return array_keys($names);
    }

    private function guardName(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }
}
