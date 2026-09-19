<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Ability;
use App\Enums\ModuleGroup;
use InvalidArgumentException;

/**
 * The single source of truth for modules and permissions.
 *
 * Pure arrays: no database, no cache, no facades, no container. Everything that needs to know
 * which permissions exist (ModuleSeeder, PermissionSeeder, RoleSeeder, the role editor, the
 * permissions index, Modules::permissionModuleMap()) reads it from here.
 *
 * Naming contract (phase-01 §4):
 *   permission name  = "{module slug}.{ability}"
 *   permission label = "{Ability label} {Module name}"   e.g. "View Any Projects"
 *
 * The ability half of a label comes from Ability::label(), so the wording lives in exactly one
 * place. The portal prefixes declare plain-string abilities (`student_fee_status`) rather than
 * Ability cases, and those are humanised from the value itself.
 */
final class PermissionRegistry
{
    /*
    |--------------------------------------------------------------------------
    | Ability presets (phase-01 §4)
    |--------------------------------------------------------------------------
    */

    private const READ = [
        Ability::ViewAny,
        Ability::View,
    ];

    private const CRUD = [
        Ability::ViewAny,
        Ability::View,
        Ability::Create,
        Ability::Edit,
        Ability::Delete,
    ];

    private const CRUD_FULL = [
        ...self::CRUD,
        Ability::Export,
        Ability::Print,
    ];

    private const APPROVE = [
        Ability::Approve,
        Ability::Reject,
    ];

    private const STATUS = [
        Ability::ChangeStatus,
    ];

    private const ASSIGN = [
        Ability::Assign,
    ];

    private const FILES = [
        Ability::Upload,
        Ability::Download,
    ];

    private const MONEY = [
        Ability::ViewFinancial,
    ];

    private const REPORTS = [
        Ability::ViewReports,
        Ability::Export,
        Ability::Print,
    ];

    private const LOGS = [
        Ability::ViewLogs,
    ];

    /*
    |--------------------------------------------------------------------------
    | Extra single abilities that belong to no preset
    |--------------------------------------------------------------------------
    */

    private const RESTORE = [Ability::Restore];

    private const IMPORT = [Ability::Import];

    private const EDIT_ONLY = [Ability::Edit];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $modules = null;

    /** @var array<int, array<string, mixed>>|null */
    private static ?array $permissions = null;

