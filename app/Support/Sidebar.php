<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Cms\SectionPlacement;
use App\Enums\PanelType;
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
 * A parent with children survives when at least one child survives; a group with no surviving
 * items disappears entirely.
 *
 * Every later phase is already declared below. Because gate 2 hides anything whose route has not
 * been registered yet, the future entries stay invisible until the phase that adds their routes —
 * no edit to this file is needed then.
 *
 * Declared item keys (all optional except label):
 *   label, icon, route, params, module, permission, children, group, match
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
                        'label' => 'Payroll',
                        'icon' => 'banknotes',
                        'route' => 'admin.payroll.index',
                        'module' => 'payroll',
                        'permission' => 'payroll.view_any',
                    ],
                    // phase-07 §8: the screens this phase adds. Salary slips sit apart from payroll so an
                    // Accountant can read and print them without holding the right to lock a run.
                    [
                        'label' => 'Salary Slips',
                        'icon' => 'document-currency-dollar',
                        'route' => 'admin.salary-slips.index',
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
                        'route' => 'admin.employee-advances.index',
                        'module' => 'employee_advances',
                        'permission' => 'employee_advances.view_any',
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
                    [
                        'label' => 'Payments',
                        'icon' => 'credit-card',
                        'route' => 'admin.payments.index',
                        'module' => 'payments',
                        'permission' => 'payments.view_any',
                    ],
                    [
                        'label' => 'Expenses',
                        'icon' => 'receipt-percent',
                        'route' => 'admin.expenses.index',
                        'module' => 'expenses',
                        'permission' => 'expenses.view_any',
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
                    [
                        'label' => 'Commissions',
                        'icon' => 'calculator',
                        'route' => 'admin.collaborator-commissions.index',
                        'module' => 'collaborator_commissions',
                        'permission' => 'collaborator_commissions.view_any',
                    ],
                    [
                        'label' => 'Commission Settings',
                        'icon' => 'adjustments-horizontal',
                        'route' => 'admin.collaborator-commission-settings.index',
                        'module' => 'collaborator_commission_settings',
                        'permission' => 'collaborator_commission_settings.view_any',
                    ],
                    [
                        'label' => 'Wallets',
                        'icon' => 'wallet',
                        'route' => 'admin.collaborator-wallets.index',
                        'module' => 'collaborator_wallets',
                        'permission' => 'collaborator_wallets.view_any',
                    ],
                    [
                        'label' => 'Payouts',
                        'icon' => 'banknotes',
                        'route' => 'admin.collaborator-payouts.index',
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
                            [
                                'label' => 'Outline',
                                'icon' => 'list-bullet',
                                'route' => 'admin.course-outline.index',
                                'module' => 'course_outline',
                                'permission' => 'course_outline.view_any',
                            ],
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
                            [
                                'label' => 'Installments',
                                'icon' => 'queue-list',
                                'route' => 'admin.installments.index',
                                'module' => 'installments',
                                'permission' => 'installments.view_any',
                            ],
                            [
                                'label' => 'Discounts',
                                'icon' => 'tag',
                                'route' => 'admin.fee-discounts.index',
                                'module' => 'fee_discounts',
                                'permission' => 'fee_discounts.view_any',
                            ],
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
                                'label' => 'Certificates',
                                'icon' => 'check-badge',
                                'route' => 'admin.certificates.index',
                                'module' => 'certificates',
                                'permission' => 'certificates.view_any',
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
                        'route' => 'admin.support-tickets.index',
                        'module' => 'support_tickets',
                        'permission' => 'support_tickets.view_any',
                    ],
                    [
                        'label' => 'Meetings',
                        'icon' => 'video-camera',
                        'route' => 'admin.meetings.index',
                        'module' => 'meetings',
                        'permission' => 'meetings.view_any',
                    ],
                    [
                        'label' => 'Messages',
                        'icon' => 'chat-bubble-left-ellipsis',
                        'route' => 'admin.messages.index',
                        'module' => 'messages',
                        'permission' => 'messages.view_any',
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
                        'permission' => 'collaborator_portal.payout_request',
                    ],
                    [
                        'label' => 'Statements',
                        'icon' => 'document-text',
                        'route' => 'collaborator.statements.index',
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
                        'module' => 'collaborators',
                        'permission' => 'collaborator_portal.meetings',
                    ],
                    [
                        'label' => 'Messages',
                        'icon' => 'chat-bubble-left-ellipsis',
                        'route' => 'collaborator.messages.index',
                        'module' => 'collaborators',
                        'permission' => 'collaborator_portal.messages',
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
                        'module' => 'student_portal',
                        'permission' => 'student_portal.meetings',
                    ],
                    [
                        'label' => 'Messages',
                        'icon' => 'chat-bubble-left-ellipsis',
                        'route' => 'student.messages.index',
                        'module' => 'student_portal',
                        'permission' => 'student_portal.messages',
                    ],
                    [
                        'label' => 'Support',
                        'icon' => 'lifebuoy',
                        'route' => 'student.support-tickets.index',
                        'module' => 'student_portal',
                        'permission' => 'student_portal.support_tickets',
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
                        'module' => 'teacher_portal',
                        'permission' => 'teacher_portal.meetings',
                    ],
                    [
                        'label' => 'Messages',
                        'icon' => 'chat-bubble-left-ellipsis',
                        'route' => 'teacher.messages.index',
                        'module' => 'teacher_portal',
                        'permission' => 'teacher_portal.messages',
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
                        'module' => 'client_portal',
                        'permission' => 'client_portal.meetings',
                    ],
                    [
                        'label' => 'Messages',
                        'icon' => 'chat',
                        'route' => 'client.messages.index',
                        'module' => 'client_portal',
                        'permission' => 'client_portal.messages',
                    ],
                    [
                        'label' => 'Support',
                        'icon' => 'lifebuoy',
                        'route' => 'client.tickets.index',
                        'module' => 'client_portal',
                        'permission' => 'client_portal.tickets',
                    ],
                    [
                        'label' => 'Notifications',
                        'icon' => 'bell',
                        'route' => 'client.notifications.index',
                        'module' => 'client_portal',
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

        $permission = isset($item['permission']) ? (string) $item['permission'] : null;

        if ($permission !== null && ! self::allows($user, $permission)) {
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
