<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Http;

use App\Enums\Cms\ContentStatus;
use App\Enums\JobApplicationStatus;
use App\Models\Cms\ContactInquiry;
use App\Models\User;
use App\Services\Cms\BlogService;
use App\Services\Cms\JobApplicationService;
use App\Services\Cms\ModerationService;
use App\Services\Cms\PortfolioService;
use App\Support\SettingsRepository;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Cms\Http\Concerns\CmsHttpFixtures;
use Tests\Feature\Cms\Marketing\Http\Concerns\MarketingHttpFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Every phase-04 screen renders (§8), admin and public — §11 test 59, with tests 9 and 14 (their screen
 * halves):
 *
 *   · every admin marketing screen answers for a Super Admin on an empty install (with the contract's empty
 *     states) and again with rows, including each tab and filter the lists offer;
 *   · the rendered admin content is dark-mode clean (every light background and dark text utility has its
 *     `dark:` counterpart) and every table scrolls inside its own container — "light and dark" as far as a
 *     rendered-HTML assertion can prove it ("no console error" is a manual browser pass, integration §10.1);
 *   · every public marketing page passes the HTML-structure smoke test: one `h1`, `alt` on every image, a
 *     non-empty `<title>` and meta description, a device-width viewport, no leaked error.
 */
final class MarketingScreensTest extends TestCase
{
    use CmsHttpFixtures;
    use InteractsWithRbac;
    use MarketingHttpFixtures;
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
        Storage::fake('public');
        Storage::fake('local');

