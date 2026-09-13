<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Http;

use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cms\Http\Concerns\CmsHttpFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The gates in front of the CMS and the public site (phase-03 §6.10, §6.12, §7, §9):
 *
 *   · FT-41 — a disabled CMS module 403s its admin routes for everyone, Super Admin included, hides its
 *     sidebar entries and touches no row, while the public site keeps serving the last published content
 *     (INV-15: no public route carries `module:` or `can:`);
 *   · FT-21 / FT-23 — who may see a draft through the preview routes, and the shareable signed link;
 *   · FT-49 — `maintenance_mode` and `public_site_enabled` close the public site with a 503 but never the
 *     admin, and a user holding `website_sections.view` browses the real site under a ribbon.
 */
final class CmsGatingAndPreviewTest extends TestCase
{
    use CmsHttpFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    private const PORTAL_ROLES = ['Teacher', 'Student', 'Client', 'Collaborator'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
        Storage::fake('public');
    }

    /*
    |--------------------------------------------------------------------------
    | FT-41 — module gating
    |--------------------------------------------------------------------------
    */

    /**
     * FT-41 over HTTP for all 21 routes of §7.1, as JSON and as HTML (the row itself is also asserted by
     * `Behaviour\GatesAndAuditTest`).
     */
    public function test_module_gating_affects_the_admin_cms_only_on_every_section_route(): void
    {
        $super = $this->createSuperAdmin();
        $marker = 'Last published hero '.$this->uniqueToken();
        $this->cmsPublisher()->publish($this->cmsSections()->saveDraft($this->cmsSection('hero'), ['heading' => $marker]));

        $routes = array_filter(
            $this->cmsRouteTable(),
            static fn (array $entry): bool => str_starts_with($entry['permission'], 'website_sections.'),
        );

        $this->assertCount(21, $routes, 'phase-03 §7.1 declares 21 section routes.');

        $prepared = [];

        foreach ($routes as $name => $entry) {
            $prepared[$name] = $this->prepareCmsRequest($name, $entry, false);
        }

        $this->actingAs($super)->get(route('admin.website.index'))->assertOk();
        $this->assertContains('Website Overview', $this->cmsSidebarLabels($super));

        $counts = $this->cmsRowCounts();

        $this->switchModule('website_sections', false);

        $before = $this->cmsFingerprint();

        foreach ($prepared as $name => $request) {
            foreach ([true, false] as $json) {
                $response = $this->actingAs($super)->sendPreparedCms($request, $json);

                $this->assertSame(
                    403,
                    $response->getStatusCode(),
                    sprintf('With website_sections disabled, %s %s must be 403 for a Super Admin (%s); it answered %d.', $request['method'], $name, $json ? 'JSON' : 'HTML', $response->getStatusCode()),
                );
            }
        }

        $this->assertSame($before, $this->cmsFingerprint(), 'A request to a disabled module wrote something.');

        $labels = $this->cmsSidebarLabels($super);
        $this->assertNotContains('Website Overview', $labels);
        $this->assertNotContains('Sections', $labels);
        $this->assertContains('Menus', $labels, 'Only the disabled module leaves the sidebar.');

        $this->signOut();

        $this->get('/')->assertOk()->assertSee($marker, false);
        $this->get('/privacy-policy')->assertOk();

        $this->switchModule('website_sections', true);

        $this->assertSame($counts, $this->cmsRowCounts(), 'Disabling and re-enabling the module must touch no CMS row.');

        foreach ($prepared as $name => $request) {
            if ($request['kind'] === 'screen') {
                $this->actingAs($super)->sendPreparedCms($request)->assertOk();
            }
        }

        $this->assertContains('Website Overview', $this->cmsSidebarLabels($super));
        $this->assertContains('Sections', $this->cmsSidebarLabels($super));
    }

