<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Http;

use App\Http\Requests\Cms\PublicJobApplicationRequest;
use App\Models\Cms\BlogCategory;
use App\Models\Cms\BlogPost;
use App\Models\Cms\ContactInquiry;
use App\Models\Cms\JobApplication;
use App\Models\Cms\JobOpening;
use App\Models\Cms\PortfolioItem;
use App\Models\Cms\Service;
use App\Models\Cms\StudentReview;
use App\Models\Cms\SuccessStory;
use App\Models\Cms\TeamMember;
use App\Models\Cms\Testimonial;
use App\Models\User;
use App\Services\Cms\ApplicationCvService;
use App\Services\Cms\MediaService;
use App\Support\PermissionRegistry;
use Closure;
use FilesystemIterator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Feature\Cms\Http\Concerns\CmsHttpFixtures;
use Tests\Feature\Cms\Marketing\Http\Concerns\MarketingHttpFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The Phase 4 definition of done for the four D60 manifests (build-order §9 (a) and (c), phase-04 §13 "Phase 24"
 * rows, §11 test 63's manifest half) — Phase 4's rows exist under their own banner and are true.
 *
 * Phase 24's `audit:manifest --check` will police every phase at once; until it ships, this test is the drift
 * check for Phase 4's rows, as `Cms/Http/CmsManifestTest` is for Phase 3's:
 *
 *   · route-guard: one row per Phase 4 route (153 admin + 15 site), equal to the live route (methods,
 *     middleware, `can:`), with a written rationale exactly where there is no permission;
 *   · screen: one row per Phase 4 GET route, whose params closure resolves to a URL that answers on a
 *     fixture holding one row of every Phase 4 model;
 *   · upload: every Phase 4 route whose Form Request accepts a file has a row naming the field, the disk the
 *     service really writes to, the content-sniffed MIME list and the route's own permission — the CV on the
 *     private `local` disk (D21), every image through `MediaService` on `public` (D24);
 *   · index: every Phase 4 entry exists in the schema, every foreign key and every deferred id of phase-04
 *     §2.1 has its own row, and `contact_inquiries.referral_code` is listed (§13 Phase 24 row);
 *   · raw output: the Phase 4 view roots print no unescaped echo that is not allowlisted with
 *     `RichText::sanitize()` as its sanitiser (SEC-05, D25).
 */
final class MarketingManifestTest extends TestCase
{
    use CmsHttpFixtures;
    use InteractsWithRbac;
    use MarketingHttpFixtures;
    use RefreshDatabase;

    private const ADMIN_PREFIXES = [
        'service-categories', 'technologies', 'services', 'portfolio-categories', 'portfolio', 'team', 'testimonials',
        'student-reviews', 'success-stories', 'blog-categories', 'blog-tags', 'blog-posts', 'jobs', 'job-applications',
        'contact-inquiries',
    ];

    private const SITE_PREFIXES = ['services', 'portfolio', 'team', 'blog', 'careers', 'contact'];

    /** The 20 tables of phase-04 §2. */
    private const TABLES = [
        'service_categories', 'technologies', 'services', 'service_technology', 'portfolio_categories', 'portfolio_items',
        'portfolio_item_technology', 'portfolio_item_media', 'team_members', 'testimonials', 'student_reviews',
        'success_stories', 'blog_categories', 'blog_tags', 'blog_post_blog_tag', 'blog_posts', 'blog_post_views',
        'job_openings', 'job_applications', 'contact_inquiries',
    ];

    /** phase-04 §2.1: the fourteen deferred ids (no constraint yet, an index and a manifest row from day one). */
    private const DEFERRED_IDS = [
        'portfolio_items.client_id', 'testimonials.client_id', 'testimonials.student_id', 'student_reviews.student_id',
        'student_reviews.course_id', 'success_stories.student_id', 'success_stories.course_id', 'contact_inquiries.course_id',
        'team_members.department_id', 'job_openings.department_id', 'team_members.employee_id', 'job_applications.employee_id',
        'contact_inquiries.collaborator_id', 'contact_inquiries.referral_visit_id',
    ];

    /** The view roots Phase 4 owns (admin screens, public pages and partials, components, widgets). */
    private const VIEW_ROOTS = [
        'admin/service-categories', 'admin/technologies', 'admin/services', 'admin/portfolio-categories', 'admin/portfolio',
        'admin/team', 'admin/testimonials', 'admin/student-reviews', 'admin/success-stories', 'admin/blog-categories',
        'admin/blog-tags', 'admin/blog-posts', 'admin/jobs', 'admin/job-applications', 'admin/contact-inquiries',
        'components/cms', 'site/services', 'site/portfolio', 'site/team', 'site/blog', 'site/careers', 'site/marketing',
    ];

    private const SANITISER = 'App\Support\RichText::sanitize()';

    private const KINDS = ['index', 'show', 'form', 'board', 'calendar', 'wizard', 'print', 'public', 'export', 'dashboard', 'statement'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_every_phase_4_route_has_one_true_route_guard_row(): void
    {
        $rows = $this->rowsByRoute($this->manifest('route-guard-manifest'));
        $permissions = PermissionRegistry::permissionNames();
        $live = $this->phase4Routes();

        $this->assertCount(168, $live, 'Phase 4 registers 168 routes (153 admin, 15 site; integration §5).');

        foreach ($live as $name => $route) {
            $this->assertArrayHasKey($name, $rows, sprintf('Route %s has no route-guard-manifest row.', $name));

            $row = $rows[$name];
            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            $can = array_values(array_filter($middleware, static fn (string $m): bool => str_starts_with($m, 'can:')));
            $permission = $can === [] ? null : substr($can[0], 4);

            $this->assertSame($route->methods(), $row['methods'], sprintf('%s: methods drifted.', $name));
            $this->assertSame($middleware, $row['middleware'], sprintf('%s: middleware drifted.', $name));
            $this->assertSame($permission, $row['permission'], sprintf('%s: the permission must be the route\'s can:.', $name));
            $this->assertSame(str_starts_with($name, 'site.') ? 'public' : 'admin', $row['panel'], sprintf('%s: panel.', $name));
            $this->assertSame(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [], $row['state_changing'], sprintf('%s: state_changing.', $name));
            $this->assertSame(4, $row['owner_phase']);

            if ($permission === null) {
                $this->assertStringStartsWith('site.', $name, sprintf('%s is an admin route without a can: permission.', $name));
                $this->assertIsString($row['rationale'], sprintf('%s has no can: and so needs a written rationale.', $name));
                $this->assertGreaterThan(40, mb_strlen(trim((string) $row['rationale'])), sprintf('%s: the rationale must say why, not merely exist.', $name));
            } else {
                $this->assertNull($row['rationale'], sprintf('%s is guarded by can:%s; a rationale would blur the two kinds of row.', $name, $permission));
                $this->assertContains($permission, $permissions, sprintf('%s names a permission PermissionRegistry does not declare.', $name));
            }
        }

        foreach ($rows as $name => $row) {
            if (($row['owner_phase'] ?? null) === 4) {
                $this->assertArrayHasKey($name, $live, sprintf('route-guard-manifest lists %s, which Phase 4 no longer registers.', $name));
            }
        }
    }

    public function test_every_phase_4_get_route_has_a_screen_row_that_answers(): void
    {
        $rows = $this->rowsByRoute($this->manifest('screen-manifest'));
        $guards = $this->rowsByRoute($this->manifest('route-guard-manifest'));
        $gets = array_filter($this->phase4Routes(), static fn (RoutingRoute $route): bool => in_array('GET', $route->methods(), true));

        $this->assertCount(76, $gets, 'Phase 4 registers 76 GET routes.');

        $this->makeScreenFixture();

        $super = $this->createSuperAdmin();

        foreach ($gets as $name => $route) {
            $this->assertArrayHasKey($name, $rows, sprintf('GET route %s has no screen-manifest row.', $name));

            $row = $rows[$name];
            $guard = $guards[$name];
            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            $module = array_values(array_filter($middleware, static fn (string $m): bool => str_starts_with($m, 'module:')));

            $this->assertSame($guard['panel'], $row['panel'], sprintf('%s: panel.', $name));
            $this->assertContains($row['kind'], self::KINDS, sprintf('%s: unknown kind.', $name));
            $this->assertSame($guard['panel'] === 'public', $row['kind'] === 'public', sprintf('%s: the public kind is for site routes only.', $name));
            $this->assertInstanceOf(Closure::class, $row['params'], sprintf('%s: params is a closure.', $name));
            $this->assertSame($module === [] ? null : substr($module[0], 7), $row['module'], sprintf('%s: module must be the route\'s module: gate.', $name));
            $this->assertSame(4, $row['owner_phase']);
            $this->assertIsInt($row['query_budget']);
            $this->assertGreaterThan(0, $row['query_budget']);
            $this->assertIsBool($row['responsive']);
            $this->assertIsBool($row['a11y']);
            $this->assertIsArray($row['idor']);
            $this->assertArrayHasKey('owner', $row['idor']);
            $this->assertContains($row['response'], ['html', 'json', 'csv', 'text', 'xml']);

            if ($guard['permission'] !== null) {
                $this->assertSame([$guard['permission']], $row['permissions'], sprintf('%s: permissions must be the route\'s can:.', $name));
            }

            foreach ($row['permissions'] as $permission) {
                $this->assertContains($permission, PermissionRegistry::permissionNames());
            }

            if ($row['response'] !== 'html') {
                $this->assertFalse($row['responsive'] || $row['a11y'], sprintf('%s answers %s: it is not in the HTML sweeps.', $name, $row['response']));
            }

            if ($row['idor']['owner'] !== null) {
                $this->assertContains($row['idor']['owner'], ['assigned_to', 'author_id'], sprintf('%s: the owner column must be the one its scope reads (§9.1).', $name));
            }

            $url = route($name, ($row['params'])(null));

            if ($row['panel'] === 'public') {
                $this->signOut();
                $response = $this->get($url);
            } else {
                $response = $row['response'] === 'json'
                    ? $this->actingAs($super)->getJson($url)
                    : $this->actingAs($super)->get($url);
            }

            $this->assertSame($row['expect'] ?? 200, $response->getStatusCode(), sprintf('%s (%s) answered %d on the Phase 4 fixture.', $name, $url, $response->getStatusCode()));
        }

        foreach ($rows as $name => $row) {
            if (($row['owner_phase'] ?? null) === 4) {
                $this->assertArrayHasKey($name, $gets, sprintf('screen-manifest lists %s, which is not a Phase 4 GET route.', $name));
            }
        }
    }

    public function test_every_phase_4_upload_field_has_a_true_upload_row(): void
    {
        $rows = collect($this->manifest('upload-manifest'))->where('owner_phase', 4)->values();
        $guards = $this->rowsByRoute($this->manifest('route-guard-manifest'));

        $this->makeScreenFixture();

        $super = $this->createSuperAdmin();
        $live = [];

        // The real rule set of every Form Request a Phase 4 write route resolves, built against the matched
        // route and a bound fixture — so a file rule composed from a trait, or prohibited on update, counts
        // exactly as the request enforces it.
        foreach ($this->phase4Routes() as $name => $route) {
            if (in_array('GET', $route->methods(), true)) {
                continue;
            }

            foreach ($this->formRequestsOf($route) as $class) {
                foreach ($this->fileFieldsOf($name, $route, $class, $super) as $field) {
                    $live[] = $name.' '.$field;
                }
            }
        }

        sort($live);

        $listed = $rows->map(static fn (array $row): string => $row['route'].' '.$row['field'])->sort()->values()->all();

        $this->assertSame($live, $listed, 'One upload-manifest row per Phase 4 route and file field its Form Request accepts, and no other.');

        $imageMimes = array_keys(MediaService::IMAGE_MIMES);

        foreach ($rows as $row) {
            $name = $row['route'];

            $this->assertArrayHasKey($name, $guards);
            $this->assertSame($guards[$name]['permission'], $row['permission'], sprintf('%s: the permission must be the route\'s can:.', $name));
            $this->assertIsBool($row['public_reachable']);

            if ($name === 'site.careers.apply') {
                $this->assertSame('cv', $row['field']);
                $this->assertSame(ApplicationCvService::DISK, $row['disk'], 'The row names the disk ApplicationCvService really writes to.');
                $this->assertSame(JobApplication::CV_DISK, $row['disk']);
                $this->assertNotSame('public', $row['disk'], 'D21: a CV is a private artefact.');
                $this->assertSame(PublicJobApplicationRequest::CV_MIME_TYPES, $row['allowed_mimes']);
                $this->assertFalse($row['public_reachable'], 'A CV is streamed only by admin.job-applications.cv behind job_applications.download.');

                continue;
            }

            $this->assertSame(MediaService::DISK, $row['disk'], sprintf('%s: every website image goes through MediaService (D24).', $name));
            $this->assertSame($imageMimes, $row['allowed_mimes'], sprintf('%s: the MIME list MediaService really accepts.', $name));
            $this->assertNotContains('image/svg+xml', $row['allowed_mimes']);
            $this->assertSame('security.max_upload_mb', $row['max_mb']);
            $this->assertTrue($row['public_reachable'], 'Website images are public content.');
        }
    }

    public function test_every_phase_4_index_manifest_entry_exists_and_every_foreign_key_is_listed(): void
    {
        $manifest = $this->manifest('index-manifest');
        $indexes = [];

        foreach (DB::select(
            'SELECT TABLE_NAME AS t, INDEX_NAME AS i, SEQ_IN_INDEX AS s, COLUMN_NAME AS c FROM information_schema.STATISTICS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('.implode(', ', array_fill(0, count(self::TABLES), '?')).') ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            self::TABLES,
        ) as $row) {
            $indexes[(string) $row->t][(string) $row->i][] = (string) $row->c;
        }

        foreach (self::TABLES as $table) {
            $this->assertArrayHasKey($table, $manifest, sprintf('index-manifest has no entry for Phase 4\'s %s table.', $table));

            foreach ($manifest[$table] as $columns) {
                $present = collect($indexes[$table] ?? [])->contains(
                    static fn (array $index): bool => array_slice($index, 0, count($columns)) === $columns,
                );

                $this->assertTrue($present, sprintf('index-manifest lists %s(%s), which no index of the schema leads with.', $table, implode(', ', $columns)));
            }
        }

        $foreignKeys = DB::select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE'
            .' WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME IN ('.implode(', ', array_fill(0, count(self::TABLES), '?')).')',
            self::TABLES,
        );

        $this->assertGreaterThanOrEqual(40, count($foreignKeys), 'Phase 4 declares at least 40 foreign keys.');

        foreach ($foreignKeys as $fk) {
            $this->assertContains([(string) $fk->c], $manifest[(string) $fk->t] ?? [], sprintf('F-9.2: the foreign key %s.%s has no index-manifest row of its own.', $fk->t, $fk->c));
        }

        foreach (self::DEFERRED_IDS as $deferred) {
            [$table, $column] = explode('.', $deferred, 2);

            $this->assertContains([$column], $manifest[$table], sprintf('phase-04 §2.1 / §13: the deferred id %s has its own index-manifest row from day one.', $deferred));
        }

        $this->assertContains(['referral_code'], $manifest['contact_inquiries'], 'ND-3: contact_inquiries.referral_code is indexed and listed.');
    }

    public function test_every_raw_echo_in_the_phase_4_views_is_allowlisted_with_the_one_sanitiser(): void
    {
        $allowlist = $this->manifest('raw-output-allowlist');
        $root = resource_path('views');
        $found = [];
        $scanned = 0;

        foreach (self::VIEW_ROOTS as $directory) {
            $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);

            $this->assertDirectoryExists($path, sprintf('The Phase 4 view root %s exists.', $directory));

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $scanned++;
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));

                preg_match_all('/\{!!(.*?)!!\}/s', $code, $raw);
                preg_match_all('/\bx-html\s*=\s*"([^"]*)"/', $code, $html);

                $echoes = array_merge($raw[1], $html[1]);

                if ($echoes !== []) {
                    $found[$relative] = $echoes;
                }
            }
        }

        $this->assertGreaterThan(50, $scanned, 'The scan must see the Phase 4 views.');

        foreach ($found as $view => $echoes) {
            $rows = array_values(array_filter($allowlist, static fn (array $row): bool => $row['view'] === $view));

            $this->assertNotSame([], $rows, sprintf('%s prints %d unescaped echo(es) and has no raw-output-allowlist row.', $view, count($echoes)));
            $this->assertSame(count($echoes), array_sum(array_column($rows, 'occurrences')), sprintf('%s: every raw echo needs its row.', $view));

            foreach ($rows as $row) {
                $this->assertSame(self::SANITISER, $row['sanitiser']);
                $this->assertStringContainsString('RichText::sanitize(', $row['expression']);
            }
        }

        foreach ($allowlist as $row) {
            if (($row['owner_phase'] ?? null) === 4) {
                $this->assertArrayHasKey($row['view'], $found, sprintf('raw-output-allowlist lists %s, which prints no raw echo any more.', $row['view']));
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * One row of every Phase 4 model a screen row reads, public where the public site needs it: a published
     * service, portfolio item, team member, success story and post (in a category, with a tag), an open job
     * with a stored application (its CV on the faked local disk), an approved testimonial and review, and an
     * inquiry.
     */
    private function makeScreenFixture(): void
    {
        foreach (['service_categories', 'technologies', 'portfolio_categories', 'blog_categories', 'blog_tags'] as $module) {
            $this->makeTerm($module);
        }

        $category = BlogCategory::query()->orderBy('id')->firstOrFail();

        $this->makeService(publish: true);
        $this->attachToGallery($this->makePortfolioItem(publish: true), $this->makeMediaAsset());
        $this->makeTeamMember(publish: true);
        $this->makeTestimonial('approved');
        $this->makeStudentReview('approved');
        $this->makeSuccessStory(publish: true);
        $this->makeBlogPost(null, 'published', ['blog_category_id' => $category->getKey()], ['Manifest']);
        $this->makeJobApplication($this->makeJobOpening('open'));
        $this->makeContactInquiry();
    }

    /**
     * @return array<int|string, mixed>
     */
    private function manifest(string $file): array
    {
        return require base_path('tests/Support/'.$file.'.php');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsByRoute(array $rows): array
    {
        $byRoute = [];

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey($row['route'], $byRoute, sprintf('%s is listed twice.', $row['route']));
            $byRoute[$row['route']] = $row;
        }

        return $byRoute;
    }

    /**
     * @return array<string, RoutingRoute>
     */
    private function phase4Routes(): array
    {
        $pattern = '/^(admin\.('.implode('|', array_map('preg_quote', self::ADMIN_PREFIXES)).')\.|site\.('.implode('|', self::SITE_PREFIXES).')\.)/';
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (preg_match($pattern, $name) === 1) {
                $routes[$name] = $route;
            }
        }

        ksort($routes);

        return $routes;
    }

    /**
     * The Form Request classes a route's controller action type-hints.
     *
     * @return list<class-string<FormRequest>>
     */
    private function formRequestsOf(RoutingRoute $route): array
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return [];
        }

        [$controller, $method] = explode('@', $action, 2);

        if (! method_exists($controller, $method)) {
            return [];
        }

        $classes = [];

        foreach ((new ReflectionMethod($controller, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), FormRequest::class)) {
                $classes[] = $type->getName();
            }
        }

        return $classes;
    }

    /**
     * The fields a Form Request accepts as a file on this route: its real `rules()`, resolved against the
     * matched route (bound to the fixture) and a Super Admin, keeping every key whose rule list contains
     * `file` and not `prohibited`.
     *
     * @param  class-string<FormRequest>  $class
     * @return list<string>
     */
    private function fileFieldsOf(string $name, RoutingRoute $route, string $class, User $super): array
    {
        $url = route($name, $this->writeRouteParameters($route));
        $method = array_values(array_diff($route->methods(), ['HEAD']))[0];

        $request = Request::create($url, $method);
        $matched = app('router')->getRoutes()->match($request);
        app('router')->substituteBindings($matched);
        app('router')->substituteImplicitBindings($matched);

        /** @var FormRequest $form */
        $form = $class::createFrom($request);
        $form->setContainer($this->app)->setRedirector($this->app->make(Redirector::class));
        $form->setRouteResolver(static fn () => $matched);
        $form->setUserResolver(static fn () => $super);

        $rules = method_exists($form, 'rules') ? (array) $this->app->call([$form, 'rules']) : [];
        $fields = [];

        foreach ($rules as $field => $rule) {
            $list = is_string($rule) ? explode('|', $rule) : (array) $rule;
            $strings = array_values(array_filter($list, 'is_string'));

            if (in_array('file', $strings, true) && ! in_array('prohibited', $strings, true)) {
                $fields[] = (string) $field;
            }
        }

        return $fields;
    }

    /**
     * Route parameters for a Phase 4 write route, each resolved to the first fixture row it names.
     *
     * @return array<string, int|string>
     */
    private function writeRouteParameters(RoutingRoute $route): array
    {
        $prefix = explode('.', (string) $route->getName())[1];
        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            $parameters[$parameter] = match ($parameter) {
                'term' => (int) $this->taxonomyModel(match ($prefix) {
                    'service-categories' => 'service_categories',
                    'portfolio-categories' => 'portfolio_categories',
                    'blog-categories' => 'blog_categories',
                    'blog-tags' => 'blog_tags',
                    default => 'technologies',
                })::query()->orderBy('id')->value('id'),
                'service' => (int) Service::query()->orderBy('id')->value('id'),
                'item' => (int) PortfolioItem::query()->orderBy('id')->value('id'),
                // portfolio_item_media is a history pivot (phase-04 §2.8, D19): it has no `id`, so the
                // first attachment is read in the gallery's own order.
                'image' => (int) DB::table('portfolio_item_media')
                    ->orderBy('portfolio_item_id')
                    ->orderBy('sort_order')
                    ->value('media_asset_id'),
                'member' => (int) TeamMember::query()->orderBy('id')->value('id'),
                'testimonial' => (int) Testimonial::query()->orderBy('id')->value('id'),
                'review' => (int) StudentReview::query()->orderBy('id')->value('id'),
                'story' => (int) SuccessStory::query()->orderBy('id')->value('id'),
                'post' => (int) BlogPost::query()->orderBy('id')->value('id'),
                'job' => (int) JobOpening::query()->orderBy('id')->value('id'),
                'application' => (int) JobApplication::query()->orderBy('id')->value('id'),
                'inquiry' => (int) ContactInquiry::query()->orderBy('id')->value('id'),
                'jobOpening' => (string) JobOpening::query()->public()->orderBy('id')->value('slug'),
                default => 1,
            };
        }

        return $parameters;
    }
}
