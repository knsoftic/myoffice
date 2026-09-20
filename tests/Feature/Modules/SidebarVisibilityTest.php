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
                'Tasks',
                'Time Tracking',
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

            // The client panel grew a real nav in Phase 5; the other three still have only a dashboard
            // until their own phase ships routes. Each entry must be one this account can actually open.
            $expectedLabels = $panel === PanelType::Client
                ? ['Dashboard', 'My Projects', 'Milestones', 'Tasks', 'Documents', 'Files', 'Invoices',
                    'Payments', 'Meetings', 'Messages', 'Support', 'Notifications', 'My Profile']
                : ['Dashboard'];

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