    /**
     * FT-41 for the other seven CMS modules (§9 "Module gating" names all eight): each one closes exactly
     * its own admin routes and sidebar entry, for a Super Admin too, and the public site stays up.
     */
    public function test_module_gating_affects_the_admin_cms_only_for_every_cms_module(): void
    {
        $super = $this->createSuperAdmin();
        $table = $this->cmsRouteTable();
        $prepared = [];

        foreach ($table as $name => $entry) {
            $prepared[$name] = $this->prepareCmsRequest($name, $entry, false);
        }

        $indexes = $this->cmsModuleIndexRoutes();
        $sidebar = $this->cmsModuleSidebarLabels();

        foreach ($this->cmsModules() as $module) {
            $counts = $this->cmsRowCounts();

            $this->switchModule($module, false);

            $before = $this->cmsFingerprint();

            foreach ($prepared as $name => $request) {
                if (explode('.', $table[$name]['permission'], 2)[0] !== $module) {
                    continue;
                }

                $response = $this->actingAs($super)->sendPreparedCms($request, true);

                $this->assertSame(
                    403,
                    $response->getStatusCode(),
                    sprintf('With %s disabled, %s %s must be 403 for a Super Admin; it answered %d.', $module, $request['method'], $name, $response->getStatusCode()),
                );
            }

            $this->assertSame($before, $this->cmsFingerprint(), sprintf('A request to the disabled %s module wrote something.', $module));

            foreach ($indexes as $other => $route) {
                if ($other !== $module) {
                    $this->actingAs($super)->get(route($route))->assertOk();
                }
            }

            $labels = $this->cmsSidebarLabels($super);

            foreach ($sidebar as $owner => $ownLabels) {
                foreach ($ownLabels as $label) {
                    $owner === $module
                        ? $this->assertNotContains($label, $labels, sprintf('%s must leave the sidebar while %s is disabled.', $label, $module))
                        : $this->assertContains($label, $labels, sprintf('%s must stay in the sidebar while only %s is disabled.', $label, $module));
                }
            }

            $this->signOut();

            $this->get('/')->assertOk();
            $this->get('/privacy-policy')->assertOk();
            $this->get('/robots.txt')->assertOk();

            $this->switchModule($module, true);

            $this->assertSame($counts, $this->cmsRowCounts(), sprintf('Switching %s off and on must touch no CMS row.', $module));

            $this->actingAs($super)->get(route($indexes[$module]))->assertOk();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FT-21 / FT-23 — preview
    |--------------------------------------------------------------------------
    */

    public function test_preview_shows_draft_content_to_an_authorised_user_through_the_preview_routes(): void
    {
        $token = $this->uniqueToken();
        $live = 'Live hero heading A '.$token;
        $draft = 'Draft hero heading B '.$token;

        $hero = $this->cmsPublisher()->publish($this->cmsSections()->saveDraft($this->cmsSection('hero'), ['heading' => $live]));
        $hero = $this->cmsSections()->saveDraft($hero, ['heading' => $draft]);

        $this->get('/')->assertOk()->assertSee($live, false)->assertDontSee($draft, false);

        $viewer = $this->createUserWithPermissions(['website_sections.view']);

        $this->actingAs($viewer)
            ->get(route('site.preview.section', $hero))
            ->assertOk()
            ->assertSee($draft, false)
            ->assertDontSee($live, false)
            ->assertSee('Preview — draft content, not live', false);

        $this->actingAs($viewer)->get('/?preview=1')->assertOk()->assertSee($draft, false);

        // The permission decides, never the login: a signed-in user without it, and every portal, get 404.
        $pagesOnly = $this->createUserWithPermissions(['pages.view']);
        $this->actingAs($pagesOnly)->get(route('site.preview.section', $hero))->assertNotFound();

        foreach (self::PORTAL_ROLES as $role) {
            $portalUser = $this->createUserWithRole($role);

            $this->actingAs($portalUser)->get(route('site.preview.section', $hero))->assertNotFound();
            $this->actingAs($portalUser)->get('/?preview=1')->assertOk()->assertSee($live, false)->assertDontSee($draft, false);
        }

        $this->signOut();

        // A guest with no signature gets a 404, so draft ids cannot be probed; ?preview=1 is the live page.
        $this->get(route('site.preview.section', $hero))->assertNotFound();
        $this->get('/?preview=1')->assertOk()->assertSee($live, false)->assertDontSee($draft, false);
        $this->get('/')->assertOk()->assertSee($live, false)->assertDontSee($draft, false);
    }

    public function test_signed_preview_link_works_and_expires_through_the_admin_share_link(): void
    {
        settings_repo()->set('website.preview_ttl_minutes', 30);

        $token = $this->uniqueToken();
        $page = $this->makeCmsPage(false, ['content' => '<p>Only in the draft '.$token.'</p>']);
        $sharer = $this->createUserWithPermissions(['pages.view']);

        $link = $this->actingAs($sharer)
            ->get(route('admin.website.pages.preview-link', $page), ['Accept' => 'application/json'])
            ->assertOk()
            ->json('url');

        $this->assertIsString($link);
        $this->assertStringContainsString('signature=', $link);

        $this->signOut();

        // The page is a draft: its public address does not exist for a visitor.
        $this->get('/'.$page->slug)->assertNotFound();

        $this->get($link)
            ->assertOk()
            ->assertSee('Only in the draft '.$token, false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $tampered = (string) preg_replace_callback(
            '/signature=([0-9a-f])/',
            static fn (array $match): string => 'signature='.($match[1] === 'a' ? 'b' : 'a'),
            $link,
            1,
        );

        $this->assertNotSame($link, $tampered);
        $this->get($tampered)->assertForbidden();

        // No preview route performs a write of any kind.
        $this->post($link)->assertStatus(405);

        $this->travel(31)->minutes();

        $this->get($link)->assertForbidden();

        $this->travelBack();
    }

    /*
    |--------------------------------------------------------------------------
    | FT-49 — the maintenance and public-site gates
    |--------------------------------------------------------------------------
    */

    public function test_maintenance_and_public_site_gates_around_the_cms(): void
    {
        $marker = 'Hero behind the gate '.$this->uniqueToken();
        $this->cmsPublisher()->publish($this->cmsSections()->saveDraft($this->cmsSection('hero'), ['heading' => $marker]));

        $super = $this->createSuperAdmin();
        $staff = $this->createUserWithPermissions(['website_sections.view']);
        $student = $this->createUserWithRole('Student');
        $message = 'Upgrading the enrolment pages, back by noon '.$this->uniqueToken();

        $this->get('/')->assertOk()->assertSee($marker, false);

        // ── maintenance_mode on ─────────────────────────────────────────────────────────────
        settings_repo()->set('maintenance.maintenance_message', $message);
        settings_repo()->set('maintenance.maintenance_mode', true);

        foreach (['/', '/privacy-policy'] as $uri) {
            $response = $this->get($uri)
                ->assertStatus(503)
                ->assertSee($message, false)
                ->assertHeader('Retry-After', '3600')
                ->assertDontSee($marker, false)
                ->assertDontSee('type="password"', false)
                ->assertDontSee('Stack trace', false);

            $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'), $uri.' must carry X-Robots-Tag: noindex while in maintenance.');
        }

        // robots.txt stays readable while the site is down, and says "stay away".
        $robots = $this->get('/robots.txt')->assertOk();
        $this->assertMatchesRegularExpression('/^Disallow: \/\s*$/m', (string) $robots->getContent());

        // A signed-in student is an ordinary visitor.
        $this->actingAs($student)->get('/')->assertStatus(503);

        // Staff holding website_sections.view see the real site with the ribbon naming the state.
        $this->actingAs($staff)
            ->get('/')
            ->assertOk()
            ->assertSee($marker, false)
            ->assertSee('Maintenance mode is on', false);

        // The admin is never behind the gate.
        $this->actingAs($super)->get(route('admin.website.index'))->assertOk();
        $this->actingAs($super)->get(route('admin.website.sections.index', ['placement' => 'home']))->assertOk();

        $this->signOut();

        // ── public_site_enabled off ─────────────────────────────────────────────────────────
        settings_repo()->set('maintenance.maintenance_mode', false);
        settings_repo()->set('maintenance.public_site_enabled', false);

        $response = $this->get('/')
            ->assertStatus(503)
            ->assertSee('This website is currently unavailable.', false)
            ->assertHeader('Retry-After', '3600')
            ->assertDontSee($marker, false)
            ->assertDontSee('type="password"', false)
            ->assertDontSee('Stack trace', false);

        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));

        $this->get('/robots.txt')->assertOk();

        $this->actingAs($staff)
            ->get('/')
            ->assertOk()
            ->assertSee($marker, false)
            ->assertSee('The public website is switched off', false);

        $this->actingAs($super)->get(route('admin.website.index'))->assertOk();
        $this->actingAs($super)->get(route('admin.website.pages.index'))->assertOk();
    }
}
