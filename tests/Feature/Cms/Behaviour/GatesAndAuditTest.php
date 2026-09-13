<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Enums\Cms\SectionPlacement;
use App\Models\Activity;
use App\Models\Cms\CmsRevision;
use App\Models\User;
use App\Support\Exceptions\NonPublicSettingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-03 §11.9 — the gating, settings-isolation and audit rows that are about behaviour and data rather
 * than the role matrix (FT-41, FT-42, FT-43, FT-49).
 *
 *   · INV-15: switching the CMS module off closes the admin screens — for a Super Admin too — and never
 *     takes the public site down; no data is touched either way.
 *   · INV-10: the public site reads only settings the registry marks public; a secret cannot be printed
 *     into a page by construction.
 *   · INV-16: every publish, unpublish, reorder, toggle, revert, slug change, SEO change and media delete
 *     leaves an activity row with the actor, the IP, the device and — where it is discretionary — the
 *     reason, with old and new values for the slug and the SEO.
 *   · §6.10: maintenance and "public site off" answer 503 with Retry-After and noindex, never block the
 *     admin, let a user holding `website_sections.view` see the real site with a ribbon, and leak nothing.
 */
final class GatesAndAuditTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    /** The CMS tables whose row counts must survive a module switch untouched. */
    private const CMS_TABLES = [
        'website_sections', 'website_section_items', 'website_section_media', 'menus', 'menu_items', 'pages',
        'cta_blocks', 'faq_categories', 'faqs', 'faq_website_section', 'seo_meta', 'media_assets', 'cms_revisions',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    /** FT-41 */
    public function test_module_gating_affects_the_admin_cms_only(): void
    {
        $admin = $this->createSuperAdmin();
        $hero = $this->publishHeroHeading('FT41 Live Hero');
        $item = $this->sections()->upsertItem($hero, 'statistic', ['label' => 'FT41 Stat', 'value_mode' => 'manual', 'manual_value' => '41']);
        $revision = CmsRevision::query()
            ->where('revisionable_type', $hero->getMorphClass())
            ->where('revisionable_id', $hero->getKey())
            ->latest('id')
            ->firstOrFail();

        $sectionsHref = 'href="'.route('admin.website.sections.index', ['placement' => SectionPlacement::Home->value]).'"';
        $overviewHref = 'href="'.route('admin.website.index').'"';

        $dashboard = (string) $this->actingAs($admin)->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString($sectionsHref, $dashboard, 'Sanity: the sidebar links the section manager while the module is on.');
        $this->assertStringContainsString($overviewHref, $dashboard);

        $counts = $this->cmsCounts();

        $this->switchModule('website_sections', false);

        $parameters = [
            'placement' => SectionPlacement::Home->value,
            'section' => (int) $hero->getKey(),
            'item' => (int) $item->getKey(),
            'revision' => (int) $revision->getKey(),
            'group' => 'statistic',
        ];

        $checked = 0;

        /** @var RouteDefinition $route */
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'admin.website.') || ! in_array('module:website_sections', $route->gatherMiddleware(), true)) {
                continue;
            }

            $method = collect($route->methods())->reject(static fn (string $verb): bool => $verb === 'HEAD')->first();
            $uri = route($name, array_intersect_key($parameters, array_flip($route->parameterNames())));

            $this->actingAs($admin)
                ->json((string) $method, $uri, ['reason' => 'FT-41 module is off'])
                ->assertForbidden();

            $checked++;
        }

        $this->assertGreaterThanOrEqual(21, $checked, 'Every §7.1 route was exercised.');

        $dashboard = (string) $this->actingAs($admin)->get('/admin')->assertOk()->getContent();
        $this->assertStringNotContainsString($sectionsHref, $dashboard, 'The sidebar hides the section manager while the module is off.');
        $this->assertStringNotContainsString($overviewHref, $dashboard);

        $this->becomeGuest();
        $this->get('/')->assertOk()->assertSee('FT41 Live Hero');

        $this->assertSame($counts, $this->cmsCounts(), 'Disabling the module touched no CMS row.');

        $this->switchModule('website_sections', true);

        $this->actingAs($admin)->get(route('admin.website.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.website.sections.edit', $hero))->assertOk();

        $this->assertSame($counts, $this->cmsCounts(), 'Re-enabling restores the admin with identical row counts.');
    }

    /** FT-42 */
    public function test_public_views_cannot_read_non_public_settings(): void
    {
        foreach (['mail.password', 'mail.host', 'security.allowed_file_types', 'finance.invoice_prefix'] as $key) {
            $this->assertThrows(static fn () => site_setting($key), NonPublicSettingException::class);
        }

        $this->assertThrows(static fn () => site_setting('ft42.not_declared_anywhere'), NonPublicSettingException::class);
        $this->assertNotNull(site_setting('company.name', ''), 'A public key is readable.');

        $offenders = [];

        foreach (File::allFiles(resource_path('views/site')) as $file) {
            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/(?<![A-Za-z0-9_$>:\\\\])setting\s*\(/', $source) === 1 || preg_match('/(?<![A-Za-z0-9_$>:\\\\])config\s*\(/', $source) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'A site view reads setting() or config() directly: '.implode(', ', $offenders));

        $secrets = [
            'mail.host' => 'ft42-smtp-host.example.test',
            'mail.username' => 'ft42-smtp-username',
            'mail.password' => 'FT42-SMTP-PASSWORD-VALUE',
            'security.allowed_file_types' => 'ft42secrettype',
            'finance.invoice_prefix' => 'FT42INV',
        ];

        foreach ($secrets as $key => $value) {
            $this->setSetting($key, $value);
        }

        $this->bumpPublicCache('FT-42 secrets written');

        $html = (string) $this->get('/')->assertOk()->getContent();

        foreach ($secrets as $key => $value) {
            $this->assertStringNotContainsString($value, $html, sprintf('The value of %s reached the public home page.', $key));
        }
    }

    /** FT-43 */
    public function test_every_cms_write_is_audited(): void
    {
        $this->treatRequestsAsWeb();
        $this->withHeader('User-Agent', self::BROWSER);

        $admin = $this->createSuperAdmin();
        $this->actingAs($admin);

        $hero = $this->sections()->saveDraft($this->seededSection('hero'), ['heading' => 'FT43 Hero']);
        $block = $this->placeRichContent('FT43 Block');
        $page = $this->makePage('ft43-old-slug', 'FT43 Page');
        $asset = $this->makeImageAsset();

        // publish
        $mark = $this->lastActivityId();
        $this->postJson(route('admin.website.sections.publish', $hero))->assertOk();
        $this->assertContext($this->activity('website_sections', 'published', $mark), $admin);

        // unpublish (reason)
        $mark = $this->lastActivityId();
        $this->postJson(route('admin.website.sections.unpublish', $block), ['reason' => 'FT-43 reason for unpublishing'])->assertOk();
        $unpublished = $this->activity('website_sections', 'unpublished', $mark);
        $this->assertContext($unpublished, $admin);
        $this->assertSame('FT-43 reason for unpublishing', $unpublished->reason);

        // reorder
        $order = DB::table('website_sections')->where('placement', SectionPlacement::Home->value)->whereNull('page_id')->whereNull('deleted_at')
            ->orderBy('sort_order')->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        $mark = $this->lastActivityId();
        $this->postJson(route('admin.website.sections.reorder'), ['placement' => SectionPlacement::Home->value, 'order' => array_reverse($order)])->assertOk();
        $reordered = $this->activity('website_sections', 'reordered', $mark);
        $this->assertContext($reordered, $admin);
        $this->assertSame($order, $reordered->properties->toArray()['old']['order'] ?? null);
        $this->assertSame(array_reverse($order), $reordered->properties->toArray()['attributes']['order'] ?? null);

        // toggle
        $mark = $this->lastActivityId();
        $this->postJson(route('admin.website.sections.toggle', $hero), ['enabled' => false])->assertOk();
        $this->assertContext($this->activity('website_sections', 'disabled', $mark), $admin);

        // revert (reason)
        $revision = CmsRevision::query()
            ->where('revisionable_type', $block->getMorphClass())
            ->where('revisionable_id', $block->getKey())
            ->orderBy('id')
            ->firstOrFail();

        $mark = $this->lastActivityId();
        $this->postJson(route('admin.website.sections.revisions.revert', ['section' => $block, 'revision' => $revision]), ['reason' => 'FT-43 reason for reverting'])->assertOk();
        $reverted = $this->activity('website_sections', 'reverted', $mark);
        $this->assertContext($reverted, $admin);
        $this->assertSame('FT-43 reason for reverting', $reverted->reason);

        // slug change, with old and new values
        $mark = $this->lastActivityId();
        $this->putJson(route('admin.website.pages.update', $page), ['slug' => 'ft43-new-slug'])->assertOk();
        $slug = $this->activity('pages', 'slug_changed', $mark);
        $this->assertContext($slug, $admin);
        $this->assertSame('ft43-old-slug', $slug->properties->toArray()['old']['slug'] ?? null);
        $this->assertSame('ft43-new-slug', $slug->properties->toArray()['attributes']['slug'] ?? null);

        // SEO change, with old and new values
        $target = 'page:'.$page->getKey();
        $this->putJson(route('admin.website.seo.update'), ['target' => $target, 'seo' => ['title' => 'FT43 SEO Title One']])->assertOk();

        $mark = $this->lastActivityId();
        $this->putJson(route('admin.website.seo.update'), ['target' => $target, 'seo' => ['title' => 'FT43 SEO Title Two']])->assertOk();
        $seo = $this->activity('seo', 'updated', $mark);
        $this->assertContext($seo, $admin);
        $this->assertSame('FT43 SEO Title One', $seo->properties->toArray()['old']['title'] ?? null);
        $this->assertSame('FT43 SEO Title Two', $seo->properties->toArray()['attributes']['title'] ?? null);

        // media delete
        $mark = $this->lastActivityId();
        $this->deleteJson(route('admin.website.media.destroy', $asset), ['reason' => 'FT-43 removing an unused image'])->assertOk();
        $deleted = $this->activity('website_media', 'deleted', $mark);
        $this->assertContext($deleted, $admin);
    }

    /** FT-49 */
    public function test_maintenance_and_public_site_gates(): void
    {
        $admin = $this->createSuperAdmin();
        $this->publishHeroHeading('FT49 Real Site Hero');

        // Maintenance mode.
        $this->setSetting('maintenance.maintenance_message', 'FT49 Back at noon after the upgrade.');
        $this->setSetting('maintenance.maintenance_mode', true);

        $closed = $this->get('/')
            ->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertSee('FT49 Back at noon after the upgrade.')
            ->assertDontSee('FT49 Real Site Hero');

        $this->assertStringContainsString('noindex', (string) $closed->headers->get('X-Robots-Tag'), 'The maintenance 503 carries X-Robots-Tag: noindex.');
        $this->assertNoLeak((string) $closed->getContent());

        $this->actingAs($admin)->get('/admin')->assertOk();

        $staff = $this->createUserWithPermissions(['website_sections.view']);

        $this->actingAs($staff)
            ->get('/')
            ->assertOk()
            ->assertSee('FT49 Real Site Hero')
            ->assertSee('Maintenance mode is on');

        // A signed-in student is an ordinary visitor: the permission decides, never the login.
        $this->actingAs($this->createUserWithRole('Student'))
            ->get('/')
            ->assertStatus(503)
            ->assertDontSee('FT49 Real Site Hero');

        $this->becomeGuest();
        $this->setSetting('maintenance.maintenance_mode', false);

        // The public site switched off.
        $this->setSetting('maintenance.public_site_enabled', false);

        $holding = $this->get('/')
            ->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertSee('This website is currently unavailable.')
            ->assertDontSee('FT49 Real Site Hero');

        $this->assertStringContainsString('noindex', (string) $holding->headers->get('X-Robots-Tag'));
        $this->assertNoLeak((string) $holding->getContent());

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    /**
     * @return array<string, int>
     */
    private function cmsCounts(): array
    {
        $counts = [];

        foreach (self::CMS_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function lastActivityId(): int
    {
        return (int) Activity::query()->max('id');
    }

    private function activity(string $module, string $event, int $afterId): Activity
    {
        $activity = Activity::query()
            ->where('id', '>', $afterId)
            ->where('module', $module)
            ->where('event', $event)
            ->latest('id')
            ->first();

        $this->assertInstanceOf(Activity::class, $activity, sprintf('No activity row [%s / %s] was written.', $module, $event));

        return $activity;
    }

    private function assertContext(Activity $activity, User $actor): void
    {
        $this->assertSame((int) $actor->getKey(), (int) $activity->causer_id, 'The activity row names the actor.');
        $this->assertSame('127.0.0.1', $activity->ip_address, 'The activity row carries the IP.');
        $this->assertNotEmpty($activity->device, 'The activity row carries the device.');
    }

    private function assertNoLeak(string $html): void
    {
        $this->assertStringNotContainsString('<form', $html, 'A closed-site page renders no form.');
        $this->assertStringNotContainsString('name="password"', $html, 'A closed-site page renders no login form.');
        $this->assertStringNotContainsString('Stack trace', $html);
        $this->assertStringNotContainsString('vendor/laravel', $html);
    }
}
