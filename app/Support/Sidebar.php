<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Cms\SectionPlacement;
use App\Enums\PanelType;
use App\Models\Hr\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * The navigation tree, declared once here and filtered per user.
 *
 * An item survives three gates (phase-01 §9, CLAUDE.md §6):
 *   1. its module is enabled        — Modules::enabled($item['module'])
 *   2. its route exists             — Route::has($item['route'])
 *   3. the user holds the permission — $user->can($item['permission'])
 *   4. its optional `when` closure agrees — for a condition a permission cannot express
 * A parent with children survives when at least one child survives; a group with no surviving
 * items disappears entirely.
 *
 * Every later phase is already declared below. Because gate 2 hides anything whose route has not
 * been registered yet, the future entries stay invisible until the phase that adds their routes —
 * no edit to this file is needed then.
 *
 * Declared item keys (all optional except label):
 *   label, icon, route, params, module, permission, when, children, group, match
 *     match  — route-name pattern(s) deciding the active state; defaults to "prefix.*"
 *     group  — optional sub-heading inside a group
 *
 * Resolved item keys handed to the views:
 *   label, icon, route, url, module, permission, group, active, children
 *
 * Rendering:  @foreach (sidebar_items() as $group) ... @foreach ($group['items'] as $item)
 */
final class Sidebar
{
    /**
     * The filtered tree for a user: a list of groups, each with a list of items.
     *
     * @return array<int, array{key: string, label: string|null, icon: string|null, items: array<int, array<string, mixed>>}>
     */
    public static function forUser(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return self::forPanel(self::panelFor($user), $user);
    }

    /**
     * The filtered tree for one panel.
     *
     * @return array<int, array{key: string, label: string|null, icon: string|null, items: array<int, array<string, mixed>>}>
     */
    public static function forPanel(PanelType|string $panel, ?User $user = null): array
    {
        if ($user === null) {
            return [];
        }

        $groups = [];

        foreach (self::tree($panel) as $group) {
            $items = [];

            foreach ($group['items'] ?? [] as $item) {
                $resolved = self::resolve($item, $user);

                if ($resolved !== null) {
                    $items[] = $resolved;
                }
            }

            if ($items === []) {
                continue;
            }

            $groups[] = [
                'key' => (string) $group['key'],
                'label' => $group['label'] ?? null,
                'icon' => $group['icon'] ?? null,
                'items' => $items,
            ];
        }

        return $groups;
    }

