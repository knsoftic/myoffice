<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\PanelType;
use App\Support\Modules;
use App\Support\Sidebar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The navigation tree (phase-01 §9, §10 "sidebar shows only permitted items"; CLAUDE.md §6).
 *
 * An item survives three gates: its module is enabled, its route is registered, and the user holds
 * its permission. Hiding an item is never the security boundary — the route middleware is — but a
 * sidebar that offers what the backend refuses is a bug in its own right.
 */
final class SidebarVisibilityTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    /*
    |--------------------------------------------------------------------------
    | Module gate
    |--------------------------------------------------------------------------
    */

    /**
     * `collaborators` is the one non-core module that owns a Phase-1 route: it is what
     * `routes/collaborator.php` guards the panel with (`module:collaborators`), and therefore what
     * the collaborator menu items declare. The four `*_portal` namespaces are core — they are
     * permission prefixes for the panels, not feature areas — so they can never hide anything, and
     * an item gated on one of them would go on advertising a link the route already 403s.
     */
    #[Test]
    public function disabling_a_module_hides_its_sidebar_item(): void
    {
        $collaborator = $this->seededDemoUser('Collaborator');

        $this->assertContains(
            'Dashboard',
            $this->labels(Sidebar::forUser($collaborator)),
            'The collaborator dashboard is the Phase-1 item the collaborators module owns.'
        );

        $this->switchModule('collaborators', false);

        $this->assertNotContains(
            'Dashboard',
            $this->labels(Sidebar::forUser($collaborator)),
            'An item whose module is disabled must disappear from the sidebar.'
        );

        // And the menu agrees with the route: the same switch answers the link with a 403.
        $this->actingAs($collaborator)->get('/collaborator')->assertForbidden();
    }

    #[Test]
    public function re_enabling_a_module_brings_its_sidebar_item_back(): void
    {
        $collaborator = $this->seededDemoUser('Collaborator');

        $this->switchModule('collaborators', false);
        $this->assertSame([], Sidebar::forUser($collaborator));

        $this->switchModule('collaborators', true);
        $this->assertContains('Dashboard', $this->labels(Sidebar::forUser($collaborator)));
    }

    /**
     * The inverse, which is the reason the module key moved: a panel's permission namespace is core,
     * so no switch on it can ever take the menu away from the people who live in that panel.
     */
    #[Test]
    public function a_portal_namespace_can_never_hide_a_panels_sidebar(): void
    {
        foreach (['Collaborator', 'Student', 'Teacher', 'Client'] as $role) {
            $user = $this->seededDemoUser($role);
            $portal = Str::snake($role).'_portal';

            // Forced straight into the row, because the switchboard itself refuses a core module.
            DB::table('modules')->where('slug', $portal)->update(['is_enabled' => false]);
            Modules::flushCache();

            $this->assertContains(
                'Dashboard',
                $this->labels(Sidebar::forUser($user)),
                sprintf('%s is core: switching it off must not empty the %s sidebar.', $portal, $role)
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Permission gate
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_sidebar_shows_only_the_items_the_user_holds_a_permission_for(): void
    {
        $user = $this->createUserWithPermissions(['users.view_any', 'roles.view_any']);

        $labels = $this->labels(Sidebar::forUser($user));

        $this->assertSame(['Users', 'Roles'], $labels);
    }

    #[Test]
    public function a_permission_less_user_gets_an_empty_sidebar(): void
    {
        $user = $this->createUserWithPermissions([]);

        $this->assertSame([], Sidebar::forUser($user));
    }

    #[Test]
    public function a_guest_gets_an_empty_sidebar(): void
    {
        $this->assertSame([], Sidebar::forUser(null));
    }

    /*
    |--------------------------------------------------------------------------
    | Route gate
    |--------------------------------------------------------------------------
    */

    /**
     * Every later phase is already declared in the tree; gate 2 (`Route::has`) keeps those entries
     * invisible until the phase that registers their routes. A Super Admin — who passes the
     * permission gate for everything — is the sharpest way to observe that.
     */
    #[Test]
    public function items_whose_routes_do_not_exist_yet_stay_hidden(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $labels = $this->labels(Sidebar::forUser($superAdmin));

        $this->assertSame(
            [
                'Dashboard',
                'Users',
                'Roles',
                'Permissions',
                'Modules',
                'Activity Log',
                'Login History',
                'Settings',
                // phase-05 §7: the CRM entries, now that leads and clients have routes.
                'Leads',
                'Clients',
                // phase-06 §7: delivery, now that projects, the board, tasks and time have routes.
                // `Projects` is a parent: it carries children rather than a route of its own.
                'Projects',
                'All Projects',
                'Task board',
                // phase-11 §8.2. The project money register, under Projects — that is the document
                // the money is against.
                'Payments',
                'Tasks',
                'Time Tracking',
                // phase-07 §7-§8: HR. `My HR` is deliberately absent — its fourth gate asks whether the
                // user *is* an employee, and a Super Admin with no employee record is not offered pages
                // that would answer 404 (§9). `HR Setup` is a parent, like `Projects`.
                'Employees',
                'Departments',
                'Attendance',
                'Leaves',
                'Leave Balances',
                'Payroll',
                'Salary Slips',
                'Salary Structures',
                'Advances',
                'HR Setup',
                'Designations',
                'Work Shifts',
                'Holidays',
                'Leave Types',
                'Salary Components',
                // phase-13 §8: the finance group, now that every one of its screens has a route. The
                // second 'Payments' is the cross-source register and is a different screen from the
                // project one above — same word, two documents.
                'Invoices',
                'Payments',
                'Expenses',
                'Expense Approvals',
                'Income',
                'Payment Methods',
                'Finance Categories',
                'Finance Reports',
                // phase-08-09 §7.1: the two collaborator entries whose routes now exist. The other four
                // in that group — Commissions, Commission Settings, Wallets, Payouts — and `Referral
                // Visits` stay hidden behind gate 2 until the phase that registers their routes.
                'Collaborators',
                'Applications',
                // phase-10-12 §7.4 / §8.8. Phase 8 declared a "Commissions" entry against the route
                // name `admin.collaborator-commissions.index`, which never existed — gate 2 hid it for
                // two phases. It now points at the route the contract actually names.
                'Commissions',
                'Commission Skips',
                // phase-10-12 §8.5-§8.7: the reconciler's report, the wallet register and the payout
                // queue, hidden behind gate 2 until Phase 12 registered their routes.
                'Discrepancies',
                'Wallets',
                'Payouts',
                'Referral Visits',
                // phase-14-17 §7.1: the catalogue. `Courses` is a parent, like `Projects` and
                // `HR Setup` — it carries `All Courses` and `Categories` rather than a route.
                'Courses',
                'All Courses',
                'Categories',
                // phase-19-23 §7.1: the material library, under Courses because a material belongs to
                // the course it distributes — this is an act of distribution, not part of the syllabus.
                'Materials',
                // phase-14-17 §7.3-§7.4: the admission pipeline. `Students` is a parent like
                // `Courses`; `Applications` is its own entry because the §67 inbox is its own module
                // (§4.1) — a receptionist triages it without holding `students.create`.
                'Students',
                'All Students',
                'Admissions',
                'Course Inquiries',
                'Applications',
                'Demo Classes',
                // phase-14-17 §7.5-§7.6: scheduling. `Teachers` and `Classrooms` are their own
                // entries — a room is its own module (§4.1), so a branch administrator can be given
                // the rooms without the batches that fill them. `Batches` is a parent carrying the
                // timetable and the dated classes it produces.
                'Teachers',
                'Classrooms',
                'Batches',
                'All Batches',
                'Timetable',
                'Classes',
                // phase-14-17 §7.7: the register and the syllabus. Phase 1 reserved these two entries
                // under names it guessed (`admin.student-attendance.index`); §7.7 names the routes
                // `admin.attendance.*` and `admin.progress.*`, and Phase 17 corrected the entries to
                // match — a nav item pointing at a route that does not exist renders as dead text.
                'Attendance',
                'Progress',
                // phase-10-12 §8.2. The money register sits in the **Institute** group, beside the
                // charges it pays off — `student_fee_payments` is an Institute module, and it being
                // what triggers commission is not a reason to file it under Collaborator. Its parent
                // "Fees" node appears with it: a group renders once it has a visible child.
                'Fees',
                // phase-18 §7-§8. Phase 1 reserved `admin.student-fees.index` and the menu carried it
                // as dead text until this phase gave it a route; the same is true of the student
                // panel's own Fees entry.
                //
                // `Installments` and `Discounts` are deliberately NOT here. Phase 1 reserved those two
                // names as well, but §7 ships neither route — an installment and a discount are only
                // ever read in the context of the charge they belong to, and a flat list of every
                // installment in the institute answers no question anybody asks. They were replaced by
                // the two screens the phase does ship.
                'Student Fees',
                'Fee Receipts',
                'Fee Collection',
                'Fee Reminders',
                // phase-19-23 §7.2. `Assessments` is a parent: it appears the moment one of its
                // children has a route, and Exams and Results stay hidden until Phase 20 ships theirs.
                'Assessments',
                'Assignments',
                // phase-03 §7-§8: the nine Website CMS entries whose routes now exist.
                'Website Overview',
                'Sections',
                'Menus',
                'Pages',
                'CTA Blocks',
                'FAQs',
                'FAQ Categories',
                'Media Library',
                'SEO',
                // phase-04 §8: the fifteen marketing entries appended to the same Website group.
                'Services',
                'Service Categories',
                'Technologies',
                'Portfolio',
                'Portfolio Categories',
                'Team',
                'Testimonials',
                'Student Reviews',
                'Success Stories',
                'Blog Posts',
                'Blog Categories',
                'Blog Tags',
                'Jobs',
                'Job Applications',
                'Contact Inquiries',
            ],
            $labels,
            'Only the screens whose phase has actually shipped its routes may appear — Phase 1, Phase 2\'s Settings, Phase 3\'s Website CMS, Phase 4\'s marketing modules, Phase 5\'s CRM and Phase 6\'s delivery screens.'
        );
    }

    #[Test]
    public function every_rendered_item_points_at_a_url_the_user_can_actually_open(): void
    {
        $superAdmin = $this->createSuperAdmin();

        // A parent entry carries children instead of a route of its own, so the walk descends rather than
        // demanding a URL from every node. phase-06's Projects group is the first nested entry shipped.
        $leaves = [];

        $collect = function (array $items) use (&$collect, &$leaves): void {
            foreach ($items as $item) {
                if (! empty($item['children'])) {
                    $collect($item['children']);

                    continue;
                }

                $leaves[] = $item;
            }
        };

        foreach (Sidebar::forUser($superAdmin) as $group) {
            $collect($group['items'] ?? []);
        }

        $this->assertNotEmpty($leaves, 'A Super Admin sees no sidebar links at all.');

        foreach ($leaves as $item) {
            $this->assertIsString($item['url'], $item['label'].' has no URL.');

            $response = $this->actingAs($superAdmin)->get($item['url']);

            $this->assertSame(
                200,
                $response->getStatusCode(),
                sprintf('The sidebar offers %s (%s) but it answers %d.', $item['label'], $item['url'], $response->getStatusCode())
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Panel trees
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function each_panel_role_gets_its_own_tree(): void
    {
        $expected = [
            'Student' => PanelType::Student,
            'Teacher' => PanelType::Teacher,
            'Client' => PanelType::Client,
            'Collaborator' => PanelType::Collaborator,
        ];

        foreach ($expected as $role => $panel) {
            $user = $this->seededDemoUser($role);

            $this->assertSame($panel, $user->primaryPanel(), $role.' must land on its own panel.');

            $labels = $this->labels(Sidebar::forUser($user));

            $this->assertContains('Dashboard', $labels, $role.' must always reach its own dashboard.');

            // The client panel grew a real nav in Phase 5, the collaborator panel in Phases 8-12, and
            // the student and teacher panels in Phase 16 — which shipped the batch, timetable and
            // class routes those two read. Attendance and progress arrive with Phase 17.
            // Each entry must be one this account can actually open.
            $expectedLabels = match ($panel) {
                PanelType::Client => ['Dashboard', 'My Projects', 'Milestones', 'Tasks', 'Documents', 'Files',
                    'Invoices', 'Payments', 'Meetings', 'Messages', 'Support', 'Notifications', 'My Profile'],
                PanelType::Collaborator => ['Dashboard', 'My Projects', 'Wallet', 'Commissions', 'Payouts',
                    'Statements'],
                // phase-17 added the register and the syllabus to both panels; phase-18 added the
                // student's own fees, which Phase 1 had reserved an entry for and never had a route to;
                // phase-19 added the material library and assignments to both.
                PanelType::Student => ['Dashboard', 'Timetable', 'Attendance', 'Progress',
                    'Materials', 'Assignments', 'Fees'],
                PanelType::Teacher => ['Dashboard', 'My Batches', 'My Students', 'Timetable',
                    'Demo Classes', 'Attendance', 'Materials', 'Assignments'],
                default => ['Dashboard'],
            };

            $this->assertSame(
                $expectedLabels,
                $labels,
                sprintf('The %s panel shows exactly the entries whose routes have shipped.', $role)
            );
        }
    }

    /**
     * Flatten the resolved tree to the labels it would render, parents included.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, string>
     */
    private function labels(array $groups): array
    {
        $labels = [];

        foreach ($groups as $group) {
            foreach ($group['items'] ?? [] as $item) {
                $labels[] = (string) $item['label'];

                foreach ($item['children'] ?? [] as $child) {
                    $labels[] = (string) $child['label'];
                }
            }
        }

        return $labels;
    }
}