    /**
     * Every module the system will ever have, keyed by slug.
     *
     * @return array<string, array{
     *     name: string,
     *     group: ModuleGroup,
     *     icon: string,
     *     is_core: bool,
     *     sort: int,
     *     abilities: array<int, Ability|string>,
     *     depends_on: list<string>
     * }>
     */
    public static function modules(): array
    {
        if (self::$modules !== null) {
            return self::$modules;
        }

        return self::$modules = self::withDependencies([

            /*
            |------------------------------------------------------------------
            | System — core, never disableable
            |------------------------------------------------------------------
            */

            'dashboard' => [
                'name' => 'Dashboard',
                'group' => ModuleGroup::System,
                'icon' => 'home',
                'is_core' => true,
                'sort' => 10,
                'abilities' => self::READ,
            ],
            'users' => [
                'name' => 'Users',
                'group' => ModuleGroup::System,
                'icon' => 'users',
                'is_core' => true,
                'sort' => 20,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::RESTORE),
            ],
            'roles' => [
                'name' => 'Roles',
                'group' => ModuleGroup::System,
                'icon' => 'shield-check',
                'is_core' => true,
                'sort' => 30,
                'abilities' => self::merge(self::CRUD, self::ASSIGN),
            ],
            'permissions' => [
                'name' => 'Permissions',
                'group' => ModuleGroup::System,
                'icon' => 'key',
                'is_core' => true,
                'sort' => 40,
                'abilities' => self::merge(self::READ, [Ability::Export]),
            ],
            'modules' => [
                'name' => 'Modules',
                'group' => ModuleGroup::System,
                'icon' => 'puzzle-piece',
                'is_core' => true,
                'sort' => 50,
                'abilities' => self::merge(self::READ, self::STATUS),
            ],
            'settings' => [
                'name' => 'Settings',
                'group' => ModuleGroup::System,
                'icon' => 'cog-6-tooth',
                'is_core' => true,
                'sort' => 60,
                // `edit_mail` is a narrowly-scoped ability for one guarded operation, exactly like
                // `project_payments.link_invoice` (D43): the SMTP credentials are the one setting
                // group whose owner can read every password-reset mail in the system, so writing
                // them and sending a test through them needs `settings.edit` *and* this. Never widen
                // it into `edit`. RoleSeeder withholds it from Admin, leaving it to Super Admin.
                'abilities' => self::merge(self::READ, self::EDIT_ONLY, [Ability::EditMail], self::FILES),
            ],
            'activity_log' => [
                'name' => 'Activity Log',
                'group' => ModuleGroup::System,
                'icon' => 'clipboard-document-list',
                'is_core' => true,
                'sort' => 70,
                'abilities' => self::merge(self::READ, self::LOGS, [Ability::Export]),
            ],
            'login_history' => [
                'name' => 'Login History',
                'group' => ModuleGroup::System,
                'icon' => 'finger-print',
                'is_core' => true,
                'sort' => 80,
                'abilities' => self::merge(self::READ, self::LOGS, [Ability::Export]),
            ],
            'backups' => [
                'name' => 'Backups',
                'group' => ModuleGroup::System,
                'icon' => 'server-stack',
                'is_core' => true,
                'sort' => 90,
                'abilities' => self::merge(self::READ, [Ability::Create, Ability::Delete, Ability::Download]),
            ],
            'global_search' => [
                'name' => 'Global Search',
                'group' => ModuleGroup::System,
                'icon' => 'magnifying-glass',
                'is_core' => true,
                'sort' => 100,
                'abilities' => self::READ,
            ],

            /*
            |------------------------------------------------------------------
            | Software house
            |------------------------------------------------------------------
            */

            'leads' => [
                'name' => 'Leads',
                'group' => ModuleGroup::SoftwareHouse,
                'icon' => 'megaphone',
                'is_core' => false,
                'sort' => 110,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::IMPORT, self::REPORTS, self::RESTORE),
            ],
            'clients' => [
                'name' => 'Clients',
                'group' => ModuleGroup::SoftwareHouse,
                'icon' => 'briefcase',
                'is_core' => false,
                'sort' => 120,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::FILES, self::MONEY, self::IMPORT, self::RESTORE),
            ],
            'projects' => [
                'name' => 'Projects',
                'group' => ModuleGroup::SoftwareHouse,
                'icon' => 'folder',
                'is_core' => false,
                'sort' => 130,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::FILES, self::MONEY, self::REPORTS, self::RESTORE),
            ],
            'project_milestones' => [
                'name' => 'Project Milestones',
                'group' => ModuleGroup::SoftwareHouse,
                'icon' => 'flag',
                'is_core' => false,
                'sort' => 140,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::ASSIGN, self::APPROVE, self::MONEY, self::RESTORE),
            ],
            'tasks' => [
                'name' => 'Tasks',
                'group' => ModuleGroup::SoftwareHouse,
                'icon' => 'check-circle',
                'is_core' => false,
                'sort' => 150,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::FILES, self::RESTORE),
            ],
            'time_tracking' => [
                'name' => 'Time Tracking',
                'group' => ModuleGroup::SoftwareHouse,
                'icon' => 'clock',
                'is_core' => false,
                'sort' => 160,
                'abilities' => self::merge(self::CRUD, self::APPROVE, self::REPORTS, self::RESTORE),
            ],

            /*
            |------------------------------------------------------------------
            | HR
            |------------------------------------------------------------------
            */

            'employees' => [
                'name' => 'Employees',
                'group' => ModuleGroup::Hr,
                'icon' => 'identification',
                'is_core' => false,
                'sort' => 210,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::FILES, self::MONEY, self::IMPORT, self::RESTORE),
            ],
            'departments' => [
                'name' => 'Departments',
                'group' => ModuleGroup::Hr,
                'icon' => 'building-office-2',
                'is_core' => false,
                'sort' => 220,
                'abilities' => self::merge(self::CRUD, self::RESTORE),
            ],
            'attendance' => [
                'name' => 'Attendance',
                'group' => ModuleGroup::Hr,
                'icon' => 'calendar-days',
                'is_core' => false,
                'sort' => 230,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::IMPORT, self::REPORTS, self::RESTORE),
            ],
            'leaves' => [
                'name' => 'Leaves',
                'group' => ModuleGroup::Hr,
                'icon' => 'calendar',
                'is_core' => false,
                'sort' => 240,
                'abilities' => self::merge(self::CRUD, self::APPROVE, self::STATUS, self::REPORTS, self::RESTORE),
            ],
            'payroll' => [
                'name' => 'Payroll',
                'group' => ModuleGroup::Hr,
                'icon' => 'banknotes',
                'is_core' => false,
                'sort' => 250,
                'abilities' => self::merge(self::CRUD, self::APPROVE, self::STATUS, self::MONEY, self::REPORTS, self::RESTORE),
            ],

            /*
            |------------------------------------------------------------------
            | Finance
            |------------------------------------------------------------------
            */

            'invoices' => [
                'name' => 'Invoices',
                'group' => ModuleGroup::Finance,
                'icon' => 'document-text',
                'is_core' => false,
                'sort' => 310,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::APPROVE, self::MONEY, self::FILES, self::REPORTS, self::RESTORE),
            ],
            'payments' => [
                'name' => 'Payments',
                'group' => ModuleGroup::Finance,
                'icon' => 'credit-card',
                'is_core' => false,
                'sort' => 320,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::APPROVE, self::MONEY, self::REPORTS, self::RESTORE),
            ],
            'expenses' => [
                'name' => 'Expenses',
                'group' => ModuleGroup::Finance,
                'icon' => 'receipt-percent',
                'is_core' => false,
                'sort' => 330,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::APPROVE, self::MONEY, self::FILES, self::REPORTS, self::RESTORE),
            ],
            'income' => [
                'name' => 'Income',
                'group' => ModuleGroup::Finance,
                'icon' => 'arrow-trending-up',
                'is_core' => false,
                'sort' => 340,
                'abilities' => self::merge(self::CRUD_FULL, self::MONEY, self::REPORTS, self::RESTORE),
            ],
            'payment_methods' => [
                'name' => 'Payment Methods',
                'group' => ModuleGroup::Finance,
                'icon' => 'wallet',
                'is_core' => false,
                'sort' => 350,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::RESTORE),
            ],

            /*
            |------------------------------------------------------------------
            | Collaborator
            |------------------------------------------------------------------
            */

            'collaborators' => [
                'name' => 'Collaborators',
                'group' => ModuleGroup::Collaborator,
                'icon' => 'user-group',
                'is_core' => false,
                'sort' => 410,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::FILES, self::MONEY, self::REPORTS, self::RESTORE),
            ],
            'collaborator_commission_settings' => [
                'name' => 'Collaborator Commission Settings',
                'group' => ModuleGroup::Collaborator,
                'icon' => 'adjustments-horizontal',
                'is_core' => false,
                'sort' => 420,
                'abilities' => self::merge(self::CRUD, self::MONEY, self::RESTORE),
            ],
            // Financial history is immutable: read, approve and report only — corrections are
            // reversing entries created by the commission engine, never edits or deletes.
            'collaborator_commissions' => [
                'name' => 'Collaborator Commissions',
                'group' => ModuleGroup::Collaborator,
                'icon' => 'calculator',
                'is_core' => false,
                'sort' => 430,
                'abilities' => self::merge(self::READ, self::APPROVE, self::STATUS, self::MONEY, self::REPORTS),
            ],
            'collaborator_wallets' => [
                'name' => 'Collaborator Wallets',
                'group' => ModuleGroup::Collaborator,
                'icon' => 'wallet',
                'is_core' => false,
                'sort' => 440,
                'abilities' => self::merge(self::READ, self::MONEY, self::REPORTS),
            ],
            'collaborator_payouts' => [
                'name' => 'Collaborator Payouts',
                'group' => ModuleGroup::Collaborator,
                'icon' => 'banknotes',
                'is_core' => false,
                'sort' => 450,
                'abilities' => self::merge(self::CRUD, self::APPROVE, self::STATUS, self::MONEY, self::FILES, self::REPORTS, self::RESTORE),
            ],
            'collaborator_referrals' => [
                'name' => 'Collaborator Referrals',
                'group' => ModuleGroup::Collaborator,
                'icon' => 'share',
                'is_core' => false,
                'sort' => 460,
                'abilities' => self::merge(self::READ, self::REPORTS),
            ],

            /*
            |------------------------------------------------------------------
            | Institute
            |------------------------------------------------------------------
            */

            'course_categories' => [
                'name' => 'Course Categories',
                'group' => ModuleGroup::Institute,
                'icon' => 'rectangle-stack',
                'is_core' => false,
                'sort' => 510,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::RESTORE),
            ],
            'courses' => [
                'name' => 'Courses',
                'group' => ModuleGroup::Institute,
                'icon' => 'academic-cap',
                'is_core' => false,
                'sort' => 520,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::MONEY, self::FILES, self::RESTORE),
            ],
            'course_outline' => [
                'name' => 'Course Outline',
                'group' => ModuleGroup::Institute,
                'icon' => 'list-bullet',
                'is_core' => false,
                'sort' => 530,
                'abilities' => self::merge(self::CRUD, self::RESTORE),
            ],
            'course_materials' => [
                'name' => 'Course Materials',
                'group' => ModuleGroup::Institute,
                'icon' => 'folder-open',
                'is_core' => false,
                'sort' => 540,
                'abilities' => self::merge(self::CRUD, self::FILES, self::RESTORE),
            ],
            'students' => [
                'name' => 'Students',
                'group' => ModuleGroup::Institute,
                'icon' => 'users',
                'is_core' => false,
                'sort' => 550,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::FILES, self::MONEY, self::IMPORT, self::REPORTS, self::RESTORE),
            ],
            'admissions' => [
                'name' => 'Admissions',
                'group' => ModuleGroup::Institute,
                'icon' => 'user-plus',
                'is_core' => false,
                'sort' => 560,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::APPROVE, self::MONEY, self::FILES, self::RESTORE),
            ],
            'course_inquiries' => [
                'name' => 'Course Inquiries',
                'group' => ModuleGroup::Institute,
                'icon' => 'question-mark-circle',
                'is_core' => false,
                'sort' => 570,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::RESTORE),
            ],
            'demo_classes' => [
                'name' => 'Demo Classes',
                'group' => ModuleGroup::Institute,
                'icon' => 'video-camera',
                'is_core' => false,
                'sort' => 580,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::ASSIGN, self::RESTORE),
            ],
            'teachers' => [
                'name' => 'Teachers',
                'group' => ModuleGroup::Institute,
                'icon' => 'presentation-chart-bar',
                'is_core' => false,
                'sort' => 590,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::FILES, self::MONEY, self::RESTORE),
            ],
            'batches' => [
                'name' => 'Batches',
                'group' => ModuleGroup::Institute,
                'icon' => 'squares-2x2',
                'is_core' => false,
                'sort' => 600,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::RESTORE),
            ],
            'timetable' => [
                'name' => 'Timetable',
                'group' => ModuleGroup::Institute,
                'icon' => 'table-cells',
                'is_core' => false,
                'sort' => 610,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::ASSIGN, [Ability::Export, Ability::Print], self::RESTORE),
            ],
            'student_attendance' => [
                'name' => 'Student Attendance',
                'group' => ModuleGroup::Institute,
                'icon' => 'clipboard-document-check',
                'is_core' => false,
                'sort' => 620,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::IMPORT, self::REPORTS, self::RESTORE),
            ],
            'student_progress' => [
                'name' => 'Student Progress',
                'group' => ModuleGroup::Institute,
                'icon' => 'chart-bar',
                'is_core' => false,
                'sort' => 630,
                'abilities' => self::merge(self::CRUD, self::REPORTS, self::RESTORE),
            ],
            'student_fees' => [
                'name' => 'Student Fees',
                'group' => ModuleGroup::Institute,
                'icon' => 'banknotes',
                'is_core' => false,
                'sort' => 640,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::APPROVE, self::MONEY, self::REPORTS, self::RESTORE),
            ],
            'installments' => [
                'name' => 'Installments',
                'group' => ModuleGroup::Institute,
                'icon' => 'queue-list',
                'is_core' => false,
                'sort' => 650,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::MONEY, self::REPORTS, self::RESTORE),
            ],
            'fee_discounts' => [
                'name' => 'Fee Discounts',
                'group' => ModuleGroup::Institute,
                'icon' => 'tag',
                'is_core' => false,
                'sort' => 660,
                'abilities' => self::merge(self::CRUD, self::APPROVE, self::STATUS, self::MONEY, self::RESTORE),
            ],
            'assignments' => [
                'name' => 'Assignments',
                'group' => ModuleGroup::Institute,
                'icon' => 'clipboard-document',
                'is_core' => false,
                'sort' => 670,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::FILES, self::RESTORE),
            ],
            'exams' => [
                'name' => 'Exams',
                'group' => ModuleGroup::Institute,
                'icon' => 'document-chart-bar',
                'is_core' => false,
                'sort' => 680,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::RESTORE),
            ],
            'results' => [
                'name' => 'Results',
                'group' => ModuleGroup::Institute,
                'icon' => 'trophy',
                'is_core' => false,
                'sort' => 690,
                'abilities' => self::merge(self::CRUD, self::APPROVE, self::STATUS, self::REPORTS, self::RESTORE),
            ],
            'certificates' => [
                'name' => 'Certificates',
                'group' => ModuleGroup::Institute,
                'icon' => 'check-badge',
                'is_core' => false,
                'sort' => 700,
                'abilities' => self::merge(self::CRUD, self::APPROVE, self::STATUS, self::FILES, [Ability::Export, Ability::Print], self::RESTORE),
            ],
            'student_id_cards' => [
                'name' => 'Student ID Cards',
                'group' => ModuleGroup::Institute,
                'icon' => 'identification',
                'is_core' => false,
                'sort' => 710,
                'abilities' => self::merge(self::CRUD, self::STATUS, [Ability::Export, Ability::Print], self::RESTORE),
            ],

            /*
            |------------------------------------------------------------------
            | Website
            |------------------------------------------------------------------
            */

            'website_sections' => [
                'name' => 'Website Sections',
                'group' => ModuleGroup::Website,
                'icon' => 'view-columns',
                'is_core' => false,
                'sort' => 810,
                // phase-03 §4.2: + LOGS (revision history is read under website_sections.view_logs). FILES and
                // RESTORE are Phase 1 grants that are already seeded; the registry only ever adds (D4).
                'abilities' => self::merge(self::CRUD, self::STATUS, self::FILES, self::RESTORE, self::LOGS),
            ],
            'menus' => [
                'name' => 'Menus',
                'group' => ModuleGroup::Website,
                'icon' => 'bars-3',
                'is_core' => false,
                'sort' => 820,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::RESTORE),
            ],
            'pages' => [
                'name' => 'Pages',
                'group' => ModuleGroup::Website,
                'icon' => 'document',
                'is_core' => false,
                'sort' => 830,
                // phase-03 §4.2: + LOGS. FILES and RESTORE are kept (additive, D4); `restore` backs
                // admin.website.pages.restore.
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::FILES, self::RESTORE, self::LOGS),
            ],
            'website_cta_blocks' => [
                'name' => 'CTA Blocks',
                'group' => ModuleGroup::Website,
                'icon' => 'megaphone',
                'is_core' => false,
                'sort' => 835,
                // phase-03 §4.1. `delete` is refused by CtaBlockPolicy while usage_count > 0.
                'abilities' => self::merge(self::CRUD, self::STATUS, self::LOGS),
            ],
            // phase-04 §4 (new slug). Sort sits in the Website range: the contract's 410 collides with
            // `collaborators` and PermissionRegistryTest::module_sort_orders_are_unique (§9 R-6).
            'service_categories' => [
                'name' => 'Service Categories',
                'group' => ModuleGroup::Website,
                'icon' => 'squares-2x2',
                'is_core' => false,
                'sort' => 838,
                'abilities' => self::merge(self::CRUD, self::STATUS),
            ],
            'services' => [
                'name' => 'Services',
                'group' => ModuleGroup::Website,
                'icon' => 'wrench-screwdriver',
                'is_core' => false,
                'sort' => 840,
                // phase-04 §4 pins CRUD_FULL + STATUS + FILES; export/print appended so existing rows keep their sort_order.
                'abilities' => self::merge(self::CRUD, self::STATUS, self::FILES, self::RESTORE, [Ability::Export, Ability::Print]),
            ],
            // phase-04 §4 (new slug).
            'technologies' => [
                'name' => 'Technologies',
                'group' => ModuleGroup::Website,
                'icon' => 'puzzle-piece',
                'is_core' => false,
                'sort' => 845,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::FILES),
            ],
            // phase-04 §4 (new slug).
            'portfolio_categories' => [
                'name' => 'Portfolio Categories',
                'group' => ModuleGroup::Website,
                'icon' => 'rectangle-stack',
                'is_core' => false,
                'sort' => 848,
                'abilities' => self::merge(self::CRUD, self::STATUS),
            ],
            'portfolio' => [
                'name' => 'Portfolio',
                'group' => ModuleGroup::Website,
                'icon' => 'photo',
                'is_core' => false,
                'sort' => 850,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::FILES, self::RESTORE, [Ability::Export, Ability::Print]),
            ],
            'team' => [
                'name' => 'Team',
                'group' => ModuleGroup::Website,
                'icon' => 'user-group',
                'is_core' => false,
                'sort' => 860,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::FILES, self::RESTORE, [Ability::Export, Ability::Print]),
            ],
            'testimonials' => [
                'name' => 'Testimonials',
                'group' => ModuleGroup::Website,
                'icon' => 'chat-bubble-left-right',
                'is_core' => false,
                'sort' => 870,
                // phase-04 §4: + FILES (the author / student photo).
                'abilities' => self::merge(self::CRUD, self::STATUS, self::APPROVE, self::RESTORE, self::FILES),
            ],
            'student_reviews' => [
                'name' => 'Student Reviews',
                'group' => ModuleGroup::Website,
                'icon' => 'star',
                'is_core' => false,
                'sort' => 880,
                // phase-04 §4: + FILES (the author / student photo).
                'abilities' => self::merge(self::CRUD, self::STATUS, self::APPROVE, self::RESTORE, self::FILES),
            ],
            'success_stories' => [
                'name' => 'Success Stories',
                'group' => ModuleGroup::Website,
                'icon' => 'sparkles',
                'is_core' => false,
                'sort' => 890,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::APPROVE, self::FILES, self::RESTORE),
            ],
            'faqs' => [
                'name' => 'FAQs',
                'group' => ModuleGroup::Website,
                'icon' => 'question-mark-circle',
                'is_core' => false,
                'sort' => 900,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::RESTORE),
            ],
            'faq_categories' => [
                'name' => 'FAQ Categories',
                'group' => ModuleGroup::Website,
                'icon' => 'rectangle-stack',
                'is_core' => false,
                'sort' => 905,
                // phase-03 §4.1: the same split as blog_categories / blog_posts.
                'abilities' => self::merge(self::CRUD, self::STATUS),
            ],
            'blog_categories' => [
                'name' => 'Blog Categories',
                'group' => ModuleGroup::Website,
                'icon' => 'tag',
                'is_core' => false,
                'sort' => 910,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::RESTORE),
            ],
            // phase-04 §4 (new slug).
            'blog_tags' => [
                'name' => 'Blog Tags',
                'group' => ModuleGroup::Website,
                'icon' => 'tag',
                'is_core' => false,
                'sort' => 915,
                'abilities' => self::merge(self::CRUD, self::STATUS),
            ],
            'blog_posts' => [
                'name' => 'Blog Posts',
                'group' => ModuleGroup::Website,
                'icon' => 'newspaper',
                'is_core' => false,
                'sort' => 920,
                // phase-04 §4: + REPORTS (only view_reports is new; export and print were already held).
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::APPROVE, self::ASSIGN, self::FILES, self::RESTORE, self::REPORTS),
            ],
            'jobs' => [
                'name' => 'Jobs',
                'group' => ModuleGroup::Website,
                'icon' => 'briefcase',
                'is_core' => false,
                'sort' => 930,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::APPROVE, self::RESTORE),
            ],
            'job_applications' => [
                'name' => 'Job Applications',
                'group' => ModuleGroup::Website,
                'icon' => 'inbox-stack',
                'is_core' => false,
                'sort' => 940,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::ASSIGN, self::FILES, [Ability::Export, Ability::Print], self::RESTORE),
            ],
            'contact_inquiries' => [
                'name' => 'Contact Inquiries',
                'group' => ModuleGroup::Website,
                'icon' => 'envelope',
                'is_core' => false,
                'sort' => 950,
                // phase-04 §4: + print, + view_logs (F-12.4 — the only key to the technical / PII columns).
                'abilities' => self::merge(self::CRUD, self::STATUS, self::ASSIGN, [Ability::Export], self::RESTORE, [Ability::Print], self::LOGS),
            ],
            'seo' => [
                'name' => 'SEO',
                'group' => ModuleGroup::Website,
                'icon' => 'globe-alt',
                'is_core' => false,
                'sort' => 960,
                // phase-03 §4.2: READ + edit + export + LOGS. No create/delete: a seo_meta row is an attribute
                // of its target. IMPORT is a Phase 1 grant that is already seeded (additive, D4).
                'abilities' => self::merge(self::READ, self::EDIT_ONLY, self::IMPORT, [Ability::Export], self::LOGS),
            ],
            'website_media' => [
                'name' => 'Media Library',
                'group' => ModuleGroup::Website,
                'icon' => 'photo',
                'is_core' => false,
                'sort' => 970,
                // phase-03 §4.1: the shared CMS image library (D24). `edit` = alt text, title and caption only.
                'abilities' => self::merge(self::READ, [Ability::Create, Ability::Edit, Ability::Delete], self::FILES, self::LOGS),
            ],

            /*
            |------------------------------------------------------------------
            | Shared
            |------------------------------------------------------------------
            */

            'support_tickets' => [
                'name' => 'Support Tickets',
                'group' => ModuleGroup::Shared,
                'icon' => 'lifebuoy',
                'is_core' => false,
                'sort' => 1010,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::FILES, self::RESTORE),
            ],
            'meetings' => [
                'name' => 'Meetings',
                'group' => ModuleGroup::Shared,
                'icon' => 'video-camera',
                'is_core' => false,
                'sort' => 1020,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::RESTORE),
            ],
            'messages' => [
                'name' => 'Messages',
                'group' => ModuleGroup::Shared,
                'icon' => 'chat-bubble-left-ellipsis',
                'is_core' => false,
                'sort' => 1030,
                'abilities' => self::merge(self::CRUD, self::FILES, self::RESTORE),
            ],
            'files' => [
                'name' => 'Files',
                'group' => ModuleGroup::Shared,
                'icon' => 'paper-clip',
                'is_core' => false,
                'sort' => 1040,
                'abilities' => self::merge(self::CRUD, self::FILES, self::RESTORE),
            ],
            'notifications' => [
                'name' => 'Notifications',
                'group' => ModuleGroup::Shared,
                'icon' => 'bell',
                'is_core' => false,
                'sort' => 1050,
                'abilities' => self::merge(self::CRUD, self::STATUS),
            ],
            'reports' => [
                'name' => 'Reports',
                'group' => ModuleGroup::Shared,
                'icon' => 'chart-pie',
                'is_core' => false,
                'sort' => 1060,
                'abilities' => self::merge(self::READ, self::REPORTS, self::MONEY),
            ],

            /*
            |------------------------------------------------------------------
            | Portal permission prefixes (phase-01 §4, spec §59)
            |--------------------------------------------------------------------
            | These are permission namespaces for the non-admin panels, not CRUD
            | modules, so their abilities are plain strings rather than Ability
            | cases. Panel entry itself is gated by the owning business module
            | (e.g. the collaborator panel carries `module:collaborators`).
            |
            | They are `is_core` for the same reason the System group is: a panel
            | is structure, not a feature. Because `Gate::before` denies every
            | ability of a disabled non-core module, one click on a
            | "Student Portal" switch would otherwise deny `student_portal.*` to
            | everyone — Super Admin included — and every student would get a
            | bare 403 on their own dashboard with nothing naming the cause.
            | Core makes `Modules::isCore()` short-circuit them, so the modules
            | screen renders them locked; their permissions are seeded and
            | granted exactly as before. Switching a *panel* off is a matter for
            | its owning business module (`module:collaborators`) or for the
            | roles that hold its permissions.
            */

            'collaborator_portal' => [
                'name' => 'Collaborator Portal',
                'group' => ModuleGroup::Collaborator,
                'icon' => 'user-group',
                'is_core' => true,
                'sort' => 1110,
                'abilities' => [
                    'dashboard',
                    'students',
                    'student_fee_status',
                    'student_commission',
                    'projects',
                    'project_client',
                    'project_value',
                    'project_payments',
                    'project_commission',
                    'tasks',
                    'tasks_update',
                    'files_upload',
                    'files_download',
                    'comments',
                    'meetings',
                    'messages',
                    'payout_request',
                    'statement_download',
                ],
            ],
            'student_portal' => [
                'name' => 'Student Portal',
                'group' => ModuleGroup::Institute,
                'icon' => 'academic-cap',
                'is_core' => true,
                'sort' => 1120,
                'abilities' => [
                    'dashboard',
                    'profile',
                    'courses',
                    'batches',
                    'timetable',
                    'attendance',
                    'materials',
                    'assignments',
                    'assignment_submit',
                    'exams',
                    'results',
                    'progress',
                    'certificates',
                    'fees',
                    'installments',
                    'payments',
                    'meetings',
                    'messages',
                    'support_tickets',
                    'reviews',
                    'files_download',
                    'notifications',
                ],
            ],
            'teacher_portal' => [
                'name' => 'Teacher Portal',
                'group' => ModuleGroup::Institute,
                'icon' => 'presentation-chart-bar',
                'is_core' => true,
                'sort' => 1130,
                'abilities' => [
                    'dashboard',
                    'profile',
                    'batches',
                    'students',
                    'timetable',
                    'attendance',
                    'attendance_mark',
                    'course_outline',
                    'materials',
                    'materials_upload',
                    'assignments',
                    'assignment_grade',
                    'exams',
                    'results',
                    'results_entry',
                    'student_progress',
                    'meetings',
                    'messages',
                    'support_tickets',
                    'files_download',
                    'notifications',
                ],
            ],
            'client_portal' => [
                'name' => 'Client Portal',
                'group' => ModuleGroup::SoftwareHouse,
                'icon' => 'briefcase',
                'is_core' => true,
                'sort' => 1140,
                'abilities' => [
                    'dashboard',
                    'profile',
                    'projects',
                    'project_milestones',
                    'project_tasks',
                    'project_progress',
                    'invoices',
                    'payments',
                    'comments',
                    'meetings',
                    'messages',
                    'support_tickets',
                    'files_upload',
                    'files_download',
                    'notifications',
                    'statement_download',
                ],
            ],
        ]);
    }

    /**
     * The module dependency graph: module slug => the slugs it cannot work without
     * (phase-02 §1 `modules.depends_on`, §3 dependency rules).
     *
     * **This is the single declaration site** — the same file that declares the modules, so adding
     * a module and declaring what it needs happen in one place (CLAUDE.md §4 "Adding a module").
     * `ModuleSeeder` projects it onto `modules.depends_on` through
     * `ModuleService::syncDependencyGraph()`; every runtime read goes through that stored column.
     *
     * Every edge is implied by a contract or a recorded decision — nothing is invented, and a module
     * whose screens merely *mention* another module is not a dependency (a dependency means "the
     * dependent's own rows point at the other module's rows, so its screens are meaningless while
     * that module is off"):
     *
     *   · software house (phase-05 / phase-06): a project belongs to a client; a milestone and a
     *     task belong to a project; elapsed time is recorded against a task (D33).
     *   · HR (phase-07): attendance, leave and payroll are all per employee.
     *   · finance (phase-13): an invoice is issued to a client; a received payment belongs to a
     *     project. The reverse is deliberately absent — `invoices.paid_amount` is a cache over the
     *     payments (D40) and a payment may exist with no invoice at all (D43).
     *   · collaborator spine (phase-08 … phase-12): every collaborator_* module hangs off
     *     `collaborators`; the commission engine cannot run without an effective rule version
     *     (CLAUDE.md §5 guard sequence); a wallet balance is a cache of the commission ledger; a
     *     payout allocates named ledger entries through the wallet (D18).
     *   · institute (phase-14 … phase-21): a course sits in a category; outline, materials,
     *     inquiries, demo classes and batches are per course; an admission enrols a student on a
     *     course (D45); a timetable rule belongs to a batch (D46); attendance and progress are per
     *     student; a fee charge follows an admission; installments and discounts belong to a fee
     *     charge (D49); assignments and exams are set for a batch; a result belongs to an exam; a
     *     certificate and an ID card are issued to a student (D52).
     *   · website (phase-03 / phase-04): a post sits in a blog category; an application answers a
     *     job opening.
     *
     * @var array<string, list<string>>
     */
    private const DEPENDS_ON = [
        // Software house — phase-05, phase-06.
        'projects' => ['clients'],
        'project_milestones' => ['projects'],
        'tasks' => ['projects'],
        'time_tracking' => ['tasks'],

        // HR — phase-07.
        'attendance' => ['employees'],
        'leaves' => ['employees'],
        'payroll' => ['employees'],

        // Finance — phase-13.
        'invoices' => ['clients'],
        'payments' => ['projects'],

        // Collaborator spine — phase-08 … phase-12.
        'collaborator_commission_settings' => ['collaborators'],
        'collaborator_commissions' => ['collaborators', 'collaborator_commission_settings'],
        'collaborator_wallets' => ['collaborators', 'collaborator_commissions'],
        'collaborator_payouts' => ['collaborators', 'collaborator_wallets'],
        'collaborator_referrals' => ['collaborators'],

        // Institute — phase-14 … phase-21.
        'courses' => ['course_categories'],
        'course_outline' => ['courses'],
        'course_materials' => ['courses'],
        'course_inquiries' => ['courses'],
        'demo_classes' => ['courses'],
        'admissions' => ['courses', 'students'],
        'batches' => ['courses'],
        'timetable' => ['batches'],
        'student_attendance' => ['students', 'batches'],
        'student_progress' => ['students', 'courses'],
        'student_fees' => ['admissions'],
        'installments' => ['student_fees'],
        'fee_discounts' => ['student_fees'],
        'assignments' => ['batches'],
        'exams' => ['batches'],
        'results' => ['exams'],
        'certificates' => ['students', 'courses'],
        'student_id_cards' => ['students'],

        // Website — phase-03, phase-04. Phase 3 adds no edge: faqs.faq_category_id is nullable (the
        // uncategorised bucket is supported), and a section's menu, CTA block and images are optional
        // references whose published snapshot keeps rendering while that module is off (INV-15).
        'blog_posts' => ['blog_categories'],
        'job_applications' => ['jobs'],
    ];

    /**
     * Attach each module's declared `depends_on` list to its definition, keeping only edges between
     * registered modules (a typo can never block anybody's switch).
     *
     * @param  array<string, array<string, mixed>>  $modules
     * @return array<string, array<string, mixed>>
     */
    private static function withDependencies(array $modules): array
    {
        foreach ($modules as $slug => $definition) {
            $clean = [];

            foreach (self::DEPENDS_ON[$slug] ?? [] as $dependency) {
                if ($dependency !== $slug && array_key_exists($dependency, $modules) && ! in_array($dependency, $clean, true)) {
                    $clean[] = $dependency;
                }
            }

            $modules[$slug]['depends_on'] = $clean;
        }

        return $modules;
    }

    /**
     * The declared dependency graph, only for modules that declare at least one dependency.
     *
     * @return array<string, list<string>>
     */
    public static function dependencyGraph(): array
    {
        $graph = [];

        foreach (self::modules() as $slug => $definition) {
            if ($definition['depends_on'] !== []) {
                $graph[$slug] = $definition['depends_on'];
            }
        }

        return $graph;
    }

    /**
     * A single module definition, or null when the slug is not registered.
     *
     * @return array<string, mixed>|null
     */
    public static function module(string $slug): ?array
    {
        return self::modules()[$slug] ?? null;
    }

    /**
     * Every registered module slug, in registration order.
     *
     * @return array<int, string>
     */
    public static function moduleSlugs(): array
    {
        return array_keys(self::modules());
    }

    /**
     * Slugs of the modules that can never be disabled.
     *
     * @return array<int, string>
     */
    public static function coreSlugs(): array
    {
        $slugs = [];

        foreach (self::modules() as $slug => $definition) {
            if ($definition['is_core'] === true) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * The abilities a module declares, as declared: Ability cases for real modules,
     * plain strings for the portal permission prefixes.
     *
     * @return array<int, Ability|string>
     */
    public static function abilitiesFor(string $module): array
    {
        return self::modules()[$module]['abilities'] ?? [];
    }

    /**
     * The ability values (strings) a module declares.
     *
     * @return array<int, string>
     */
    public static function abilityValuesFor(string $module): array
    {
        return array_map(
            static fn (Ability|string $ability): string => self::abilityValue($ability),
            self::abilitiesFor($module),
        );
    }

    /**
     * Every permission row the system knows about.
     *
     * @return array<int, array{
     *     name: string,
     *     module: string,
     *     ability: string,
     *     group: string,
     *     label: string,
     *     sort_order: int
     * }>
     */
    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }

        $rows = [];

        foreach (self::modules() as $slug => $definition) {
            $position = 0;

            foreach ($definition['abilities'] as $ability) {
                $value = self::abilityValue($ability);

                $rows[] = [
                    'name' => $slug.'.'.$value,
                    'module' => $slug,
                    'ability' => $value,
                    'group' => $definition['group']->value,
                    'label' => self::abilityLabel($ability).' '.$definition['name'],
                    'sort_order' => ($definition['sort'] * 100) + $position,
                ];

                $position++;
            }
        }

        return self::$permissions = $rows;
    }

    /**
     * Every permission name, flat.
     *
     * @return array<int, string>
     */
    public static function permissionNames(): array
    {
        return array_column(self::permissions(), 'name');
    }

    /**
     * Permission names for one or more modules, optionally narrowed to a set of abilities.
     *
     * Lets seeders and the role editor build grants from module lists instead of hand-typed
     * permission strings:
     *
     *   PermissionRegistry::permissionNamesFor(['employees', 'departments']);
     *   PermissionRegistry::permissionNamesFor('leads', [Ability::ViewAny, Ability::View]);
     *   PermissionRegistry::permissionNamesFor('collaborator_portal');
     *
     * Unknown module slugs yield nothing; unknown abilities are simply not matched.
     *
     * @param  string|array<int, string>  $modules
     * @param  array<int, Ability|string>|null  $abilities  null = every ability the module declares
     * @return array<int, string>
     */
    public static function permissionNamesFor(string|array $modules, ?array $abilities = null): array
    {
        $modules = is_string($modules) ? [$modules] : $modules;

        $wanted = $abilities === null
            ? null
            : array_map(
                static fn (Ability|string $ability): string => self::abilityValue($ability),
                $abilities,
            );

        $names = [];

        foreach ($modules as $slug) {
            if (! is_string($slug)) {
                throw new InvalidArgumentException('Module slugs must be strings.');
            }

            foreach (self::abilitiesFor($slug) as $ability) {
                $value = self::abilityValue($ability);

                if ($wanted !== null && ! in_array($value, $wanted, true)) {
                    continue;
                }

                $name = $slug.'.'.$value;

                if (! in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * Permission names for every module in a group.
     *
     * @return array<int, string>
     */
    public static function permissionNamesForGroup(ModuleGroup $group, ?array $abilities = null): array
    {
        $slugs = [];

        foreach (self::modules() as $slug => $definition) {
            if ($definition['group'] === $group) {
                $slugs[] = $slug;
            }
        }

        return self::permissionNamesFor($slugs, $abilities);
    }

    /**
     * Merge ability presets, preserving declaration order and dropping duplicates.
     *
     * @param  array<int, Ability|string>  ...$sets
     * @return array<int, Ability|string>
     */
    private static function merge(array ...$sets): array
    {
        $merged = [];

        foreach ($sets as $set) {
            foreach ($set as $ability) {
                if (! in_array($ability, $merged, true)) {
                    $merged[] = $ability;
                }
            }
        }

        return $merged;
    }

    /**
     * The string stored in `permissions.ability`.
     */
    private static function abilityValue(Ability|string $ability): string
    {
        return $ability instanceof Ability ? $ability->value : $ability;
    }

    /**
     * The human wording of an ability: the Ability enum owns it for real modules, and the portal
     * prefixes — whose abilities are plain strings — are humanised from the value itself.
     */
    private static function abilityLabel(Ability|string $ability): string
    {
        return $ability instanceof Ability
            ? $ability->label()
            : self::humanize($ability);
    }

    /**
     * `view_any` => `View Any`, `student_fee_status` => `Student Fee Status`.
     */
    private static function humanize(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }
}