        $this->superAdmin = $this->createSuperAdmin();
    }

    /*
    |--------------------------------------------------------------------------
    | Admin screens
    |--------------------------------------------------------------------------
    */

    public function test_contract_59_every_marketing_admin_screen_renders_on_an_empty_install_with_its_empty_state(): void
    {
        foreach ($this->marketingRouteTable() as $name => $entry) {
            if ($entry['method'] !== 'GET' || str_contains($entry['uri'], '{') || ! in_array($entry['kind'], ['screen', 'download'], true)) {
                continue;
            }

            $status = $this->actingAs($this->superAdmin)->get(route($name))->getStatusCode();

            $this->assertSame(200, $status, sprintf('The empty %s screen answered %d.', $name, $status));
        }

        $emptyStates = [
            'admin.service-categories.index' => 'No service categories yet',
            'admin.portfolio-categories.index' => 'No portfolio categories yet',
            'admin.blog-categories.index' => 'No blog categories yet',
            'admin.blog-tags.index' => 'No tags yet',
            'admin.technologies.index' => 'No technologies yet',
            'admin.portfolio.index' => 'No projects published yet',
            'admin.team.index' => 'No team members yet',
            'admin.testimonials.index' => 'Nothing waiting for approval',
            'admin.student-reviews.index' => 'Nothing waiting for approval',
            'admin.success-stories.index' => 'No success stories yet',
            'admin.blog-posts.index' => 'No posts yet',
            'admin.jobs.index' => 'No openings',
            'admin.job-applications.index' => 'No applications yet',
            'admin.contact-inquiries.index' => 'No inquiries yet',
        ];

        foreach ($emptyStates as $route => $text) {
            $this->actingAs($this->superAdmin)->get(route($route))->assertOk()->assertSee($text, false);
        }

        $this->actingAs($this->superAdmin)
            ->get(route('admin.contact-inquiries.index', ['tab' => 'spam']))
            ->assertOk()
            ->assertSee('No spam caught', false);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.contact-inquiries.index', ['tab' => 'new']))
            ->assertOk()
            ->assertSee('No new inquiries', false);
    }

    public function test_contract_59_every_marketing_admin_screen_renders_with_rows_and_every_list_variant(): void
    {
        foreach ($this->marketingRouteTable() as $name => $entry) {
            if ($entry['kind'] === 'write') {
                continue;
            }

            $request = $this->prepareMarketingRequest($name, $entry);
            $response = $this->actingAs($this->superAdmin)->sendPreparedMarketing($request);
            $expected = $this->expectedMarketingStatus($entry['kind']);

            $this->assertSame($expected, $response->getStatusCode(), sprintf('The %s screen (%s) answered %d, expected %d: %s', $name, $request['url'], $response->getStatusCode(), $expected, Str::limit((string) $response->getContent(), 300)));
        }

        $fixtures = $this->richFixtures();
        $urls = [];

        foreach (['service-categories', 'portfolio-categories', 'blog-categories', 'technologies', 'blog-tags'] as $prefix) {
            $urls[] = route("admin.$prefix.index", ['state' => 'active', 'search' => 'Http', 'sort' => 'name', 'direction' => 'desc']);
            $urls[] = route("admin.$prefix.index", ['state' => 'inactive', 'trashed' => '1']);
            $urls[] = route("admin.$prefix.index", ['create' => '1']);
        }

        foreach (['service-categories' => 'service_categories', 'portfolio-categories' => 'portfolio_categories', 'blog-categories' => 'blog_categories'] as $prefix => $module) {
            // The three categories carry SEO, so their editor is a full page (§8.1).
            $term = $this->sharedMarketing('term:'.$module, fn () => $this->makeTerm($module));
            $urls[] = route("admin.$prefix.edit", ['term' => $term->getKey()]);
            $urls[] = route("admin.$prefix.index", ['edit' => $term->getKey()]);
        }

        $urls = array_merge($urls, [
            route('admin.services.index', ['status' => 'published', 'featured' => '1', 'search' => 'Http', 'sort' => 'starting_price', 'direction' => 'desc']),
            route('admin.services.index', ['category' => $fixtures['category'], 'technology' => $fixtures['technology']]),
            route('admin.services.index', ['trashed' => '1']),
            route('admin.services.export', ['status' => 'published']),
            route('admin.services.edit', ['service' => $fixtures['service']]),
            route('admin.portfolio.index', ['status' => 'published', 'year' => (int) date('Y'), 'search' => 'Http', 'trashed' => '0']),
            route('admin.portfolio.edit', ['item' => $fixtures['gallery']]),
            route('admin.team.index', ['visibility' => 'hidden', 'status' => 'draft', 'search' => 'Http']),
            route('admin.team.index', ['department' => 'Engineering', 'sort' => 'name']),
            route('admin.team.edit', ['member' => $fixtures['hidden_member']]),
            route('admin.success-stories.index', ['status' => 'published', 'featured' => '0', 'course' => 'Laravel']),
            route('admin.blog-posts.calendar', ['month' => date('Y-m')]),
            route('admin.blog-posts.calendar', ['month' => '2025-02']),
            route('admin.blog-posts.index', ['has_image' => '0', 'sort' => 'views_count', 'direction' => 'asc']),
            route('admin.blog-posts.index', ['category' => $fixtures['blog_category'], 'tag' => $fixtures['blog_tag'], 'author' => $this->superAdmin->getKey()]),
            route('admin.blog-posts.stats', ['post' => $fixtures['published_post'], 'range' => 'week']),
            route('admin.blog-posts.edit', ['post' => $fixtures['scheduled_post']]),
            route('admin.jobs.index', ['deadline' => 'expired', 'status' => 'open']),
            route('admin.jobs.index', ['work_mode' => 'remote', 'employment_type' => 'full_time', 'featured' => '0']),
            route('admin.jobs.edit', ['job' => $fixtures['open_job']]),
            route('admin.job-applications.index', ['job' => $fixtures['open_job'], 'unassigned' => '1']),
            route('admin.job-applications.index', ['rating' => 4, 'search' => 'Http']),
            route('admin.job-applications.export', ['stage' => 'new']),
            route('admin.job-applications.show', ['application' => $fixtures['rejected_application']]),
            route('admin.contact-inquiries.index', ['type' => 'service', 'routing' => 'pending', 'source' => 'website', 'unassigned' => '1']),
            route('admin.contact-inquiries.export', ['tab' => 'spam']),
            route('admin.contact-inquiries.show', ['inquiry' => $fixtures['pending_inquiry']]),
            route('admin.contact-inquiries.show', ['inquiry' => $fixtures['spam_inquiry']]),
        ]);

        foreach (['testimonials', 'student-reviews'] as $prefix) {
            foreach (['pending', 'approved', 'rejected', 'featured', 'all', 'trashed'] as $tab) {
                $urls[] = route("admin.$prefix.index", ['tab' => $tab, 'rating' => $tab === 'all' ? 5 : null, 'source' => 'admin']);
            }
        }

        foreach (['all', 'published', 'scheduled', 'draft', 'archived', 'mine', 'trashed'] as $tab) {
            $urls[] = route('admin.blog-posts.index', ['tab' => $tab]);
        }

        foreach (JobApplicationStatus::cases() as $stage) {
            $urls[] = route('admin.job-applications.index', ['stage' => $stage->value]);
        }

        foreach (ContactInquiry::TABS as $tab) {
            $urls[] = route('admin.contact-inquiries.index', ['tab' => $tab]);
        }

        foreach ($urls as $url) {
            $response = $this->actingAs($this->superAdmin)->get($url);

            $this->assertSame(200, $response->getStatusCode(), sprintf('%s answered %d: %s', $url, $response->getStatusCode(), Str::limit((string) $response->getContent(), 300)));
        }
    }

    /**
     * §11 test 59, "in light and dark": the phase-04 content area of every list and editor carries a `dark:`
     * counterpart for each light background and dark text, and every table scrolls in its own container.
     */
    public function test_contract_59_admin_marketing_screens_are_dark_mode_clean_in_the_rendered_html(): void
    {
        $this->richFixtures();

        foreach ($this->marketingRouteTable() as $name => $entry) {
            if ($entry['kind'] !== 'screen') {
                continue;
            }

            $request = $this->prepareMarketingRequest($name, $entry);
            $html = (string) $this->actingAs($this->superAdmin)->sendPreparedMarketing($request)->assertOk()->getContent();

            // Decorative `aria-hidden` shapes (a switch's white knob, which stays white on its dark track) are
            // not surfaces or text; everything a reader sees is held to the rule.
            $this->assertRenderedHtmlIsDarkModeClean($html, $name, '//main', true);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Public screens
    |--------------------------------------------------------------------------
    */

    public function test_contract_59_public_marketing_pages_pass_the_html_structure_smoke_test(): void
    {
        settings_repo()->set('seo.meta_description', 'The default description every public marketing page falls back to.');

        $fixtures = $this->richFixtures();

        $pages = [
            '/services' => route('site.services.index'),
            '/services/{slug}' => route('site.services.show', ['service' => $fixtures['service_slug']]),
            '/portfolio' => route('site.portfolio.index'),
            '/portfolio/{slug}' => route('site.portfolio.show', ['portfolioItem' => $fixtures['gallery_slug']]),
            '/team' => route('site.team.index'),
            '/blog' => route('site.blog.index'),
            '/blog/{slug}' => route('site.blog.show', ['blogPost' => $fixtures['published_post_slug']]),
            '/blog/category/{slug}' => route('site.blog.category', ['blogCategory' => $fixtures['blog_category_slug']]),
            '/blog/tag/{slug}' => route('site.blog.tag', ['blogTag' => $fixtures['blog_tag_slug']]),
            '/careers' => route('site.careers.index'),
            '/careers/{slug}' => route('site.careers.show', ['jobOpening' => $fixtures['open_job_slug']]),
            '/contact' => route('site.contact.index'),
        ];

        $this->signOut();

        foreach ($pages as $label => $url) {
            $response = $this->get($url);

            $this->assertSame(200, $response->getStatusCode(), sprintf('%s (%s) answered %d.', $label, $url, $response->getStatusCode()));

            $html = (string) $response->getContent();
            $xpath = $this->xpathOf($html);

            $this->assertSame(1, $xpath->query('//h1')?->length, sprintf('%s must have exactly one <h1>.', $label));

            foreach ($xpath->query('//img') ?: [] as $image) {
                $this->assertInstanceOf(DOMElement::class, $image);
                $this->assertTrue($image->hasAttribute('alt'), sprintf('%s renders an <img> without alt: %s', $label, $image->getAttribute('src')));
            }

            $title = trim((string) $xpath->query('//head/title')?->item(0)?->textContent);
            $this->assertNotSame('', $title, sprintf('%s must have a <title>.', $label));

            $description = $xpath->query('//head/meta[@name="description"]')?->item(0);
            $this->assertInstanceOf(DOMElement::class, $description, sprintf('%s must have a meta description.', $label));
            $this->assertNotSame('', trim($description->getAttribute('content')), sprintf('%s has an empty meta description.', $label));

            $this->assertMatchesRegularExpression('/<meta\s+name="viewport"\s+content="[^"]*width=device-width/i', $html, $label.' must declare a device-width viewport.');

            $this->assertRenderedHtmlIsDarkModeClean($html, $label, '//body');
        }

        // The images the fixtures carry are really on the pages checked above.
        $this->assertGreaterThan(0, substr_count((string) $this->get($pages['/portfolio/{slug}'])->getContent(), '<img'), 'The project page must render its gallery for the alt check to mean anything.');
    }

    /**
     * §11 test 9: a draft service is 404 on `/services/{slug}` and absent from `/services`; publishing makes
     * both answer.
     */
    public function test_contract_09_a_draft_service_is_invisible_publicly_until_published(): void
    {
        $service = $this->makeService(false, ['name' => 'Cloud migration consulting']);

        $this->get(route('site.services.show', ['service' => $service->slug]))->assertNotFound();
        $this->get(route('site.services.index'))->assertOk()->assertDontSee('Cloud migration consulting', false);

        $this->actingAs($this->superAdmin)
            ->sendCms('POST', route('admin.services.status', $service), ['status' => 'published'])
            ->assertOk();

        $this->signOut();

        $this->get(route('site.services.show', ['service' => $service->slug]))->assertOk()->assertSee('Cloud migration consulting', false);
        $this->get(route('site.services.index'))->assertOk()->assertSee('Cloud migration consulting', false);
    }

    /**
     * §11 test 14 (the screen half): a team member with `is_public = false` is absent from `/team` and present
     * in the admin list, marked "Hidden from website".
     */
    public function test_contract_14_a_hidden_team_member_is_absent_from_team_but_listed_in_admin(): void
    {
        $visible = $this->makeTeamMember(true, true, ['name' => 'Ayesha Visible']);
        $hidden = $this->makeTeamMember(true, false, ['name' => 'Bilal Hidden']);

        $this->assertSame(1, (int) DB::table('team_members')->where('id', $visible->getKey())->value('is_public'));
        $this->assertSame(0, (int) DB::table('team_members')->where('id', $hidden->getKey())->value('is_public'));

        $this->get(route('site.team.index'))
            ->assertOk()
            ->assertSee('Ayesha Visible', false)
            ->assertDontSee('Bilal Hidden', false);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.team.index'))
            ->assertOk()
            ->assertSee('Ayesha Visible', false)
            ->assertSee('Bilal Hidden', false)
            ->assertSee('Hidden from website', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * One of every state the screens branch on, with images and taxonomy links, so each list, editor and
     * public page renders its full shape.
     *
     * @return array<string, mixed>
     */
    private function richFixtures(): array
    {
        return $this->sharedMarketing('rich', function (): array {
            $category = $this->makeTerm('service_categories');
            $technology = $this->makeTerm('technologies');
            $portfolioCategory = $this->makeTerm('portfolio_categories');
            $blogCategory = $this->makeTerm('blog_categories');

            $service = $this->makeService(true, [
                'service_category_id' => (int) $category->getKey(),
                'starting_price' => '1234567.89',
                'short_description' => 'Websites and web applications built to last.',
                'features' => ['Discovery workshop', 'Design system', 'Launch support'],
            ]);
            $this->makeService(false, ['price_visible' => false, 'starting_price' => '500.00']);

            $cover = $this->makeMediaAsset();
            $second = $this->makeMediaAsset();
            $gallery = $this->attachToGallery($this->makePortfolioItem(false, [
                'portfolio_category_id' => (int) $portfolioCategory->getKey(),
                'client_name' => 'Acme Retail',
                'completion_date' => now()->subMonth()->toDateString(),
            ]), $cover, $second);
            $gallery = $this->withoutActor(fn () => app(PortfolioService::class)->changeStatus($gallery, ContentStatus::Published));

            $this->makeTeamMember(true, true, ['department' => 'Engineering', 'skills' => ['Laravel', 'Vue'], 'social_links' => ['github' => 'https://github.com/example']]);
            $hidden = $this->makeTeamMember(true, false);

            $this->makeTestimonial('pending');
            $approved = $this->makeTestimonial('approved', ['rating' => 5]);
            $this->withoutActor(fn () => app(ModerationService::class)->toggleFeatured($approved));
            $this->makeTestimonial('rejected');
            $this->makeStudentReview('pending', ['course_name' => 'Laravel bootcamp', 'rating' => 4]);
            $this->makeStudentReview('approved');
            $this->makeSuccessStory(true, ['course_name' => 'Laravel bootcamp', 'company_name' => 'Acme', 'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);

            $published = $this->makeBlogPost($this->superAdmin, 'published', [
                'blog_category_id' => (int) $blogCategory->getKey(),
                'excerpt' => 'What we learned shipping a platform.',
                'featured_image_media_id' => (int) $cover->getKey(),
            ], ['Laravel', 'Engineering']);
            $scheduled = $this->makeBlogPost($this->superAdmin, 'scheduled');
            $archived = $this->makeBlogPost(null, 'published');
            $this->withoutActor(fn () => app(BlogService::class)->archive($archived));
            $this->makeBlogPost(null, 'draft');

            $tag = DB::table('blog_tags')->whereRaw('LOWER(name) = ?', ['laravel'])->first();

            $openJob = $this->makeJobOpening('open', null, [
                'department' => 'Engineering',
                'location' => 'Lahore',
                'salary_min' => '150000.00',
                'salary_max' => '250000.00',
                'salary_visible' => true,
            ]);
            $this->makeJobOpening('closed');

            $application = $this->makeJobApplication($openJob, $this->superAdmin);
            $rejected = $this->makeJobApplication($openJob);
            $this->withoutActor(fn () => app(JobApplicationService::class)->changeStatus($rejected, JobApplicationStatus::Rejected, ['reason' => 'Not enough experience']));
            $this->withoutActor(fn () => app(JobApplicationService::class)->saveNotes($application, 'Strong portfolio.', 4));

            $pending = $this->makeContactInquiry([
                'inquiry_type' => 'service',
                'service_id' => (int) $service->getKey(),
                'routing_status' => 'pending',
                'routing_target' => 'crm_lead',
                'routing_error' => 'target_unregistered',
                'ip_address' => '198.51.100.9',
            ]);
            $spam = $this->makeContactInquiry(['is_spam' => true, 'spam_reason' => 'honeypot']);
            $this->makeContactInquiry(['inquiry_type' => 'course', 'course_name' => 'Laravel bootcamp', 'routing_status' => 'failed', 'routing_target' => 'course_inquiry', 'routing_attempts' => 3, 'routing_error' => 'Timeout']);

            return [
                'category' => (int) $category->getKey(),
                'technology' => (int) $technology->getKey(),
                'service' => (int) $service->getKey(),
                'service_slug' => (string) $service->slug,
                'gallery' => (int) $gallery->getKey(),
                'gallery_slug' => (string) $gallery->slug,
                'hidden_member' => (int) $hidden->getKey(),
                'blog_category' => (int) $blogCategory->getKey(),
                'blog_category_slug' => (string) $blogCategory->getAttribute('slug'),
                'blog_tag' => (int) ($tag->id ?? 0),
                'blog_tag_slug' => (string) ($tag->slug ?? ''),
                'published_post' => (int) $published->getKey(),
                'published_post_slug' => (string) $published->slug,
                'scheduled_post' => (int) $scheduled->getKey(),
                'open_job' => (int) $openJob->getKey(),
                'open_job_slug' => (string) $openJob->slug,
                'rejected_application' => (int) $rejected->getKey(),
                'pending_inquiry' => (int) $pending->getKey(),
                'spam_inquiry' => (int) $spam->getKey(),
            ];
        });
    }

    /**
     * FT-51's rendered-HTML rule, scoped: inside `$scope`, every light background and dark text utility
     * carries its `dark:` counterpart unless it sits inside a forced-dark scope, every table scrolls in its
     * own container, no inline width is wider than a phone, and no error leaks.
     */
    private function assertRenderedHtmlIsDarkModeClean(string $html, string $label, string $scope, bool $skipDecorative = false): void
    {
        foreach (['Undefined variable', 'Undefined array key', 'ErrorException', 'Stack trace', 'Whoops'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, sprintf('%s leaks an error ("%s").', $label, $leak));
        }

        $xpath = $this->xpathOf($html);
        $violations = [];

        foreach ($xpath->query($scope.'//*[@class]') ?: [] as $node) {
            if (! $node instanceof DOMElement || $this->insideForcedDarkScope($node)) {
                continue;
            }

            if ($skipDecorative && $node->getAttribute('aria-hidden') === 'true') {
                continue;
            }

            $tokens = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
            $darkBackground = $this->hasTokenStartingWith($tokens, 'dark:bg-');
            $darkText = $this->hasTokenStartingWith($tokens, 'dark:text-');

            foreach ($tokens as $token) {
                if (preg_match('/^bg-(white|(slate|gray|zinc|neutral|stone)-(50|100|200))$/', $token) === 1 && ! $darkBackground) {
                    $violations[] = sprintf('<%s class="%s"> has %s with no dark:bg- counterpart', $node->tagName, $node->getAttribute('class'), $token);
                }

                if (preg_match('/^text-(black|(slate|gray|zinc|neutral|stone)-(700|800|900|950))$/', $token) === 1 && ! $darkText) {
                    $violations[] = sprintf('<%s class="%s"> has %s with no dark:text- counterpart', $node->tagName, $node->getAttribute('class'), $token);
                }
            }
        }

        foreach ($xpath->query($scope.'//table') ?: [] as $table) {
            if (! $this->scrollsInsideItsOwnContainer($table)) {
                $violations[] = 'a <table> is not inside its own horizontal scroll container (overflow-x-auto)';
            }
        }

        foreach ($xpath->query($scope.'//*[@style]') ?: [] as $node) {
            if ($node instanceof DOMElement
                && preg_match('/(?:^|;)\s*(?:min-)?width\s*:\s*(\d+)px/i', $node->getAttribute('style'), $match) === 1
                && (int) $match[1] > 375) {
                $violations[] = sprintf('<%s style="%s"> is wider than a 375 px screen', $node->tagName, $node->getAttribute('style'));
            }
        }

        $this->assertSame([], $violations, sprintf("%s is not dark-mode clean / responsive (§11 test 59, CLAUDE.md §6):\n%s", $label, implode("\n", array_unique($violations))));
    }

    private function xpathOf(string $html): DOMXPath
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function insideForcedDarkScope(DOMNode $node): bool
    {
        for ($current = $node; $current instanceof DOMElement; $current = $current->parentNode) {
            $tokens = preg_split('/\s+/', trim($current->getAttribute('class'))) ?: [];

            if (in_array('dark', $tokens, true)) {
                return true;
            }
        }

        return false;
    }

    private function scrollsInsideItsOwnContainer(DOMNode $table): bool
    {
        for ($current = $table; $current instanceof DOMElement; $current = $current->parentNode) {
            foreach (preg_split('/\s+/', trim($current->getAttribute('class'))) ?: [] as $token) {
                if (preg_match('/(^|:)overflow-(x-)?(auto|scroll)$/', $token) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function hasTokenStartingWith(array $tokens, string $prefix): bool
    {
        foreach ($tokens as $token) {
            if (str_starts_with($token, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