    /**
     * Is this item (or any of its children) the current page?
     *
     * Works on both declared and resolved items.
     *
     * @param  array<string, mixed>  $item
     */
    public static function isActive(array $item): bool
    {
        foreach ($item['children'] ?? [] as $child) {
            if (is_array($child) && self::isActive($child)) {
                return true;
            }
        }

        $current = self::currentRouteName();

        if ($current === null) {
            return false;
        }

        foreach (self::patternsFor($item) as $pattern) {
            if (Str::is($pattern, $current)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The raw declarative tree for a panel, unfiltered.
     *
     * @return array<int, array{key: string, label: string|null, icon: string|null, items: array<int, array<string, mixed>>}>
     */
    public static function tree(PanelType|string|null $panel = null): array
    {
        $key = self::panelKey($panel);

        return match ($key) {
            'collaborator' => self::collaboratorTree(),
            'student' => self::studentTree(),
            'teacher' => self::teacherTree(),
            'client' => self::clientTree(),
            default => self::adminTree(),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Admin panel
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function adminTree(): array
    {
        return [
            [
                'key' => 'system',
                'label' => 'System',
                'icon' => 'cog-6-tooth',
                'items' => [
                    [
                        'label' => 'Dashboard',
                        'icon' => 'home',
                        'route' => 'admin.dashboard',
                        'module' => 'dashboard',
                        // Must be the ability the route itself demands (phase-02 §4:
                        // `can:dashboard.view`), or the link would be offered to someone the
                        // route then refuses — or hidden from someone who may open it.
                        'permission' => 'dashboard.view',
                        'match' => 'admin.dashboard',
                    ],
                    [
                        'label' => 'Users',
                        'icon' => 'users',
                        'route' => 'admin.users.index',
                        'module' => 'users',
                        'permission' => 'users.view_any',
                    ],
                    [
                        'label' => 'Roles',
                        'icon' => 'shield-check',
                        'route' => 'admin.roles.index',
                        'module' => 'roles',
                        'permission' => 'roles.view_any',
                    ],
                    [
                        'label' => 'Permissions',
                        'icon' => 'key',
                        'route' => 'admin.permissions.index',
                        'module' => 'permissions',
                        'permission' => 'permissions.view_any',
                    ],
                    [
                        'label' => 'Modules',
                        'icon' => 'puzzle-piece',
                        'route' => 'admin.modules.index',
                        'module' => 'modules',
                        'permission' => 'modules.view_any',
                    ],
                    [
                        'label' => 'Activity Log',
                        'icon' => 'clipboard-document-list',
                        'route' => 'admin.activity-log.index',
                        'module' => 'activity_log',
                        // view_logs, not view_any: the route middleware, the controller and this
                        // item must state one rule, and `can:activity_log.view_logs` is the one
                        // routes/admin.php enforces (a log module's read ability is LOGS).
                        'permission' => 'activity_log.view_logs',
                    ],
                    [
                        'label' => 'Login History',
                        'icon' => 'finger-print',
                        'route' => 'admin.login-history.index',
                        'module' => 'login_history',
                        'permission' => 'login_history.view_logs',
                    ],
                    [
                        'label' => 'Settings',
                        'icon' => 'cog-6-tooth',
                        'route' => 'admin.settings.index',
                        'module' => 'settings',
                        // view, not view_any: phase-02 §4 gates `admin.settings.index` on
                        // `can:settings.view`, and this item must state the identical string
                        // (same reasoning as activity_log.view_logs above). The settings screen is
                        // one page per group rather than a list, so `view` is the read ability.
                        'permission' => 'settings.view',
                    ],
                    // Later phases: hidden until their routes exist.
                    [
                        'label' => 'Backups',
                        'icon' => 'server-stack',
                        'route' => 'admin.backups.index',
                        'module' => 'backups',
                        'permission' => 'backups.view_any',
                    ],
                ],
            ],

            [
                'key' => 'software_house',
                'label' => 'Software House',
                'icon' => 'folder',
                'items' => [
                    [
                        'label' => 'Leads',
                        'icon' => 'megaphone',
                        'route' => 'admin.leads.index',
                        'module' => 'leads',
                        // The route accepts leads.view: a sales executive sees the leads they own.
                        'permission' => 'leads.view',
                    ],
                    [
                        'label' => 'Clients',
                        'icon' => 'briefcase',
                        'route' => 'admin.clients.index',
                        'module' => 'clients',
                        'permission' => 'clients.view_any',
                    ],
                    [
                        'label' => 'Projects',
                        'icon' => 'folder',
                        'children' => [
                            [
                                'label' => 'All Projects',
                                'icon' => 'folder',
                                'route' => 'admin.projects.index',
                                'module' => 'projects',
                                'permission' => 'projects.view_any',
                            ],
                            [
                                'label' => 'Task board',
                                'icon' => 'view-columns',
                                'route' => 'admin.tasks.board',
                                'module' => 'tasks',
                                'permission' => 'tasks.view_any',
                            ],
                            // phase-11 §8.2. Under Projects, because that is the document the money
                            // is against. It carries its own `project_payments` slug rather than
                            // the `payments` umbrella (F-6.1), which belongs to phase-13's
                            // cross-source register.
                            [
                                'label' => 'Payments',
                                'icon' => 'banknotes',
                                'route' => 'admin.project-payments.index',
                                'module' => 'project_payments',
                                'permission' => 'project_payments.view_any',
                            ],
                        ],
                    ],
                    [
                        'label' => 'Tasks',
                        'icon' => 'check-circle',
                        'route' => 'admin.tasks.index',
                        'module' => 'tasks',
                        'permission' => 'tasks.view_any',
                    ],
                    [
                        'label' => 'Time Tracking',
                        'icon' => 'clock',
                        'route' => 'admin.time.index',
                        'module' => 'time_tracking',
                        // The route accepts time_tracking.view: a worker sees their own hours.
                        'permission' => 'time_tracking.view',
                    ],
                ],
            ],

            [
                'key' => 'hr',
                'label' => 'HR',
                'icon' => 'identification',
                'items' => [
                    [
                        'label' => 'Employees',
                        'icon' => 'identification',
                        'route' => 'admin.employees.index',
                        'module' => 'employees',
                        'permission' => 'employees.view_any',
                    ],
                    [
                        'label' => 'Departments',
                        'icon' => 'building-office-2',
                        'route' => 'admin.departments.index',
                        'module' => 'departments',
                        'permission' => 'departments.view_any',
                    ],
                    [
                        'label' => 'Attendance',
                        'icon' => 'calendar-days',
                        'route' => 'admin.attendance.index',
                        'module' => 'attendance',
                        'permission' => 'attendance.view_any',
                    ],
                    [
                        'label' => 'Leaves',
                        'icon' => 'calendar',
                        'route' => 'admin.leaves.index',
                        'module' => 'leaves',
                        'permission' => 'leaves.view_any',
                    ],
                    [
                        'label' => 'Leave Balances',
                        'icon' => 'scale',
                        'route' => 'admin.leave-balances.index',
                        'module' => 'leave_balances',
                        'permission' => 'leave_balances.view_any',
                    ],
                    [
                        'label' => 'Payroll',
                        'icon' => 'banknotes',
                        'route' => 'admin.payroll-runs.index',
                        'module' => 'payroll',
                        'permission' => 'payroll.view_any',
                    ],
                    // phase-07 §8: the screens this phase adds. Salary slips sit apart from payroll so an
                    // Accountant can read and print them without holding the right to lock a run.
                    [
                        'label' => 'Salary Slips',
                        'icon' => 'document-currency-dollar',
                        'route' => 'admin.payslips.index',
                        'module' => 'salary_slips',
                        'permission' => 'salary_slips.view_any',
                    ],
                    [
                        'label' => 'Salary Structures',
                        'icon' => 'banknotes',
                        'route' => 'admin.salary-structures.index',
                        'module' => 'salary_structures',
                        'permission' => 'salary_structures.view_any',
                    ],
                    [
                        'label' => 'Advances',
                        'icon' => 'credit-card',
                        'route' => 'admin.advances.index',
                        'module' => 'employee_advances',
                        'permission' => 'employee_advances.view_any',
                    ],
                    [
                        'label' => 'My HR',
                        'icon' => 'user-circle',
                        // Every one of these 404s without an employee record (§9), so the whole group is
                        // hidden rather than offering pages that cannot open.
                        'when' => static fn (User $user): bool => Employee::query()
                            ->where('user_id', $user->getKey())
                            ->exists(),
                        'children' => [
                            [
                                'label' => 'My Profile',
                                'icon' => 'user',
                                'route' => 'admin.my.profile',
                                'module' => 'employee_self_service',
                                'permission' => 'employee_self_service.view',
                            ],
                            [
                                'label' => 'My Attendance',
                                'icon' => 'clock',
                                'route' => 'admin.my.attendance.index',
                                'module' => 'employee_self_service',
                                'permission' => 'employee_self_service.view',
                            ],
                            [
                                'label' => 'My Leave',
                                'icon' => 'calendar',
                                'route' => 'admin.my.leave.index',
                                'module' => 'employee_self_service',
                                'permission' => 'employee_self_service.view',
                            ],
                            [
                                'label' => 'My Salary Slips',
                                'icon' => 'document-text',
                                'route' => 'admin.my.payslips.index',
                                'module' => 'employee_self_service',
                                'permission' => 'employee_self_service.view_financial',
                            ],
                            [
                                'label' => 'Approvals',
                                'icon' => 'inbox',
                                'route' => 'admin.my.approvals.index',
                                'module' => 'employee_self_service',
                                'permission' => 'leaves.approve',
                            ],
                        ],
                    ],
                    [
                        'label' => 'HR Setup',
                        'icon' => 'adjustments-horizontal',
                        'children' => [
                            [
                                'label' => 'Designations',
                                'icon' => 'identification',
                                'route' => 'admin.designations.index',
                                'module' => 'designations',
                                'permission' => 'designations.view_any',
                            ],
                            [
                                'label' => 'Work Shifts',
                                'icon' => 'clock',
                                'route' => 'admin.work-shifts.index',
                                'module' => 'work_shifts',
                                'permission' => 'work_shifts.view_any',
                            ],
                            [
                                'label' => 'Holidays',
                                'icon' => 'calendar-days',
                                'route' => 'admin.holidays.index',
                                'module' => 'holidays',
                                'permission' => 'holidays.view_any',
                            ],
                            [
                                'label' => 'Leave Types',
                                'icon' => 'tag',
                                'route' => 'admin.leave-types.index',
                                'module' => 'leave_types',
                                'permission' => 'leave_types.view_any',
                            ],
                            [
                                'label' => 'Salary Components',
                                'icon' => 'adjustments-horizontal',
                                'route' => 'admin.salary-components.index',
                                'module' => 'salary_components',
                                'permission' => 'salary_components.view_any',
                            ],
                        ],
                    ],
                ],
            ],

            [
                'key' => 'finance',
                'label' => 'Finance',
                'icon' => 'banknotes',
                'items' => [
                    [
                        'label' => 'Invoices',
                        'icon' => 'document-text',
                        'route' => 'admin.invoices.index',
                        'module' => 'invoices',
                        'permission' => 'invoices.view_any',
                    ],
                    // phase-13 §7.5. Two permissions, and-ed, because the route demands both: the
                    // register's whole content is amounts, so a version of it without them would be a
                    // list of reference numbers. Advertising only `view_any` would be a visible link
                    // that 403s.
                    [
                        'label' => 'Payments',
                        'icon' => 'credit-card',
                        'route' => 'admin.payments.index',
                        'module' => 'payments',
                        'permission' => ['payments.view_any', 'payments.view_financial'],
                    ],
                    [
                        'label' => 'Expenses',
                        'icon' => 'receipt-percent',
                        'route' => 'admin.expenses.index',
                        'module' => 'expenses',
                        'permission' => 'expenses.view_any',
                    ],
                    // phase-13 §8.8. Its own entry rather than a tab: a claim waiting for a decision
                    // is work somebody has to notice, and a queue nobody can see from the sidebar is a
                    // queue that grows.
                    [
                        'label' => 'Expense Approvals',
                        'icon' => 'check-badge',
                        'route' => 'admin.expenses.approvals',
                        'module' => 'expenses',
                        'permission' => 'expenses.approve',
                    ],
                    [
                        'label' => 'Income',
                        'icon' => 'arrow-trending-up',
                        'route' => 'admin.income.index',
                        'module' => 'income',
                        'permission' => 'income.view_any',
                    ],
                    [
                        'label' => 'Payment Methods',
                        'icon' => 'wallet',
                        'route' => 'admin.payment-methods.index',
                        'module' => 'payment_methods',
                        'permission' => 'payment_methods.view_any',
                    ],
                    // phase-13 §2.3. The module holds no amount, so somebody who maintains the list
                    // never has to be given sight of a figure to do it.
                    [
                        'label' => 'Finance Categories',
                        'icon' => 'tag',
                        'route' => 'admin.finance-categories.index',
                        'module' => 'finance_categories',
                        'permission' => 'finance_categories.view_any',
                    ],
                    // phase-13 §7.6. Double-gated at the route: the hub opens on
                    // `reports.view_reports`, and each report needs its source module's pair.
                    [
                        'label' => 'Finance Reports',
                        'icon' => 'chart-bar',
                        'route' => 'admin.reports.finance.index',
                        'module' => 'reports',
                        'permission' => 'reports.view_reports',
                    ],
                ],
            ],

            [
                'key' => 'collaborator',
                'label' => 'Collaborator',
                'icon' => 'user-group',
                'items' => [
                    [
                        'label' => 'Collaborators',
                        'icon' => 'user-group',
                        'route' => 'admin.collaborators.index',
                        'module' => 'collaborators',
                        'permission' => 'collaborators.view_any',
                    ],
                    // phase-08-09 §7.1. Its own entry rather than a tab on the list, because an
                    // application waiting for a decision is work somebody has to notice, and a queue
                    // nobody can see from the sidebar is a queue that grows.
                    [
                        'label' => 'Applications',
                        'icon' => 'inbox-arrow-down',
                        'route' => 'admin.collaborators.pending',
                        'module' => 'collaborators',
                        'permission' => 'collaborators.approve',
                    ],
                    [
                        'label' => 'Commissions',
                        'icon' => 'calculator',
                        'route' => 'admin.commissions.index',
                        'module' => 'collaborator_commissions',
                        'permission' => 'collaborator_commissions.view_any',
                    ],
                    // phase-10-12 §8.8. Its own entry rather than a tab: a receipt that earned
                    // nothing is invisible everywhere else, and the whole point of the screen is to
                    // find a misconfigured partner before they complain.
                    [
                        'label' => 'Commission Skips',
                        'icon' => 'exclamation-triangle',
                        'route' => 'admin.commission-skips.index',
                        'module' => 'collaborator_commissions',
                        'permission' => 'collaborator_commissions.view_reports',
                    ],
                    // phase-10-12 §8.8. A discrepancy is a decision waiting for a person, and a
                    // decision nobody can see from the sidebar is one that never gets made.
                    [
                        'label' => 'Discrepancies',
                        'icon' => 'exclamation-triangle',
                        'route' => 'admin.commission-discrepancies.index',
                        'module' => 'collaborator_commissions',
                        'permission' => 'collaborator_commissions.view_any',
                    ],
                    [
                        'label' => 'Wallets',
                        'icon' => 'wallet',
                        'route' => 'admin.wallets.index',
                        'module' => 'collaborator_wallets',
                        'permission' => 'collaborator_wallets.view_any',
                    ],
                    [
                        'label' => 'Payouts',
                        'icon' => 'banknotes',
                        'route' => 'admin.payouts.index',
                        'module' => 'collaborator_payouts',
                        'permission' => 'collaborator_payouts.view_any',
                    ],
                    [
                        'label' => 'Referrals',
                        'icon' => 'share',
                        'route' => 'admin.collaborator-referrals.index',
                        'module' => 'collaborator_referrals',
                        'permission' => 'collaborator_referrals.view_any',
                    ],
                    // phase-08-09 §7.3. Separate from Referrals because these rows carry IP addresses
                    // and user agents, which a role that may link a referral has no business reading.
                    [
                        'label' => 'Referral Visits',
                        'icon' => 'cursor-arrow-rays',
                        'route' => 'admin.referral-visits.index',
                        'module' => 'collaborator_referral_visits',
                        'permission' => 'collaborator_referral_visits.view_any',
                    ],
                ],
            ],

            [
                'key' => 'institute',
                'label' => 'Institute',
                'icon' => 'academic-cap',
                'items' => [
                    [
                        'label' => 'Courses',
                        'icon' => 'academic-cap',
                        'children' => [
                            [
                                'label' => 'All Courses',
                                'icon' => 'academic-cap',
                                'route' => 'admin.courses.index',
                                'module' => 'courses',
                                'permission' => 'courses.view_any',
                            ],
                            [
                                'label' => 'Categories',
                                'icon' => 'rectangle-stack',
                                'route' => 'admin.course-categories.index',
                                'module' => 'course_categories',
                                'permission' => 'course_categories.view_any',
                            ],
                            // phase-14-17 §8.4: there is deliberately no "Outline" entry here. The
                            // outline builder is a tab on one course's detail screen, so its route
                            // needs a `{course}` — and a nav item for a route it cannot build a URL
                            // for renders as dead text that looks like a broken link.
                            [
                                'label' => 'Materials',
                                'icon' => 'folder-open',
                                'route' => 'admin.course-materials.index',
                                'module' => 'course_materials',
                                'permission' => 'course_materials.view_any',
                            ],
                        ],
                    ],
                    [
                        'label' => 'Students',
                        'icon' => 'users',
                        'children' => [
                            [
                                'label' => 'All Students',
                                'icon' => 'users',
                                'route' => 'admin.students.index',
                                'module' => 'students',
                                'permission' => 'students.view_any',
                            ],
                            [
                                'label' => 'Admissions',
                                'icon' => 'user-plus',
                                'route' => 'admin.admissions.index',
                                'module' => 'admissions',
                                'permission' => 'admissions.view_any',
                            ],
                            [
                                'label' => 'Course Inquiries',
                                'icon' => 'question-mark-circle',
                                'route' => 'admin.course-inquiries.index',
                                'module' => 'course_inquiries',
                                'permission' => 'course_inquiries.view_any',
                            ],
                            [
                                // phase-14-17 §4.1, §8.6: its own entry because it is its own module
                                // — the front desk triages the public form without holding
                                // `students.create` until the day it converts one.
                                'label' => 'Applications',
                                'icon' => 'inbox-arrow-down',
                                'route' => 'admin.student-applications.index',
                                'module' => 'student_applications',
                                'permission' => 'student_applications.view_any',
                            ],
                            [
                                'label' => 'Demo Classes',
                                'icon' => 'video-camera',
                                'route' => 'admin.demo-classes.index',
                                'module' => 'demo_classes',
                                'permission' => 'demo_classes.view_any',
                            ],
                            [
                                'label' => 'ID Cards',
                                'icon' => 'identification',
                                'route' => 'admin.student-id-cards.index',
                                'module' => 'student_id_cards',
                                'permission' => 'student_id_cards.view_any',
                            ],
                        ],
                    ],
                    [
                        'label' => 'Teachers',
                        'icon' => 'presentation-chart-bar',
                        'route' => 'admin.teachers.index',
                        'module' => 'teachers',
                        'permission' => 'teachers.view_any',
                    ],
                    [
                        // phase-16 §4.1: its own module, so a branch administrator can be given the
                        // rooms without the batches that fill them.
                        'label' => 'Classrooms',
                        'icon' => 'building-office-2',
                        'route' => 'admin.classrooms.index',
                        'module' => 'classrooms',
                        'permission' => 'classrooms.view_any',
                    ],
                    [
                        'label' => 'Batches',
                        'icon' => 'squares-2x2',
                        'children' => [
                            [
                                'label' => 'All Batches',
                                'icon' => 'squares-2x2',
                                'route' => 'admin.batches.index',
                                'module' => 'batches',
                                'permission' => 'batches.view_any',
                            ],
                            [
                                'label' => 'Timetable',
                                'icon' => 'table-cells',
                                'route' => 'admin.timetable.index',
                                'module' => 'timetable',
                                'permission' => 'timetable.view_any',
                            ],
                            [
                                // phase-16 §8.14: the dated classes the timetable produced. Under
                                // `timetable` because that is the module that owns them — a class is
                                // an occurrence of a slot, not a thing of its own.
                                'label' => 'Classes',
                                'icon' => 'calendar-days',
                                'route' => 'admin.class-sessions.index',
                                'module' => 'timetable',
                                'permission' => 'timetable.view_any',
                            ],
                            [
                                'label' => 'Attendance',
                                'icon' => 'clipboard-document-check',
                                'route' => 'admin.student-attendance.index',
                                'module' => 'student_attendance',
                                'permission' => 'student_attendance.view_any',
                            ],
                            [
                                'label' => 'Progress',
                                'icon' => 'chart-bar',
                                'route' => 'admin.student-progress.index',
                                'module' => 'student_progress',
                                'permission' => 'student_progress.view_any',
                            ],
                        ],
                    ],
                    [
                        'label' => 'Fees',
                        'icon' => 'banknotes',
                        'children' => [
                            [
                                'label' => 'Student Fees',
                                'icon' => 'banknotes',
                                'route' => 'admin.student-fees.index',
                                'module' => 'student_fees',
                                'permission' => 'student_fees.view_any',
                            ],
                            // phase-10-12 §8.2. The money register — beside the charges it pays off,
                            // because `student_fee_payments` is an Institute module (its own
                            // `ModuleGroup`), not a collaborator one. It happens to be what triggers
                            // commission; that is not where it belongs on a menu.
                            [
                                'label' => 'Fee Receipts',
                                'icon' => 'receipt-percent',
                                'route' => 'admin.fee-payments.index',
                                'module' => 'student_fee_payments',
                                'permission' => 'student_fee_payments.view_any',
                            ],
                            // phase-18 §8.8. The cashier's worklist, not a fourth list of charges:
                            // who owes what today, this week, and how late.
                            [
                                'label' => 'Fee Collection',
                                'icon' => 'calculator',
                                'route' => 'admin.fee-collection.index',
                                'module' => 'student_fees',
                                'permission' => 'student_fees.view_reports',
                            ],
                            // phase-18 §4.1. Its own module because chasing and charging are different
                            // rights: a Receptionist may send a reminder and may not edit a fee.
                            [
                                'label' => 'Fee Reminders',
                                'icon' => 'bell-alert',
                                'route' => 'admin.fee-reminders.index',
                                'module' => 'fee_reminders',
                                'permission' => 'fee_reminders.view_any',
                            ],
                            // **`admin.installments.index` and `admin.fee-discounts.index` are gone
                            // from this menu, and no route of those names exists.** Phase 1 reserved
                            // them before the shape of the phase was known; §7 has neither, because an
                            // installment and a discount are only ever read in the context of the
                            // charge they belong to — a flat list of "all installments in the
                            // institute" answers no question anybody asks. A menu item whose route
                            // does not exist is a visible link that 404s, which is the same class of
                            // defect as D98 pointing the other way.
                        ],
                    ],
                    [
                        'label' => 'Assessments',
                        'icon' => 'clipboard-document',
                        'children' => [
                            [
                                'label' => 'Assignments',
                                'icon' => 'clipboard-document',
                                'route' => 'admin.assignments.index',
                                'module' => 'assignments',
                                'permission' => 'assignments.view_any',
                            ],
                            [
                                'label' => 'Exams',
                                'icon' => 'document-chart-bar',
                                'route' => 'admin.exams.index',
                                'module' => 'exams',
                                'permission' => 'exams.view_any',
                            ],
                            [
                                'label' => 'Results',
                                'icon' => 'trophy',
                                'route' => 'admin.results.index',
                                'module' => 'results',
                                'permission' => 'results.view_any',
                            ],
                            [
                                'label' => 'Grade Scales',
                                'icon' => 'academic-cap',
                                'route' => 'admin.grade-scales.index',
                                'module' => 'grade_scales',
                                'permission' => 'grade_scales.view_any',
                            ],
                            [
                                'label' => 'Certificates',
                                'icon' => 'check-badge',
                                'route' => 'admin.certificates.index',
                                'module' => 'certificates',
                                'permission' => 'certificates.view_any',
                            ],
                            [
                                // phase-19-23 §4.1. Its own module because `body_html` is powerful:
                                // a designer may hold the certificate layout with no sight of a
                                // student record, and whoever issues certificates all day needs no
                                // say in what HTML a PDF renderer is handed.
                                'label' => 'Print Templates',
                                'icon' => 'document-duplicate',
                                'route' => 'admin.print-templates.index',
                                'module' => 'print_templates',
                                'permission' => 'print_templates.view_any',
                            ],
                        ],
                    ],
                ],
            ],

            [
                'key' => 'website',
                'label' => 'Website',
                'icon' => 'globe-alt',
                'items' => [
                    // phase-03 §8 (F-6.7): content ABOUT the site lives under /admin/website. Every
                    // `permission` below is exactly the can: of the route it links to.
                    [
                        'label' => 'Website Overview',
                        'icon' => 'globe-alt',
                        'route' => 'admin.website.index',
                        'module' => 'website_sections',
                        'permission' => 'website_sections.view_any',
                        // Explicit: the default "admin.website.*" would light this item on every CMS screen.
                        'match' => 'admin.website.index',
                    ],
                    [
                        'label' => 'Sections',
                        'icon' => 'view-columns',
                        'route' => 'admin.website.sections.index',
                        // Required: the route has a {placement} parameter; without it the item renders as a non-link.
                        'params' => ['placement' => SectionPlacement::Home->value],
                        'module' => 'website_sections',
                        'permission' => 'website_sections.view_any',
                        'match' => ['admin.website.sections.*', 'admin.website.section-items.*', 'admin.website.statistics.*'],
                    ],
                    [
                        'label' => 'Menus',
                        'icon' => 'bars-3',
                        'route' => 'admin.website.menus.index',
                        'module' => 'menus',
                        'permission' => 'menus.view_any',
                        'match' => ['admin.website.menus.*', 'admin.website.menu-items.*'],
                    ],
                    [
                        'label' => 'Pages',
                        'icon' => 'document',
                        'route' => 'admin.website.pages.index',
                        'module' => 'pages',
                        'permission' => 'pages.view_any',
                    ],
                    [
                        'label' => 'CTA Blocks',
                        'icon' => 'megaphone',
                        'route' => 'admin.website.cta-blocks.index',
                        'module' => 'website_cta_blocks',
                        'permission' => 'website_cta_blocks.view_any',
                    ],
                    // Two flat items, not a parent with children: a rendered parent has no URL of its
                    // own, which SidebarVisibilityTest::every_rendered_item_points_at_a_url… refuses.
                    [
                        'label' => 'FAQs',
                        'icon' => 'question-mark-circle',
                        'route' => 'admin.website.faqs.index',
                        'module' => 'faqs',
                        'permission' => 'faqs.view_any',
                    ],
                    [
                        'label' => 'FAQ Categories',
                        'icon' => 'rectangle-stack',
                        'route' => 'admin.website.faq-categories.index',
                        'module' => 'faq_categories',
                        'permission' => 'faq_categories.view_any',
                    ],
                    [
                        'label' => 'Media Library',
                        'icon' => 'photo',
                        'route' => 'admin.website.media.index',
                        'module' => 'website_media',
                        'permission' => 'website_media.view_any',
                    ],
                    [
                        'label' => 'SEO',
                        'icon' => 'magnifying-glass',
                        'route' => 'admin.website.seo.index',
                        'module' => 'seo',
                        'permission' => 'seo.view_any',
                    ],

                    // Business entities the site renders stay at the top level (phase-04 §8, F-6.7). Flat items only:
                    // a rendered parent has no URL. Every `permission` is exactly the can: of the route it links to.
                    [
                        'label' => 'Services',
                        'icon' => 'wrench-screwdriver',
                        'route' => 'admin.services.index',
                        'module' => 'services',
                        'permission' => 'services.view_any',
                    ],
                    [
                        'label' => 'Service Categories',
                        'icon' => 'squares-2x2',
                        'route' => 'admin.service-categories.index',
                        'module' => 'service_categories',
                        'permission' => 'service_categories.view_any',
                    ],
                    [
                        'label' => 'Technologies',
                        'icon' => 'puzzle-piece',
                        'route' => 'admin.technologies.index',
                        'module' => 'technologies',
                        'permission' => 'technologies.view_any',
                    ],
                    [
                        'label' => 'Portfolio',
                        'icon' => 'photo',
                        'route' => 'admin.portfolio.index',
                        'module' => 'portfolio',
                        'permission' => 'portfolio.view_any',
                    ],
                    [
                        'label' => 'Portfolio Categories',
                        'icon' => 'rectangle-stack',
                        'route' => 'admin.portfolio-categories.index',
                        'module' => 'portfolio_categories',
                        'permission' => 'portfolio_categories.view_any',
                    ],
                    [
                        'label' => 'Team',
                        'icon' => 'user-group',
                        'route' => 'admin.team.index',
                        'module' => 'team',
                        'permission' => 'team.view_any',
                    ],
                    [
                        'label' => 'Testimonials',
                        'icon' => 'chat-bubble-left-right',
                        'route' => 'admin.testimonials.index',
                        'module' => 'testimonials',
                        'permission' => 'testimonials.view_any',
                    ],
                    [
                        'label' => 'Student Reviews',
                        'icon' => 'star',
                        'route' => 'admin.student-reviews.index',
                        'module' => 'student_reviews',
                        'permission' => 'student_reviews.view_any',
                    ],
                    [
                        'label' => 'Success Stories',
                        'icon' => 'sparkles',
                        'route' => 'admin.success-stories.index',
                        'module' => 'success_stories',
                        'permission' => 'success_stories.view_any',
                    ],
                    [
                        'label' => 'Blog Posts',
                        'icon' => 'newspaper',
                        'route' => 'admin.blog-posts.index',
                        'module' => 'blog_posts',
                        'permission' => 'blog_posts.view_any',
                    ],
                    [
                        'label' => 'Blog Categories',
                        'icon' => 'folder',
                        'route' => 'admin.blog-categories.index',
                        'module' => 'blog_categories',
                        'permission' => 'blog_categories.view_any',
                    ],
                    [
                        'label' => 'Blog Tags',
                        'icon' => 'tag',
                        'route' => 'admin.blog-tags.index',
                        'module' => 'blog_tags',
                        'permission' => 'blog_tags.view_any',
                    ],
                    [
                        'label' => 'Jobs',
                        'icon' => 'briefcase',
                        'route' => 'admin.jobs.index',
                        'module' => 'jobs',
                        'permission' => 'jobs.view_any',
                    ],
                    [
                        // `view`, not view_any: a hiring manager sees the applications of its own openings (§9.1.3).
                        'label' => 'Job Applications',
                        'icon' => 'inbox-stack',
                        'route' => 'admin.job-applications.index',
                        'module' => 'job_applications',
                        'permission' => 'job_applications.view',
                    ],
                    [
                        // `view`, not view_any: a reviewer sees what is assigned to it (§9.1.2).
                        'label' => 'Contact Inquiries',
                        'icon' => 'envelope',
                        'route' => 'admin.contact-inquiries.index',
                        'module' => 'contact_inquiries',
                        'permission' => 'contact_inquiries.view',
                    ],
                ],
            ],

            [
                'key' => 'workspace',
                'label' => 'Workspace',
                'icon' => 'rectangle-stack',
                'items' => [
                    [
                        'label' => 'Support Tickets',
                        'icon' => 'lifebuoy',
                        // phase-19-23 §7.6 names the route `admin.tickets.*`, not
                        // `admin.support-tickets.*` — the module slug and the URL differ, and the
                        // placeholder here had the slug.
                        'route' => 'admin.tickets.index',
                        'module' => 'support_tickets',
                        // Either permission: §9.4 gives `view_any` the queue and `view` their own,
                        // and the controller narrows the query rather than the menu. Gating on
                        // `view_any` alone would hide the screen from the staff it was built for.
                        'permission' => ['support_tickets.view_any', 'support_tickets.view'],
                        'match' => 'admin.tickets.*',
                    ],
                    [
                        'label' => 'Support Desks',
                        'icon' => 'building-office',
                        'route' => 'admin.ticket-departments.index',
                        'module' => 'ticket_departments',
                        'permission' => 'ticket_departments.view_any',
                    ],
                    [
                        'label' => 'Meetings',
                        'icon' => 'video-camera',
                        'route' => 'admin.meetings.index',
                        'module' => 'meetings',
                        'permission' => ['meetings.view_any', 'meetings.view'],
                        'match' => 'admin.meetings.*',
                    ],
                    [
                        'label' => 'Messages',
                        'icon' => 'chat-bubble-left-ellipsis',
                        'route' => 'admin.messages.index',
                        'module' => 'messages',
                        // **`messages.view`, not `view_any`.** `view_any` is the compliance reader's
                        // permission and §9.4 grants it to nobody; gating the menu on it would hide
                        // the messaging screen from everybody who is meant to use it. The screen
                        // itself shows only threads the viewer is in, whichever they hold.
                        'permission' => 'messages.view',
                        'match' => 'admin.messages.*',
                    ],
                    [
                        'label' => 'Notifications',
                        'icon' => 'bell',
                        'route' => 'admin.notifications.index',
                        'module' => 'notifications',
                        'permission' => 'notifications.view_any',
                        'match' => 'admin.notifications.*',
                    ],
                    [
                        'label' => 'Files',
                        'icon' => 'paper-clip',
                        'route' => 'admin.files.index',
                        'module' => 'files',
                        'permission' => 'files.view_any',
                    ],
                    [
                        'label' => 'Reports',
                        'icon' => 'chart-pie',
                        'route' => 'admin.reports.index',
                        'module' => 'reports',
                        'permission' => 'reports.view_any',
                    ],
                ],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Collaborator panel
    |--------------------------------------------------------------------------
    */

    /**
     * Every item is gated on `collaborators`, the business module `routes/collaborator.php` guards
     * the whole panel with (`module:collaborators`) — not on `collaborator_portal`.
     *
     * `collaborator_portal` is the permission *namespace* for this panel and is a core module, so it
     * can never be switched off; gating the menu on it would mean the sidebar kept advertising
     * links that the route's own `module:collaborators` middleware answers with a 403. The module
     * key names what actually closes the route, which is the rule phase-01 §6 states.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function collaboratorTree(): array
    {
        return [
            [
                'key' => 'collaborator',
                'label' => null,
                'icon' => null,
                'items' => [
                    [
                        'label' => 'Dashboard',
                        'icon' => 'home',
                        'route' => 'collaborator.dashboard',
                        'module' => 'collaborators',
                        'permission' => 'collaborator_portal.dashboard',
                        'match' => 'collaborator.dashboard',
                    ],
                    [
                        'label' => 'My Students',
                        'icon' => 'users',
                        'route' => 'collaborator.students.index',
                        'module' => 'collaborators',
                        'permission' => 'collaborator_portal.students',
                    ],
                    [
                        'label' => 'My Projects',
                        'icon' => 'folder',
                        'route' => 'collaborator.projects.index',
                        'module' => 'collaborators',
                        'permission' => 'collaborator_portal.projects',
                    ],
                    [
                        'label' => 'Tasks',
                        'icon' => 'check-circle',
                        'route' => 'collaborator.tasks.index',
                        'module' => 'collaborators',
                        'permission' => 'collaborator_portal.tasks',
                    ],
                    [
                        'label' => 'Wallet',
                        'icon' => 'wallet',
                        'route' => 'collaborator.wallet.index',
                        'module' => 'collaborators',
                        'permission' => 'collaborator_portal.wallet',
                    ],
                    [
                        'label' => 'Commissions',
                        'icon' => 'calculator',
                        'route' => 'collaborator.commissions.index',
                        'module' => 'collaborators',
                        'permission' => 'collaborator_portal.student_commission',
                    ],
                    [
                        'label' => 'Payouts',
                        'icon' => 'banknotes',
                        'route' => 'collaborator.payouts.index',
                        'module' => 'collaborators',
                        // `payouts`, not `payout_request`: seeing what has been paid is not the same
                        // right as asking for more, and a partner who may not ask still has a history.
                        'permission' => 'collaborator_portal.payouts',
                    ],
                    [
                        'label' => 'Payout Accounts',
                        'icon' => 'credit-card',
                        'route' => 'collaborator.payout-accounts.index',
                        'module' => 'collaborators',
                        'permission' => 'collaborator_portal.payout_request',
                    ],
                    [
                        'label' => 'Statements',
                        'icon' => 'document-text',
                        'route' => 'collaborator.statement.index',
                        'module' => 'collaborators',
                        'permission' => 'collaborator_portal.statement_download',
                    ],
                    [
                        'label' => 'Files',
                        'icon' => 'paper-clip',
                        'route' => 'collaborator.files.index',
                        'module' => 'collaborators',
                        'permission' => 'collaborator_portal.files_download',
                    ],
                    [
                        'label' => 'Meetings',
                        'icon' => 'video-camera',
                        'route' => 'collaborator.meetings.index',
                        'module' => 'meetings',
                        'permission' => 'collaborator_portal.meetings',
                    ],
                    [
                        'label' => 'Messages',
                        'icon' => 'chat-bubble-left-ellipsis',
                        'route' => 'collaborator.messages.index',
                        'module' => 'messages',
                        'permission' => 'collaborator_portal.messages',
                    ],
                    [
                        'label' => 'Support',
                        'icon' => 'lifebuoy',
                        'route' => 'collaborator.tickets.index',
                        'module' => 'support_tickets',
                        'permission' => 'collaborator_portal.support_tickets',
                        'match' => 'collaborator.tickets.*',
                    ],
                    [
                        'label' => 'Notifications',
                        'icon' => 'bell',
                        'route' => 'collaborator.notifications.index',
                        'module' => 'notifications',
                        'permission' => 'collaborator_portal.notifications',
                        'match' => 'collaborator.notifications.*',
                    ],
                ],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Student panel
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function studentTree(): array
    {
        return [
            [
                'key' => 'student',
                'label' => null,
                'icon' => null,
                'items' => [
                    [
                        'label' => 'Dashboard',
                        'icon' => 'home',
                        'route' => 'student.dashboard',
                        'module' => 'student_portal',
                        'permission' => 'student_portal.dashboard',
                        'match' => 'student.dashboard',
                    ],
                    [
                        'label' => 'My Courses',
                        'icon' => 'academic-cap',
                        'route' => 'student.courses.index',
                        'module' => 'student_portal',
                        'permission' => 'student_portal.courses',
                    ],
                    [
                        'label' => 'Timetable',
                        'icon' => 'table-cells',
                        'route' => 'student.timetable.index',
                        'module' => 'student_portal',
                        'permission' => 'student_portal.timetable',
                    ],
                    [
                        'label' => 'Attendance',
                        'icon' => 'clipboard-document-check',
                        'route' => 'student.attendance.index',
                        'module' => 'student_portal',
                        'permission' => 'student_portal.attendance',
                    ],
                    [
                        // phase-17 §7.8: Phase 1 reserved `student_portal.progress` but never gave it
                        // an entry, so the screen would have been reachable only by typing the URL.
                        'label' => 'Progress',
                        'icon' => 'chart-bar',
                        'route' => 'student.progress.index',
                        'module' => 'student_portal',
                        'permission' => 'student_portal.progress',
                    ],
                    [
                        'label' => 'Materials',
                        'icon' => 'folder-open',
                        'route' => 'student.materials.index',
                        'module' => 'student_portal',
                        'permission' => 'student_portal.materials',
                    ],
                    [
                        'label' => 'Assignments',
                        'icon' => 'clipboard-document',
                        'route' => 'student.assignments.index',
                        'module' => 'student_portal',
                        'permission' => 'student_portal.assignments',
                    ],
                    [
                        'label' => 'Exams',
                        'icon' => 'document-chart-bar',
                        'route' => 'student.exams.index',
                        'module' => 'student_portal',
                        'permission' => 'student_portal.exams',
                    ],
                    [
                        'label' => 'Results',
                        'icon' => 'trophy',
                        'route' => 'student.results.index',
                        'module' => 'student_portal',
                        'permission' => 'student_portal.results',
                    ],
                    [
                        'label' => 'Certificates',
                        'icon' => 'check-badge',
                        'route' => 'student.certificates.index',
                        'module' => 'student_portal',
                        'permission' => 'student_portal.certificates',
                    ],
                    [
                        'label' => 'Student card',
                        'icon' => 'identification',
                        'route' => 'student.id-card.show',
                        'module' => 'student_portal',
                        'permission' => 'student_portal.id_card',
                    ],
                    [
                        'label' => 'Fees',
                        'icon' => 'banknotes',
                        'route' => 'student.fees.index',
                        'module' => 'student_portal',
                        'permission' => 'student_portal.fees',
                    ],
                    [
                        'label' => 'Meetings',
                        'icon' => 'video-camera',
                        'route' => 'student.meetings.index',
                        'module' => 'meetings',
                        'permission' => 'student_portal.meetings',
                    ],
                    [
                        'label' => 'Messages',
                        'icon' => 'chat-bubble-left-ellipsis',
                        'route' => 'student.messages.index',
                        'module' => 'messages',
                        'permission' => 'student_portal.messages',
                    ],
                    [
                        'label' => 'Support',
                        'icon' => 'lifebuoy',
                        // §7.6 names the route `{panel}.tickets.*`, not `.support-tickets.*` —
                        // the module slug and the URL differ, and the placeholder here had the slug.
                        'route' => 'student.tickets.index',
                        'module' => 'support_tickets',
                        'permission' => 'student_portal.support_tickets',
                    ],
                    [
                        'label' => 'Notifications',
                        'icon' => 'bell',
                        'route' => 'student.notifications.index',
                        'module' => 'notifications',
                        'permission' => 'student_portal.notifications',
                        'match' => 'student.notifications.*',
                    ],
                ],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Teacher panel
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function teacherTree(): array
    {
        return [
            [
                'key' => 'teacher',
                'label' => null,
                'icon' => null,
                'items' => [
                    [
                        'label' => 'Dashboard',
                        'icon' => 'home',
                        'route' => 'teacher.dashboard',
                        'module' => 'teacher_portal',
                        'permission' => 'teacher_portal.dashboard',
                        'match' => 'teacher.dashboard',
                    ],
                    [
                        'label' => 'My Batches',
                        'icon' => 'squares-2x2',
                        'route' => 'teacher.batches.index',
                        'module' => 'teacher_portal',
                        'permission' => 'teacher_portal.batches',
                    ],
                    [
                        'label' => 'My Students',
                        'icon' => 'users',
                        'route' => 'teacher.students.index',
                        'module' => 'teacher_portal',
                        'permission' => 'teacher_portal.students',
                    ],
                    [
                        'label' => 'Timetable',
                        'icon' => 'table-cells',
                        'route' => 'teacher.timetable.index',
                        'module' => 'teacher_portal',
                        'permission' => 'teacher_portal.timetable',
                    ],
                    [
                        // phase-16 §7.9: whether somebody turned up to a trial class is a fact only
                        // the person who took it has, so the teacher needs to be able to reach it.
                        'label' => 'Demo Classes',
                        'icon' => 'video-camera',
                        'route' => 'teacher.demo-classes.index',
                        'module' => 'teacher_portal',
                        'permission' => 'teacher_portal.demo_classes',
                    ],
                    [
                        'label' => 'Attendance',
                        'icon' => 'clipboard-document-check',
                        'route' => 'teacher.attendance.index',
                        'module' => 'teacher_portal',
                        'permission' => 'teacher_portal.attendance',
                    ],
                    [
                        'label' => 'Materials',
                        'icon' => 'folder-open',
                        'route' => 'teacher.materials.index',
                        'module' => 'teacher_portal',
                        'permission' => 'teacher_portal.materials',
                    ],
                    [
                        'label' => 'Assignments',
                        'icon' => 'clipboard-document',
                        'route' => 'teacher.assignments.index',
                        'module' => 'teacher_portal',
                        'permission' => 'teacher_portal.assignments',
                    ],
                    [
                        'label' => 'Exams',
                        'icon' => 'document-chart-bar',
                        'route' => 'teacher.exams.index',
                        'module' => 'teacher_portal',
                        'permission' => 'teacher_portal.exams',
                    ],
                    [
                        'label' => 'Results',
                        'icon' => 'trophy',
                        'route' => 'teacher.results.index',
                        'module' => 'teacher_portal',
                        'permission' => 'teacher_portal.results',
                    ],
                    [
                        'label' => 'Meetings',
                        'icon' => 'video-camera',
                        'route' => 'teacher.meetings.index',
                        'module' => 'meetings',
                        'permission' => 'teacher_portal.meetings',
                    ],
                    [
                        'label' => 'Messages',
                        'icon' => 'chat-bubble-left-ellipsis',
                        'route' => 'teacher.messages.index',
                        'module' => 'messages',
                        'permission' => 'teacher_portal.messages',
                    ],
                    [
                        'label' => 'Support',
                        'icon' => 'lifebuoy',
                        'route' => 'teacher.tickets.index',
                        'module' => 'support_tickets',
                        'permission' => 'teacher_portal.support_tickets',
                        'match' => 'teacher.tickets.*',
                    ],
                    [
                        'label' => 'Notifications',
                        'icon' => 'bell',
                        'route' => 'teacher.notifications.index',
                        'module' => 'notifications',
                        'permission' => 'teacher_portal.notifications',
                        'match' => 'teacher.notifications.*',
                    ],
                ],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Client panel
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * The client panel nav (phase-05 section 8.10).
     *
     * Every entry names a route that exists and the permission that route's can: really checks, so an
     * item is hidden rather than leading to a 403. Later phases that add a portal section append here
     * and grant its client_portal.* permission to the Client role.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function clientTree(): array
    {
        return [
            [
                'key' => 'client',
                'label' => null,
                'icon' => null,
                'items' => [
                    [
                        'label' => 'Dashboard',
                        'icon' => 'home',
                        'route' => 'client.dashboard',
                        'module' => 'client_portal',
                        'permission' => 'client_portal.dashboard',
                        'match' => 'client.dashboard',
                    ],
                    [
                        'label' => 'My Projects',
                        'icon' => 'folder',
                        'route' => 'client.projects.index',
                        'module' => 'client_portal',
                        'permission' => 'client_portal.projects',
                    ],
                    [
                        'label' => 'Milestones',
                        'icon' => 'flag',
                        'route' => 'client.milestones.index',
                        'module' => 'client_portal',
                        'permission' => 'client_portal.milestones',
                    ],
                    [
                        'label' => 'Tasks',
                        'icon' => 'check-circle',
                        'route' => 'client.tasks.index',
                        'module' => 'client_portal',
                        'permission' => 'client_portal.tasks',
                    ],
                    [
                        'label' => 'Documents',
                        'icon' => 'document-text',
                        'route' => 'client.documents.index',
                        'module' => 'client_portal',
                        'permission' => 'client_portal.documents',
                    ],
                    [
                        'label' => 'Files',
                        'icon' => 'paper-clip',
                        'route' => 'client.files.index',
                        'module' => 'client_portal',
                        'permission' => 'client_portal.files',
                    ],
                    [
                        'label' => 'Invoices',
                        'icon' => 'banknotes',
                        'route' => 'client.invoices.index',
                        'module' => 'client_portal',
                        'permission' => 'client_portal.invoices',
                    ],
                    [
                        'label' => 'Payments',
                        'icon' => 'credit-card',
                        'route' => 'client.payments.index',
                        'module' => 'client_portal',
                        'permission' => 'client_portal.payments',
                    ],
                    [
                        'label' => 'Meetings',
                        'icon' => 'video-camera',
                        'route' => 'client.meetings.index',
                        'module' => 'meetings',
                        'permission' => 'client_portal.meetings',
                    ],
                    [
                        'label' => 'Messages',
                        'icon' => 'chat',
                        'route' => 'client.messages.index',
                        'module' => 'messages',
                        'permission' => 'client_portal.messages',
                    ],
                    [
                        'label' => 'Support',
                        'icon' => 'lifebuoy',
                        'route' => 'client.tickets.index',
                        'module' => 'support_tickets',
                        'permission' => 'client_portal.tickets',
                    ],
                    [
                        'label' => 'Notifications',
                        'icon' => 'bell',
                        'route' => 'client.notifications.index',
                        'module' => 'notifications',
                        'permission' => 'client_portal.notifications',
                    ],
                    [
                        'label' => 'My Profile',
                        'icon' => 'user-circle',
                        'route' => 'client.profile.edit',
                        'module' => 'client_portal',
                        'permission' => 'client_portal.profile',
                    ],
                ],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Filtering
    |--------------------------------------------------------------------------
    */

    /**
     * Apply the three gates to one declared item; null means "do not render".
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private static function resolve(array $item, User $user): ?array
    {
        $module = isset($item['module']) ? (string) $item['module'] : null;

        if ($module !== null && ! Modules::enabled($module)) {
            return null;
        }

        // A list is and-ed, exactly as multiple `can:` entries on one route are. An item that
        // advertised only the first of a route's two permissions would be a visible link that 403s —
        // which is the whole failure `PermissionStringConsistencyTest` exists to catch. The cross-source
        // payments register is the first route in the system that needs two (phase-13 §7.5).
        $permission = self::permissionOf($item);

        foreach ((array) ($permission ?? []) as $required) {
            if (! self::allows($user, (string) $required)) {
                return null;
            }
        }

        // Gate 4: a condition the permission cannot express. Employee self-service is the first case —
        // holding `employee_self_service.view` does not mean there *is* an employee record behind the
        // login, and those screens answer 404 when there is not (phase-07 §9). A menu must not offer a
        // page the user cannot open.
        if (isset($item['when']) && is_callable($item['when']) && ! ($item['when'])($user)) {
            return null;
        }

        $children = [];

        foreach ($item['children'] ?? [] as $child) {
            if (! is_array($child)) {
                continue;
            }

            $resolved = self::resolve($child, $user);

            if ($resolved !== null) {
                $children[] = $resolved;
            }
        }

        $route = isset($item['route']) ? (string) $item['route'] : null;
        $hasRoute = $route !== null && self::routeExists($route);

        // A leaf needs a registered route; a parent needs at least one surviving child.
        if (! $hasRoute && $children === []) {
            return null;
        }

        $resolved = [
            'label' => (string) $item['label'],
            'icon' => isset($item['icon']) ? (string) $item['icon'] : null,
            'route' => $hasRoute ? $route : null,
            'url' => $hasRoute ? self::url($route, $item['params'] ?? []) : null,
            'module' => $module,
            'permission' => $permission,
            'group' => isset($item['group']) ? (string) $item['group'] : null,
            'match' => $item['match'] ?? null,
            'children' => $children,
        ];

        $resolved['active'] = self::isActive($resolved);

        return $resolved;
    }

    /**
     * The item's permission rule: one name, a list of names that are and-ed, or null.
     *
     * @param  array<string, mixed>  $item
     * @return string|list<string>|null
     */
    public static function permissionOf(array $item): string|array|null
    {
        $permission = $item['permission'] ?? null;

        if (is_array($permission)) {
            $names = array_values(array_filter(array_map(
                static fn (mixed $name): string => (string) $name,
                $permission,
            ), static fn (string $name): bool => $name !== ''));

            return $names === [] ? null : $names;
        }

        if (is_string($permission) && $permission !== '') {
            return $permission;
        }

        return null;
    }

    private static function allows(User $user, string $permission): bool
    {
        try {
            return $user->can($permission);
        } catch (Throwable) {
            return false;
        }
    }

    private static function routeExists(string $name): bool
    {
        try {
            return Route::has($name);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<array-key, mixed>  $params
     */
    private static function url(string $name, array $params = []): ?string
    {
        try {
            return route($name, $params);
        } catch (Throwable) {
            // Route needs parameters we do not have: render it as a non-link.
            return null;
        }
    }

    /**
     * Active-state patterns: an explicit `match`, otherwise "panel.resource.*".
     *
     * @param  array<string, mixed>  $item
     * @return array<int, string>
     */
    private static function patternsFor(array $item): array
    {
        if (! empty($item['match'])) {
            return array_map(
                static fn (mixed $pattern): string => (string) $pattern,
                is_array($item['match']) ? $item['match'] : [$item['match']],
            );
        }

        $route = isset($item['route']) ? (string) $item['route'] : '';

        if ($route === '') {
            return [];
        }

        $segments = explode('.', $route);

        if (count($segments) < 3) {
            return [$route];
        }

        array_pop($segments);

        return [implode('.', $segments).'.*'];
    }

    private static function currentRouteName(): ?string
    {
        try {
            return Route::currentRouteName();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Which tree belongs to this user.
     */
    private static function panelFor(User $user): string
    {
        try {
            if (method_exists($user, 'primaryPanel')) {
                return self::panelKey($user->primaryPanel());
            }
        } catch (Throwable) {
            // fall through to admin
        }

        return 'admin';
    }

    private static function panelKey(PanelType|string|null $panel): string
    {
        if ($panel instanceof PanelType) {
            return $panel->value;
        }

        return $panel === null || $panel === '' ? 'admin' : strtolower($panel);
    }
}
