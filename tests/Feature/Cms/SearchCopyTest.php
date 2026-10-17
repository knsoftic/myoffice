<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Services\Cms\SeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * `cms:seo-copy` writes search titles, descriptions and keywords that name the city, only into empty
 * fields; and a title stored in the SEO manager is the one the browser tab shows.
 */
final class SearchCopyTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        $this->setSetting('company.name', 'Example Softic');
        $this->setSetting('contact.city', 'Testabad');
        $this->setSetting('contact.phone', '+92 300 0000000');
    }

    #[Test]
    public function the_home_page_and_route_pages_name_the_city(): void
    {
        $this->artisan('cms:seo-copy')->assertSuccessful();
        $this->bumpPublicCache();

        $this->get('/')->assertOk()
            ->assertSee('<title>Example Softic – IT Courses &amp; Software House in Testabad</title>', false)
            ->assertSee('Testabad — or get your website, app or software built.', false)
            ->assertSee('web development course in Testabad', false);

        // A stored title beats the view's own @section('title', 'Services').
        $this->get('/services')->assertOk()->assertSee('<title>Software House in Testabad | Example Softic</title>', false);

        $this->get('/contact')->assertOk()->assertSee('+92 300 0000000', false);

        // An error page borrows the home payload but keeps its own title.
        $this->get('/no-such-page-at-all')->assertNotFound()->assertSee('<title>Page not found | Example Softic</title>', false);

        $this->assertNotEmpty(setting('seo.meta_description'));
        $this->assertNull(setting('seo.meta_title'), 'A site-wide title would replace every route page\'s own.');
    }

    #[Test]
    public function it_fills_only_empty_fields_and_a_second_run_changes_nothing(): void
    {
        app(SeoService::class)->save('site.services.index', ['title' => 'Our services, written by an editor']);

        $this->artisan('cms:seo-copy')->assertSuccessful();

        $row = app(SeoService::class)->meta('site.services.index');
        $this->assertSame('Our services, written by an editor', $row?->title);
        $this->assertStringContainsString('software house in Testabad', (string) $row?->meta_description, 'The empty description was still filled.');

        $before = app(SeoService::class)->meta(SeoService::HOME_ROUTE)?->updated_at;
        $this->artisan('cms:seo-copy')->expectsOutputToContain('Nothing to write')->assertSuccessful();
        $this->assertEquals($before, app(SeoService::class)->meta(SeoService::HOME_ROUTE)?->updated_at);

        $this->artisan('cms:seo-copy', ['--overwrite' => true])->assertSuccessful();
        $this->assertSame('Software House in Testabad', app(SeoService::class)->meta('site.services.index')?->title);
    }

    #[Test]
    public function it_refuses_to_run_without_a_city(): void
    {
        $this->setSetting('contact.city', '');

        $this->artisan('cms:seo-copy')->assertFailed();
        $this->assertNull(app(SeoService::class)->meta(SeoService::HOME_ROUTE)?->title);
    }
}
