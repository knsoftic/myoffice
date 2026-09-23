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
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::FILES, self::MONEY, self::IMPORT, self::RESTORE, self::LOGS),
            ],
            'client_documents' => [
                'name' => 'Client Documents',
                'group' => ModuleGroup::SoftwareHouse,
                'icon' => 'document-text',
                'is_core' => false,
                'sort' => 125,
                'abilities' => self::merge(self::READ, self::FILES, [Ability::Edit, Ability::Delete], self::STATUS),
            ],
            'projects' => [
                'name' => 'Projects',
                'group' => ModuleGroup::SoftwareHouse,
                'icon' => 'folder',
                'is_core' => false,
                'sort' => 130,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::FILES, self::MONEY, self::REPORTS, self::RESTORE, self::LOGS),
            ],
            'project_milestones' => [
                'name' => 'Project Milestones',
                'group' => ModuleGroup::SoftwareHouse,
                'icon' => 'flag',
                'is_core' => false,
                'sort' => 140,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::ASSIGN, self::APPROVE, self::MONEY, self::RESTORE, self::REPORTS),
            ],
            'tasks' => [
                'name' => 'Tasks',
                'group' => ModuleGroup::SoftwareHouse,
                'icon' => 'check-circle',
                'is_core' => false,
                'sort' => 150,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::FILES, self::RESTORE, self::REPORTS, self::LOGS),
            ],
            'task_comments' => [
                'name' => 'Task Comments',
                'group' => ModuleGroup::SoftwareHouse,
                'icon' => 'chat-bubble-left-right',
                'is_core' => false,
                'sort' => 155,
                // phase-06 §4.1: a role must be able to discuss a task without holding `tasks.edit`, and
                // §59 gives a collaborator "add comments" and nothing else.
                'abilities' => self::merge(self::READ, [Ability::Create, Ability::Edit, Ability::Delete]),
            ],
            'time_tracking' => [
                'name' => 'Time Tracking',
                'group' => ModuleGroup::SoftwareHouse,
                'icon' => 'clock',
                'is_core' => false,
                'sort' => 160,
                'abilities' => self::merge(self::CRUD, self::APPROVE, self::REPORTS, self::RESTORE, self::STATUS, self::LOGS),
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
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::FILES, self::MONEY, self::IMPORT, self::RESTORE, self::REPORTS, self::LOGS),
            ],
            'departments' => [
                'name' => 'Departments',
                'group' => ModuleGroup::Hr,
                'icon' => 'building-office-2',
                'is_core' => false,
                'sort' => 220,
                'abilities' => self::merge(self::CRUD, self::RESTORE, self::STATUS, self::ASSIGN),
            ],
            'attendance' => [
                'name' => 'Attendance',
                'group' => ModuleGroup::Hr,
                'icon' => 'calendar-days',
                'is_core' => false,
                'sort' => 230,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::IMPORT, self::REPORTS, self::RESTORE, self::APPROVE, self::LOGS),
            ],
            'leaves' => [
                'name' => 'Leaves',
                'group' => ModuleGroup::Hr,
                'icon' => 'calendar',
                'is_core' => false,
                'sort' => 240,
                'abilities' => self::merge(self::CRUD, self::APPROVE, self::STATUS, self::REPORTS, self::RESTORE, self::FILES, self::LOGS),
            ],
            'payroll' => [
                'name' => 'Payroll',
                'group' => ModuleGroup::Hr,
                'icon' => 'banknotes',
                'is_core' => false,
                'sort' => 250,
                'abilities' => self::merge(self::CRUD, self::APPROVE, self::STATUS, self::MONEY, self::REPORTS, self::RESTORE, self::LOGS),
            ],

            // phase-07 §4.1 — the eleven slugs this phase adds. Every one is `is_core = false`, so a
            // business that does not run payroll can switch it off and keep every row (D5).
            'designations' => [
                'name' => 'Designations',
                'group' => ModuleGroup::Hr,
                'icon' => 'identification',
                'is_core' => false,
                'sort' => 212,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::RESTORE),
            ],
            'employee_documents' => [
                'name' => 'Employee Documents',
                'group' => ModuleGroup::Hr,
                'icon' => 'document-text',
                'is_core' => false,
                'sort' => 214,
                // CNICs, contracts and medical reports are the most sensitive rows in HR: `download` is
                // the privacy gate and every download writes an activity row.
                'abilities' => self::merge(self::READ, [Ability::Create, Ability::Delete], self::FILES, self::STATUS, self::LOGS),
            ],
            'work_shifts' => [
                'name' => 'Work Shifts',
                'group' => ModuleGroup::Hr,
                'icon' => 'clock',
                'is_core' => false,
                'sort' => 232,
                // A shift defines what "late" means. Editing one changes nobody's history (HR-2) and
                // everybody's future, which is why it is permissioned apart from marking attendance.
                'abilities' => self::merge(self::CRUD, self::STATUS, self::ASSIGN),
            ],
            'holidays' => [
                'name' => 'Holidays',
                'group' => ModuleGroup::Hr,
                'icon' => 'calendar-days',
                'is_core' => false,
                'sort' => 234,
                'abilities' => self::merge(self::CRUD, self::IMPORT, [Ability::Export]),
            ],
            'leave_types' => [
                'name' => 'Leave Types',
                'group' => ModuleGroup::Hr,
                'icon' => 'tag',
                'is_core' => false,
                'sort' => 242,
                // Quotas and accrual rules are policy, not a leave clerk's business.
                'abilities' => self::merge(self::CRUD, self::STATUS, self::RESTORE),
            ],
            'leave_balances' => [
                'name' => 'Leave Balances',
                'group' => ModuleGroup::Hr,
                'icon' => 'scale',
                'is_core' => false,
                'sort' => 244,
                // **No edit, no delete**: minting or removing days is an append-only adjustment (HR-7),
                // and the right to mint days is not the right to approve a leave.
                'abilities' => self::merge(self::READ, [Ability::Create, Ability::Export], self::LOGS),
            ],
            'salary_components' => [
                'name' => 'Salary Components',
                'group' => ModuleGroup::Hr,
                'icon' => 'adjustments-horizontal',
                'is_core' => false,
                'sort' => 246,
                // A component definition shapes every future slip, so it is permissioned apart from one
                // employee's salary.
                'abilities' => self::merge(self::CRUD, self::STATUS, self::RESTORE),
            ],
            'salary_structures' => [
                'name' => 'Salary Structures',
                'group' => ModuleGroup::Hr,
                'icon' => 'banknotes',
                'is_core' => false,
                'sort' => 248,
                // **Never edit, never delete** (HR-10): a raise is a new version, so there is no ability
                // to grant that would let somebody rewrite one.
                'abilities' => self::merge(self::READ, [Ability::Create], self::APPROVE, self::STATUS, self::MONEY, self::LOGS),
            ],
            'salary_slips' => [
                'name' => 'Salary Slips',
                'group' => ModuleGroup::Hr,
                'icon' => 'document-currency-dollar',
                'is_core' => false,
                'sort' => 252,
                // Lets an Accountant read and print slips with no right to generate, lock or pay a run.
                'abilities' => self::merge(self::READ, [Ability::Print, Ability::Export], self::MONEY, self::LOGS),
            ],
            'employee_advances' => [
                'name' => 'Employee Advances',
                'group' => ModuleGroup::Hr,
                'icon' => 'credit-card',
                'is_core' => false,
                'sort' => 254,
                // **Never delete**: a cancellation is a status and a waiver is a row (HR-19).
                'abilities' => self::merge(self::READ, [Ability::Create], self::APPROVE, self::STATUS, self::MONEY, self::LOGS),
            ],
            'employee_self_service' => [
                'name' => 'My HR',
                'group' => ModuleGroup::Hr,
                'icon' => 'user-circle',
                'is_core' => false,
                'sort' => 256,
                // The staff member's own window (§9): view = own attendance and leave, create = own punch,
                // leave request or correction request, view_financial = own slip amounts.
                'abilities' => self::merge(
                    self::READ,
                    [Ability::Create, Ability::Print, Ability::Upload, Ability::Download],
                    self::MONEY,
                ),
            ],

            /*
            |------------------------------------------------------------------
            | Finance
            |------------------------------------------------------------------
            */

            // phase-13 §4.2 adds LOGS. **Emailing an invoice is `change_status`** — it is the act that
            // moves `draft` to `sent` — so no `send` ability is invented; the spine set that precedent by
            // mapping "run a reconciliation" onto the same case. `delete` stays, narrowed by the policy
            // to an unissued draft with no payment against it: taking an ability away would revoke a
            // permission somebody has already granted (D65).
            'invoices' => [
                'name' => 'Invoices',
                'group' => ModuleGroup::Finance,
                'icon' => 'document-text',
                'is_core' => false,
                'sort' => 310,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::APPROVE, self::MONEY, self::FILES, self::REPORTS, self::RESTORE, self::LOGS),
            ],
            // phase-13 §4.2 adds LOGS. This slug gates **only** the cross-source money-in register
            // (§8.13): every project-payment screen, admin and client alike, carries
            // `module:project_payments` instead (F-6.1). A business that switches `payments` off loses
            // the combined view and keeps both registers it combines.
            'payments' => [
                'name' => 'Payments',
                'group' => ModuleGroup::Finance,
                'icon' => 'credit-card',
                'is_core' => false,
                'sort' => 320,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::APPROVE, self::MONEY, self::REPORTS, self::RESTORE, self::LOGS),
            ],
            // phase-10-12 §4.1. **No `edit` and no `delete`, for ever** (INV-8, INV-5): a received payment
            // is never editable, and voiding it is `change_status`. `link_invoice` is the single narrow,
            // non-preset addition D43 needs — it permits the invoice-link field move and nothing else,
            // and it belongs to no preset, specifically not to MONEY.
            'project_payments' => [
                'name' => 'Project Payments',
                'group' => ModuleGroup::Finance,
                'icon' => 'banknotes',
                'is_core' => false,
                'sort' => 325,
                // phase-13 §4.2 adds REPORTS: the income report, the profit-and-loss statement and the
                // receivables aging all read this table, and without `view_reports` they could not be
                // permissioned honestly — the alternative is a report gated on a module it does not read.
                'abilities' => self::merge(
                    self::READ,
                    [Ability::Create, Ability::Print, Ability::Export, Ability::LinkInvoice],
                    self::STATUS, self::MONEY, self::REPORTS, self::LOGS,
                ),
            ],
            // phase-10-12 §4.1. A reversal is created and approved; it is never edited or deleted,
            // because it is the evidence that money went back.
            'payment_reversals' => [
                'name' => 'Payment Reversals',
                'group' => ModuleGroup::Finance,
                'icon' => 'arrow-uturn-left',
                'is_core' => false,
                'sort' => 335,
                'abilities' => self::merge(self::READ, [Ability::Create], self::APPROVE, self::MONEY, self::REPORTS, self::LOGS),
            ],
            // phase-10-12 §4.1. `change_status` **is** "run a reconciliation / repair the cache" — no new
            // Ability case is invented for it, because a repair is a state change like any other.
            'wallet_reconciliation' => [
                'name' => 'Wallet Reconciliation',
                'group' => ModuleGroup::Finance,
                'icon' => 'scale',
                'is_core' => false,
                'sort' => 345,
                'abilities' => self::merge(self::READ, self::STATUS, self::MONEY, self::REPORTS, self::LOGS),
            ],
            // phase-13 §4.2 adds LOGS. `approve` is §30's approval workflow, `change_status` is the
            // void path, and `FILES` is the receipt — three different acts that a single `edit` would
            // have collapsed into one permission nobody could hand out safely.
            'expenses' => [
                'name' => 'Expenses',
                'group' => ModuleGroup::Finance,
                'icon' => 'receipt-percent',
                'is_core' => false,
                'sort' => 330,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::APPROVE, self::MONEY, self::FILES, self::REPORTS, self::RESTORE, self::LOGS),
            ],
            // phase-13 §4.2 adds STATUS (void), FILES (the receipt) and LOGS. **No `approve`**:
            // requirement §29 asks for none, and money that has arrived does not need a second person
            // to agree that it arrived.
            'income' => [
                'name' => 'Income',
                'group' => ModuleGroup::Finance,
                'icon' => 'arrow-trending-up',
                'is_core' => false,
                'sort' => 340,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::MONEY, self::FILES, self::REPORTS, self::RESTORE, self::LOGS),
            ],
            // phase-13 §4.2 adds LOGS. **No `MONEY` and no `export`**: the table holds no amounts, and
            // the one sensitive thing on it — the encrypted gateway config — is reachable through
            // `edit` alone and is rendered nowhere, so there is nothing for `view_financial` to unmask.
            'payment_methods' => [
                'name' => 'Payment Methods',
                'group' => ModuleGroup::Finance,
                'icon' => 'wallet',
                'is_core' => false,
                'sort' => 350,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::RESTORE, self::LOGS),
            ],
            // phase-13 §4.1. **No `MONEY`**: a category carries no amount, so somebody who maintains the
            // list never has to be given sight of a single figure to do it.
            'finance_categories' => [
                'name' => 'Finance Categories',
                'group' => ModuleGroup::Finance,
                'icon' => 'tag',
                'is_core' => false,
                'sort' => 355,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::RESTORE, self::LOGS),
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
                // phase-08-09 §4.2 adds APPROVE (onboarding a `pending` application, §34) and LOGS
                // (§60's per-collaborator feed). ASSIGN and FILES were registered in Phase 1 and stay:
                // taking an ability away would revoke a permission an administrator has already granted
                // (D65). `delete` is soft only — `forceDelete` is refused outright by the policy (INV-C5).
                'abilities' => self::merge(self::CRUD_FULL, self::APPROVE, self::STATUS, self::ASSIGN, self::FILES, self::MONEY, self::REPORTS, self::RESTORE, self::LOGS),
            ],
            // phase-10-12 §4.2 adds APPROVE and LOGS. `edit` and `delete` were registered in Phase 1
            // and stay — taking an ability away revokes a permission somebody has already granted (D65)
            // — but **no code path uses them**: INV-17 makes a rule version immutable at the model, so a
            // rate change is a new version and nothing in the system offers an edit form.
            'collaborator_commission_settings' => [
                'name' => 'Collaborator Commission Settings',
                'group' => ModuleGroup::Collaborator,
                'icon' => 'adjustments-horizontal',
                'is_core' => false,
                'sort' => 420,
                'abilities' => self::merge(self::CRUD, self::APPROVE, self::MONEY, self::LOGS, self::RESTORE),
            ],
            // Financial history is immutable: read, approve and report only — corrections are
            // reversing entries created by the commission engine, never edits or deletes.
            // phase-10-12 §4.2 adds `create` — the manual adjustment and write-off path — and LOGS.
            // Still never `edit` and never `delete`: a wrong commission is corrected by a reversing
            // entry that references it (CLAUDE.md rule 3).
            'collaborator_commissions' => [
                'name' => 'Collaborator Commissions',
                'group' => ModuleGroup::Collaborator,
                'icon' => 'calculator',
                'is_core' => false,
                'sort' => 430,
                'abilities' => self::merge(self::READ, [Ability::Create], self::APPROVE, self::STATUS, self::MONEY, self::REPORTS, self::LOGS),
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
                // phase-10-12 §4.2 adds LOGS. `delete` is absent and stays absent: §120.9 requires
                // payout history to survive, and cancelling is a status that releases the allocations.
                'abilities' => self::merge(self::CRUD, self::APPROVE, self::STATUS, self::MONEY, self::FILES, self::REPORTS, self::LOGS, self::RESTORE),
            ],
            // phase-08-09 §4.2. `create` is the manual link a receptionist makes at admission; `edit`
            // is the change-attribution right (§37), which supersedes and never mutates (INV-R4). There
            // is deliberately no `delete`: an attribution is evidence (D19).
            'collaborator_referrals' => [
                'name' => 'Collaborator Referrals',
                'group' => ModuleGroup::Collaborator,
                'icon' => 'share',
                'is_core' => false,
                'sort' => 460,
                'abilities' => self::merge(self::READ, [Ability::Create, Ability::Edit], self::STATUS, self::REPORTS, self::LOGS),
            ],

            // phase-08-09 §4.1. §55 asks for sensitive payout data to be protected, so an encrypted bank
            // destination gets its own gate rather than riding on `collaborator_payouts.view` — an
            // Accountant who may approve a payout does not thereby get to manage where money goes.
            // `change_status` means verify / disable. **There is deliberately no `view_financial`**:
            // no ability anywhere reveals `details_encrypted`, so there is nothing to unmask (INV-C6).
            'collaborator_payout_accounts' => [
                'name' => 'Collaborator Payout Accounts',
                'group' => ModuleGroup::Collaborator,
                'icon' => 'credit-card',
                'is_core' => false,
                'sort' => 455,
                'abilities' => self::merge(self::READ, [Ability::Create, Ability::Edit, Ability::Delete], self::STATUS, self::LOGS),
            ],

            // phase-08-09 §4.1. §38 click tracking — read-only by design, because nobody edits a click.
            // Separate from `collaborator_referrals` because these rows carry IP addresses and user
            // agents, which a Sales Executive who may link a referral has no business reading.
            'collaborator_referral_visits' => [
                'name' => 'Collaborator Referral Visits',
                'group' => ModuleGroup::Collaborator,
                'icon' => 'cursor-arrow-rays',
                'is_core' => false,
                'sort' => 465,
                'abilities' => self::merge(self::READ, self::REPORTS, self::LOGS),
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
                // phase-14-17 §4.2 adds REPORTS and LOGS: a catalogue is reported on (which courses
                // sell, which never filled a batch) and a fee change is a decision somebody is asked
                // to account for. `view_financial` from MONEY gates the three fee columns everywhere a
                // course is rendered — the index, the form, the export and the public page.
                'abilities' => self::merge(
                    self::CRUD_FULL, self::STATUS, self::MONEY, self::FILES, self::REPORTS, self::LOGS, self::RESTORE,
                ),
            ],
            'course_outline' => [
                'name' => 'Course Outline',
                'group' => ModuleGroup::Institute,
                'icon' => 'list-bullet',
                'is_core' => false,
                'sort' => 530,
                // phase-14-17 §4.2 adds FILES (a syllabus resource is an upload) and STATUS
                // (deactivating a node rather than deleting it is the whole of INV-I13, and it is a
                // different right from editing one).
                'abilities' => self::merge(self::CRUD, self::FILES, self::STATUS, self::RESTORE),
            ],
            'course_materials' => [
                'name' => 'Course Materials',
                'group' => ModuleGroup::Institute,
                'icon' => 'folder-open',
                'is_core' => false,
                'sort' => 540,
                // phase-19-23 §4.2. `assign` **is** the targeting ability — course / batch / student
                // — and it is separate from `edit` on purpose: deciding who receives a file is a
                // different act from correcting its title, and a coordinator may be trusted with one
                // and not the other. `upload` creates, `download` streams, `view_reports` opens the
                // engagement report. REPORTS already carries `export`.
                'abilities' => self::merge(
                    self::CRUD,
                    self::FILES,
                    self::RESTORE,
                    self::ASSIGN,
                    self::STATUS,
                    self::REPORTS,
                    self::LOGS,
                ),
            ],
            'students' => [
                'name' => 'Students',
                'group' => ModuleGroup::Institute,
                'icon' => 'users',
                'is_core' => false,
                'sort' => 550,
                // phase-14-17 §4.2 adds LOGS: a student record is one somebody may later have to
                // account for, and `view_logs` is what lets an auditor read that account without
                // holding `edit`. `view_financial` is declared but gates nothing here — a student's
                // money is `student_fees.view_financial` (§4.2), and three seeded roles already hold
                // this one, so removing it would revoke rather than tidy.
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::FILES, self::MONEY, self::IMPORT, self::REPORTS, self::LOGS, self::RESTORE),
            ],
            'admissions' => [
                'name' => 'Admissions',
                'group' => ModuleGroup::Institute,
                'icon' => 'user-plus',
                'is_core' => false,
                'sort' => 560,
                // §4.2: `view_financial` gates the agreed figures and the four paid/pending caches
                // everywhere an admission is rendered, and LOGS lets somebody read the history of a
                // discount without being able to change one.
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::APPROVE, self::MONEY, self::FILES, self::REPORTS, self::LOGS, self::RESTORE),
            ],
            'course_inquiries' => [
                'name' => 'Course Inquiries',
                'group' => ModuleGroup::Institute,
                'icon' => 'question-mark-circle',
                'is_core' => false,
                'sort' => 570,
                // §4.2: `assign` hands an enquiry to another counsellor, and REPORTS is the §88 funnel
                // — which is a different right from working the queue, because the conversion rate is
                // a management number and the queue is a job.
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::REPORTS, self::LOGS, self::RESTORE),
            ],
            'demo_classes' => [
                'name' => 'Demo Classes',
                'group' => ModuleGroup::Institute,
                'icon' => 'video-camera',
                'is_core' => false,
                'sort' => 580,
                // §4.2 adds `print` — the demo slip an attendee is handed at reception — and REPORTS.
                'abilities' => self::merge(self::CRUD, self::STATUS, self::ASSIGN, self::REPORTS, self::RESTORE),
            ],

            // §4.1, a module of its own: the public §67 inbox is triaged by a receptionist who must
            // NOT hold `students.create` until the day they convert one, and a module boundary is the
            // only way to say that. It has no `delete` and never will — a public submission is
            // evidence that somebody asked, so it is rejected or marked duplicate, never removed.
            'student_applications' => [
                'name' => 'Admission Applications',
                'group' => ModuleGroup::Institute,
                'icon' => 'inbox-arrow-down',
                'is_core' => false,
                'sort' => 585,
                'abilities' => self::merge(
                    self::READ,
                    [Ability::Create, Ability::Edit, Ability::Export],
                    self::APPROVE,
                    self::STATUS,
                    self::LOGS,
                ),
            ],
            'teachers' => [
                'name' => 'Teachers',
                'group' => ModuleGroup::Institute,
                'icon' => 'presentation-chart-bar',
                'is_core' => false,
                'sort' => 590,
                'abilities' => self::merge(
                    self::CRUD_FULL,
                    self::STATUS,
                    self::ASSIGN,
                    self::FILES,
                    self::MONEY,
                    self::REPORTS,
                    self::LOGS,
                    self::RESTORE,
                ),
            ],
            // Phase 16 §4.1 — a room is booked by the timetable and by demo classes, so a branch
            // admin can be given the rooms without being given the batches that fill them.
            'classrooms' => [
                'name' => 'Classrooms',
                'group' => ModuleGroup::Institute,
                'icon' => 'building-office-2',
                'is_core' => false,
                'sort' => 595,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::RESTORE),
            ],
            'batches' => [
                'name' => 'Batches',
                'group' => ModuleGroup::Institute,
                'icon' => 'squares-2x2',
                'is_core' => false,
                'sort' => 600,
                // `assign` is the enrollment / transfer ability (§4.2) — there is no
                // `student_enrollments` module, and inventing one would add surface for no gain.
                'abilities' => self::merge(
                    self::CRUD_FULL,
                    self::STATUS,
                    self::ASSIGN,
                    self::REPORTS,
                    self::LOGS,
                    self::RESTORE,
                ),
            ],
            'timetable' => [
                'name' => 'Timetable',
                'group' => ModuleGroup::Institute,
                'icon' => 'table-cells',
                'is_core' => false,
                'sort' => 610,
                // `change_status` covers cancel / reschedule / substitute / mark held (§4.2).
                'abilities' => self::merge(
                    self::CRUD,
                    self::STATUS,
                    self::ASSIGN,
                    [Ability::Export, Ability::Print],
                    self::REPORTS,
                    self::RESTORE,
                ),
            ],
            'student_attendance' => [
                'name' => 'Student Attendance',
                'group' => ModuleGroup::Institute,
                'icon' => 'clipboard-document-check',
                'is_core' => false,
                'sort' => 620,
                // phase-14-17 §4.2. `edit` is what the post-lock amendment of INV-I10 requires — a
                // register is corrected, never deleted, so `delete` exists only for the soft-delete
                // column `CLAUDE.md` §3 asks for and every policy answers false to it.
                'abilities' => self::merge(
                    self::CRUD,
                    self::STATUS,
                    self::IMPORT,
                    self::REPORTS,
                    self::LOGS,
                    self::RESTORE,
                ),
            ],
            'student_progress' => [
                'name' => 'Student Progress',
                'group' => ModuleGroup::Institute,
                'icon' => 'chart-bar',
                'is_core' => false,
                'sort' => 630,
                // phase-14-17 §4.2: read + create + edit + change_status + the reports. There is
                // deliberately **no `delete`**: every row here is derived by `CourseProgressService`
                // and re-derivable by `progress:recompute`, so deleting one would destroy nothing and
                // repair nothing. A topic that should stop counting is `skipped`, which is
                // `change_status` and leaves the reason on the record.
                'abilities' => self::merge(
                    self::READ,
                    [Ability::Create, Ability::Edit],
                    self::STATUS,
                    self::REPORTS,
                ),
            ],
            'student_fees' => [
                'name' => 'Student Fees',
                'group' => ModuleGroup::Institute,
                'icon' => 'banknotes',
                'is_core' => false,
                'sort' => 640,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::APPROVE, self::MONEY, self::REPORTS, self::RESTORE),
            ],
            // phase-10-12 §4.1. The receipt itself: created, printed and voided, never edited or
            // deleted (INV-8, INV-5).
            'student_fee_payments' => [
                'name' => 'Fee Receipts',
                'group' => ModuleGroup::Institute,
                'icon' => 'receipt-percent',
                'is_core' => false,
                'sort' => 645,
                // phase-13 §4.2 adds REPORTS: the income report's fee lines read this table.
                'abilities' => self::merge(
                    self::READ,
                    [Ability::Create, Ability::Print, Ability::Export],
                    // phase-18 §4.2 (Q2, F-6.9): spine §6.6 row 6 and FT-39 both require "the module's
                    // `approve` ability" to post a receipt older than `finance.backdate_limit_days`,
                    // and spine §4.1 never granted the module one — so the rule they state could not be
                    // enforced by the gate they name. No role gains it by default, so the back-date
                    // window tightens rather than loosens.
                    self::APPROVE,
                    self::STATUS, self::MONEY, self::REPORTS, self::LOGS,
                ),
            ],
            'fee_reminders' => [
                'name' => 'Fee Reminders',
                'group' => ModuleGroup::Institute,
                'icon' => 'bell-alert',
                'is_core' => false,
                'sort' => 665,
                // phase-18 §4.1. "Tell a student they owe money" is a different act from editing a fee:
                // a Receptionist may do the first and must not do the second, and folding the two into
                // `student_fees.edit` would have meant granting the second to get the first.
                //
                // No `edit` and no `delete`. A sent reminder is a log row — it cannot be edited into
                // having said something else, and deleting it would remove the one piece of evidence a
                // student disputing being chased would want. D19 is why the table has no `deleted_at`
                // either.
                'abilities' => self::merge(
                    self::READ,
                    [Ability::Create],
                    self::LOGS,
                ),
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
                // phase-19-23 §4.2. `print` is the printable assignment sheet for a physical class —
                // a real request from §80, not a CRUD leftover.
                'abilities' => self::merge(
                    self::CRUD_FULL,
                    self::STATUS,
                    self::ASSIGN,
                    self::FILES,
                    self::RESTORE,
                    self::REPORTS,
                    self::LOGS,
                    [Ability::Print],
                ),
            ],
            'assignment_submissions' => [
                'name' => 'Assignment Submissions',
                'group' => ModuleGroup::Institute,
                'icon' => 'clipboard-document-check',
                'is_core' => false,
                'sort' => 675,
                // phase-19-23 §4.1: **grading is not authoring.** A visiting trainer may read a
                // roster's work without marking it, and a coordinator may mark without being able to
                // publish new assignments — neither of which is expressible if both live under
                // `assignments.edit`.
                //
                // `edit` **is** the mark-and-feedback ability. `create` exists only for "record an
                // offline submission on a student's behalf".
                //
                // **No `delete` and no `restore`, deliberately** (§2.7). Marked work is somebody's
                // record; an ability that is never registered cannot be granted by mistake, and the
                // model's `deleting` hook refuses it even for a Super Admin, whom `Gate::before`
                // would otherwise wave past every policy.
                'abilities' => self::merge(
                    self::READ,
                    [Ability::Create, Ability::Edit, Ability::Download],
                    self::STATUS,
                    self::REPORTS,
                    self::LOGS,
                ),
            ],
            'grade_scales' => [
                'name' => 'Grade Scales',
                'group' => ModuleGroup::Institute,
                'icon' => 'academic-cap',
                'is_core' => false,
                'sort' => 678,
                // §4.1: §82's grade comes from a scale an exam officer maintains, and giving somebody
                // that right does not imply the right to publish results. Separately grantable is the
                // whole reason this is not folded into `results`.
                //
                // No `restore`: a scale is deactivated rather than deleted (INV-20-4), so there is
                // nothing to bring back.
                'abilities' => self::merge(self::CRUD, self::STATUS),
            ],
            'exams' => [
                'name' => 'Exams',
                'group' => ModuleGroup::Institute,
                'icon' => 'document-chart-bar',
                'is_core' => false,
                'sort' => 680,
                // §4.2. `assign` sets the examiner or the marker; `change_status` covers scheduling,
                // conducting and cancelling — §2.28.4 puts all three behind one ability because they
                // are the same person's job on the same screen.
                'abilities' => self::merge(
                    self::CRUD_FULL,
                    self::STATUS,
                    self::ASSIGN,
                    self::RESTORE,
                    self::REPORTS,
                    self::LOGS,
                ),
            ],
            'results' => [
                'name' => 'Results',
                'group' => ModuleGroup::Institute,
                'icon' => 'trophy',
                'is_core' => false,
                'sort' => 690,
                // §4.2, and the ability list is deliberately assembled by hand rather than from CRUD:
                // **`delete` and `restore` are absent.** A result is amended with a reason, never
                // removed (INV-20-5), and an ability nobody can be granted is an ability nobody can
                // be granted by mistake. `self::CRUD` would have quietly included both.
                //
                // `create` enters a sheet · `edit` amends one · `approve`/`reject` are the §2.28.4
                // second pair of eyes · `change_status` publishes and withdraws · `print` is the
                // result card · `import` is the CSV sheet.
                'abilities' => self::merge(
                    self::READ,
                    [Ability::Create, Ability::Edit, Ability::Print, Ability::Import],
                    self::APPROVE,
                    self::STATUS,
                    self::REPORTS,
                    self::LOGS,
                ),
            ],
            'print_templates' => [
                'name' => 'Print Templates',
                'group' => ModuleGroup::Institute,
                'icon' => 'document-duplicate',
                'is_core' => false,
                'sort' => 695,
                // §4.1. Separately grantable **because `body_html` is powerful**: a designer can be
                // given the certificate layout with no sight of a student record at all, and
                // conversely somebody who issues certificates all day has no reason to hold the
                // ability that decides what HTML a PDF renderer is handed.
                //
                // `print` is the preview, which renders with PrintTokenRegistry's example values and
                // never a real student's data — which is what makes the module safe to grant alone.
                //
                // No `restore`: a used template is retired rather than deleted (the model refuses the
                // delete), so there is nothing to bring back.
                'abilities' => self::merge(self::CRUD, self::STATUS, [Ability::Print]),
            ],
            'certificates' => [
                'name' => 'Certificates',
                'group' => ModuleGroup::Institute,
                'icon' => 'check-badge',
                'is_core' => false,
                'sort' => 700,
                // §4.2: READ + create + edit + APPROVE + STATUS + print + export + LOGS.
                //
                // **`view_logs` is the new one and it is load-bearing** — §4.4 puts
                // `certificate_verifications` behind it, so without it the public-verification log has
                // no gate and the abuse-detection screen has no permission to sit behind.
                //
                // **`delete` and `restore` stay**, unlike `results`. A *draft* certificate is a
                // document nobody has been given, so deleting one is reasonable; the policy narrows
                // the ability to drafts and the model refuses anything that gets past it (INV-21-1).
                // An ability whose scope a policy narrows is a different thing from one no route can
                // ever honour.
                //
                // **`download` is a synonym the policy resolves to `print`.** §7.5 gates the PDF
                // route on `certificates.print`, because printing a certificate and saving a PDF of
                // it are the same act by two routes — `CertificatePolicy::download()` says so in one
                // line. The ability stays declared so a role that already holds it keeps working,
                // and so the distinction is available if an institute ever wants it.
                //
                // **`upload` goes.** Nothing in this phase uploads to a certificate: the PDF is
                // generated, the QR is generated, the signature images belong to the template. An
                // ability nobody can use is an ability somebody grants by mistake — the same
                // argument that removed `results.delete`.
                'abilities' => self::merge(
                    self::READ,
                    [Ability::Create, Ability::Edit, Ability::Delete, Ability::Download, Ability::Print],
                    self::APPROVE,
                    self::STATUS,
                    self::RESTORE,
                    [Ability::Export],
                    self::LOGS,
                ),
            ],
            'student_id_cards' => [
                'name' => 'Student ID Cards',
                'group' => ModuleGroup::Institute,
                'icon' => 'identification',
                'is_core' => false,
                'sort' => 710,
                // §4.2: READ + create + edit + STATUS + print + export + LOGS — **never `delete`**.
                //
                // Unlike a certificate there is no draft card: a card is numbered, snapshotted and
                // printed in one step, so there is never a row nobody has been given. A card that was
                // issued stays on the register — lost, damaged, replaced or revoked — and the model
                // refuses the delete outright. Registering an ability the policy and the model both
                // refuse would only let somebody grant it and wonder why it does nothing.
                //
                // `print` additionally gates **batch printing**, bounded by
                // `institute.id_card_batch_print_max`.
                'abilities' => self::merge(
                    self::READ,
                    [Ability::Create, Ability::Edit, Ability::Print],
                    self::STATUS,
                    [Ability::Export],
                    self::LOGS,
                ),
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

            'ticket_departments' => [
                'name' => 'Ticket Departments',
                'group' => ModuleGroup::Shared,
                'icon' => 'rectangle-stack',
                'is_core' => false,
                // 1005: the Shared band runs 1010 support_tickets, 1020 meetings, 1030 messages,
                // 1040 files, 1050 notifications, 1060 reports — and a department belongs above the
                // queue it organises. D111: two modules on one sort order is two sidebar items in an
                // arbitrary order, and a manifest test asserts no collision.
                'sort' => 1005,
                // §4.1. Its own module so a support lead maintains the queues and their SLA targets
                // **without holding `settings.edit`** — which would hand them the SMTP credentials
                // and the security group along with it.
                //
                // No `print`, no `export`, no `view_logs`: a department is a short list of rows an
                // administrator reads on one screen. An ability nobody can use is an ability somebody
                // grants by mistake — the argument that removed `certificates.upload`.
                'abilities' => self::merge(self::CRUD, self::STATUS),
            ],
            'support_tickets' => [
                'name' => 'Support Tickets',
                'group' => ModuleGroup::Shared,
                'icon' => 'lifebuoy',
                'is_core' => false,
                'sort' => 1010,
                // `view_reports` is the SLA desk: §93's queue figures, first-response and resolution
                // times, and the audience §10.3 names for `ticket.sla_breach` — a breach goes to the
                // assignee and to whoever is accountable for the target, and that is this permission.
                // `export` and `print` come with CRUD_FULL already, so only `view_reports` is new.
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::ASSIGN, self::FILES, self::RESTORE, self::REPORTS),
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
                // `change_status` closes a thread (phase-19-23 §6.18): readable for ever afterwards,
                // writable by nobody. It is the only status a conversation has, and it needed a name.
                //
                // **`view_any` is declared and granted to nobody** (§9.4). It exists for a future
                // compliance reader, it is read-only even then, and every read it permits is logged.
                // Granting it to a role would let that role read every private conversation in the
                // system — a student's thread with their teacher included — and would make the §94
                // matrix decorative. See `RoleSeeder`, which names the message abilities one by one
                // for exactly this reason.
                'abilities' => self::merge(self::CRUD, self::STATUS, self::FILES, self::RESTORE),
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
                    // phase-06 §4.3 — start a timer and log time on an assigned task, and see own hours.
                    'time_tracking',
                    // phase-08-09 §4.3, under Phase 1 §4's licence to add "the read abilities each panel
                    // needs". §59's list does not name these six, but §3 and §36 require the surfaces.
                    'profile',
                    'referrals',
                    'leads',
                    'activity_log',
                    'notifications',
                    // phase-10-12 §4.3: the two the spine adds.
                    'wallet',
                    'payouts',
                    // Registering where one's own money should land is not the same right as asking for
                    // it: the spine gates `collaborator.payout-accounts.*` with `payout_request`, which
                    // the Collaborator role deliberately does not hold, so without this a collaborator
                    // could not add a bank account at all. The spine's routes keep their existing gate
                    // until Phase 12 adopts this one (§12.2 Q2).
                    'payout_accounts',
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
                    // phase-19-23 §4.3. Separate from `materials` so a fee-blocked student can still
                    // be shown the list and told why the files are unavailable. Hiding the library
                    // entirely would leave them guessing what they were missing; hiding only the
                    // bytes is the honest version of the same restriction.
                    'material_download',
                    'assignments',
                    'assignment_submit',
                    'exams',
                    'results',
                    'progress',
                    // Phase 16 §4.3 — the name and public bio of their OWN teachers, nothing else.
                    'teachers',
                    'certificates',
                    // phase-19-23 §7.9. Separate from `certificates` because they are different
                    // documents answering different questions: a certificate says a course was
                    // completed, a card says somebody is a student here today. An institute that
                    // does not issue cards should not have to withhold certificates to hide them.
                    'id_card',
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
                    // Phase 16 §4.3 — separate from `student_progress` on purpose: a visiting trainer
                    // may be allowed to read a register without being allowed to write it.
                    'progress_mark',
                    // phase-19-23 §7.10, §9.3. **Read only, and there is no writing ability to pair
                    // it with.** A teacher sees who on their own batches could be certified, which is
                    // useful to them; issuing, revoking and replacing are the office's, and §9.3 says
                    // 403 on every one of them.
                    'certificates',
                    'demo_classes',
                    'reports',
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
                    // phase-05 §4.3 — the names the client panel routes actually check. Appended, never
                    // renamed: the Phase 1 names above are already granted to the Client role (D4).
                    'tasks',
                    'milestones',
                    'files',
                    'documents',
                    'download',
                    'tickets',
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

        // HR — phase-07 §4.3. Disabling `employees` is blocked while any of these is on, and Phase 2's
        // impact modal names them; no HR row is ever touched by a toggle (D5).
        'attendance' => ['employees'],
        'leaves' => ['employees', 'leave_types'],
        'leave_balances' => ['leaves', 'leave_types'],
        'payroll' => ['employees', 'attendance', 'salary_structures'],
        'salary_structures' => ['employees', 'salary_components'],
        'salary_slips' => ['payroll'],
        'employee_documents' => ['employees'],
        'designations' => ['employees'],
        'employee_advances' => ['employees'],
        'employee_self_service' => ['employees'],
        // work_shifts, holidays, salary_components and leave_types stand alone: they are configuration a
        // business fills in before anybody is hired.

        // Finance — phase-13.
        'invoices' => ['clients'],
        // phase-13 §4.1. A category is the axis every expense and income report groups by; switching
        // the module off would leave both sides of the books unable to classify a new row.
        'finance_categories' => [],
        'payments' => ['projects'],

        // Collaborator spine — phase-08 … phase-12.
        'collaborator_commission_settings' => ['collaborators'],
        'collaborator_commissions' => ['collaborators', 'collaborator_commission_settings'],
        'collaborator_wallets' => ['collaborators', 'collaborator_commissions'],
        'collaborator_payouts' => ['collaborators', 'collaborator_wallets'],
        'collaborator_referrals' => ['collaborators'],
        // Both of phase-08-09 §4.1's modules hang off `collaborators` and nothing else: their rows point
        // at a collaborator, and at no other module's rows. A payout account is a destination — the
        // payout points at it, not the other way round — so it is not a dependant of `collaborator_payouts`.
        'collaborator_payout_accounts' => ['collaborators'],
        // phase-10-12 §4.1. A reconciliation is a proof about a wallet, so it is meaningless without
        // one; a receipt belongs to a fee charge and a project payment to a project.
        'wallet_reconciliation' => ['collaborator_wallets'],
        'student_fee_payments' => ['student_fees'],
        // A reminder is about a charge, and `Gate::before` denies every ability of a disabled
        // module — without this edge, switching `student_fees` off would leave the reminder
        // screens reachable and pointed at nothing.
        'fee_reminders' => ['student_fees'],
        'project_payments' => ['projects'],
        'payment_reversals' => ['project_payments'],
        'collaborator_referral_visits' => ['collaborators'],

        // Institute — phase-14 … phase-21.
        'courses' => ['course_categories'],
        'course_outline' => ['courses'],
        'course_materials' => ['courses'],
        'course_inquiries' => ['courses'],
        'student_applications' => ['courses'],
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
        'assignment_submissions' => ['assignments'],
        // An empty list is deliberate and is not the same as being absent: a scale depends on
        // nothing, and saying so stops somebody assuming it was forgotten.
        'grade_scales' => [],
        'exams' => ['batches'],
        'results' => ['exams'],
        // A template depends on nothing: it holds no student data, and an institute may design one
        // before it has a single student. An empty list is deliberate and is not the same as being
        // absent — saying so stops somebody assuming it was forgotten.
        'print_templates' => [],
        'certificates' => ['students', 'courses'],
        'student_id_cards' => ['students'],

        // Shared — phase-19-23 §4.1. Four of these five were registered by Phase 1 and have never
        // appeared here, which the graph reads as "no dependencies" — true of all but one, and
        // saying so is what stops the omission looking like an oversight.
        //
        // A ticket has to land somewhere, so `support_tickets` needs the departments. Nothing else
        // does: a meeting, a thread and a notification each stand alone, and a notification in
        // particular must keep working while every module it reports on is switched off — a bell
        // that went silent because somebody disabled `projects` would hide the news that it had been.
        'ticket_departments' => [],
        'support_tickets' => ['ticket_departments'],
        'meetings' => [],
        'messages' => [],
        'notifications' => [],

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
