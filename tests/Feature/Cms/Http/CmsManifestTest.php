<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Http;

use App\Services\Cms\MediaService;
use App\Support\PermissionRegistry;
use Closure;
use FilesystemIterator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Feature\Cms\Http\Concerns\CmsHttpFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Build-order E4 / B9 and the Phase 3 definition of done (D60, F-9.2, D21, D25): the four manifests of
 * phase-24-25 §6.1 and the raw-output allowlist of SEC-05 exist under tests/Support and carry Phase 3's own
 * rows — and those rows are true.
 *
 * Phase 24's `audit:manifest --check` will police every phase at once; until it ships, this test is the
 * drift check for Phase 3's rows:
 *
 *   · route-guard: one row per Phase 3 route, equal to the live route (methods, middleware, `can:`), with a
 *     written rationale exactly where there is no permission;
 *   · screen: one row per Phase 3 GET route, whose params closure resolves to a URL that answers;
 *   · upload: every Phase 3 Form Request with a file rule has a row naming the disk, the sniffed MIME list
 *     and the permission the uploader really uses;
 *   · index: every listed index exists in the schema and every Phase 3 foreign-key column has its own row;
 *   · raw output: every `{!! !!}` / `x-html` in the Phase 3 view roots is listed, with RichText::sanitize()
 *     as the one sanitiser.
 */
final class CmsManifestTest extends TestCase
{
    use CmsHttpFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    /** The seven public routes phase-03 §7.6 registers; every other Phase 3 route is `admin.website.*`. */
    private const PUBLIC_ROUTES = ['site.home', 'site.robots', 'site.sitemap', 'site.sitemap.chunk', 'site.preview.page', 'site.preview.section', 'site.page'];

    /** The 12 tables and 2 pivots of phase-03 §2.1. */
    private const TABLES = [
        'media_assets', 'cta_blocks', 'pages', 'menus', 'menu_items', 'website_sections', 'website_section_items',
        'website_section_media', 'faq_categories', 'faqs', 'faq_website_section', 'seo_meta', 'cms_revisions', 'sitemap_generations',
    ];

    /** The view roots Phase 3 owns for SEC-05. */
    private const VIEW_ROOTS = ['site', 'components/site', 'admin/cms'];

    private const SANITISER = 'App\Support\RichText::sanitize()';

    private const KINDS = ['index', 'show', 'form', 'board', 'calendar', 'wizard', 'print', 'public', 'export', 'dashboard', 'statement'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        Storage::fake('public');
    }

    public function test_the_four_manifests_and_the_raw_output_allowlist_exist(): void
    {
        foreach (['screen-manifest', 'route-guard-manifest', 'upload-manifest', 'index-manifest', 'raw-output-allowlist'] as $file) {
            $path = base_path('tests/Support/'.$file.'.php');

            $this->assertFileExists($path, sprintf('build-order E4 / SEC-05: tests/Support/%s.php is a Phase 3 deliverable.', $file));
            $this->assertIsArray(require $path, sprintf('%s.php returns an array.', $file));
        }
    }

    public function test_every_phase_3_route_has_one_true_route_guard_row(): void
    {
        $rows = $this->rowsByRoute($this->manifest('route-guard-manifest'));
        $permissions = PermissionRegistry::permissionNames();
        $live = $this->phase3Routes();

        $this->assertCount(85, $live, 'Phase 3 registers 85 routes (78 admin.website.*, 7 site.*).');

        foreach ($live as $name => $route) {
            $this->assertArrayHasKey($name, $rows, sprintf('Route %s has no route-guard-manifest row.', $name));

            $row = $rows[$name];
            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            $can = array_values(array_filter($middleware, static fn (string $m): bool => str_starts_with($m, 'can:')));
            $permission = $can === [] ? null : substr($can[0], 4);

            $this->assertSame($route->methods(), $row['methods'], sprintf('%s: methods drifted.', $name));
            $this->assertSame($middleware, $row['middleware'], sprintf('%s: middleware drifted.', $name));
            $this->assertSame($permission, $row['permission'], sprintf('%s: the permission must be the route\'s can:.', $name));
            $this->assertSame(in_array($name, self::PUBLIC_ROUTES, true) ? 'public' : 'admin', $row['panel'], sprintf('%s: panel.', $name));
            $this->assertSame(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [], $row['state_changing'], sprintf('%s: state_changing.', $name));
            $this->assertSame(3, $row['owner_phase']);

            if ($permission === null) {
                $this->assertIsString($row['rationale'], sprintf('%s has no can: and so needs a written rationale.', $name));
                $this->assertGreaterThan(40, mb_strlen(trim((string) $row['rationale'])), sprintf('%s: the rationale must say why, not merely exist.', $name));
            } else {
                $this->assertNull($row['rationale'], sprintf('%s is guarded by can:%s; a rationale would blur the two kinds of row.', $name, $permission));
                $this->assertContains($permission, $permissions, sprintf('%s names a permission PermissionRegistry does not declare.', $name));
            }
        }

        foreach ($rows as $name => $row) {
            if (($row['owner_phase'] ?? null) === 3) {
                $this->assertArrayHasKey($name, $live, sprintf('route-guard-manifest lists %s, which Phase 3 no longer registers.', $name));
            }
        }
    }

    public function test_every_phase_3_get_route_has_a_screen_row_that_answers(): void
    {
        $rows = $this->rowsByRoute($this->manifest('screen-manifest'));
        $guards = $this->rowsByRoute($this->manifest('route-guard-manifest'));
        $gets = array_filter($this->phase3Routes(), static fn (RoutingRoute $route): bool => in_array('GET', $route->methods(), true));

        $this->assertCount(35, $gets, 'Phase 3 registers 35 GET routes.');

        // The one model the seeder does not provide (see the manifest header).
        $this->makeMediaAsset();

        $super = $this->createSuperAdmin();

        foreach ($gets as $name => $route) {
            $this->assertArrayHasKey($name, $rows, sprintf('GET route %s has no screen-manifest row.', $name));

            $row = $rows[$name];
            $guard = $guards[$name];
            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            $module = array_values(array_filter($middleware, static fn (string $m): bool => str_starts_with($m, 'module:')));

            $this->assertSame($guard['panel'], $row['panel'], sprintf('%s: panel.', $name));
            $this->assertContains($row['kind'], self::KINDS, sprintf('%s: unknown kind.', $name));
            $this->assertInstanceOf(Closure::class, $row['params'], sprintf('%s: params is a closure.', $name));
            $this->assertSame($module === [] ? null : substr($module[0], 7), $row['module'], sprintf('%s: module must be the route\'s module: gate.', $name));
            $this->assertSame(3, $row['owner_phase']);
            $this->assertIsInt($row['query_budget']);
            $this->assertGreaterThan(0, $row['query_budget']);
            $this->assertIsBool($row['responsive']);
            $this->assertIsBool($row['a11y']);
            $this->assertSame(['owner' => null], $row['idor'], sprintf('%s: a staff CMS screen has no owner scope.', $name));
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

            $url = route($name, ($row['params'])(null));
            $response = $row['response'] === 'json'
                ? $this->actingAs($super)->getJson($url)
                : $this->actingAs($super)->get($url);

            $this->assertSame($row['expect'] ?? 200, $response->getStatusCode(), sprintf('%s (%s) answered %d on the standard fixture.', $name, $url, $response->getStatusCode()));
        }

        foreach ($rows as $name => $row) {
            if (($row['owner_phase'] ?? null) === 3) {
                $this->assertArrayHasKey($name, $gets, sprintf('screen-manifest lists %s, which is not a Phase 3 GET route.', $name));
            }
        }
    }

    public function test_every_phase_3_upload_field_has_a_true_upload_row(): void
    {
        $rows = collect($this->manifest('upload-manifest'))->where('owner_phase', 3)->values();
        $guards = $this->rowsByRoute($this->manifest('route-guard-manifest'));
        $uploaders = [];

        // Every Form Request a Phase 3 route resolves, read for a file rule.
        foreach ($this->phase3Routes() as $name => $route) {
            foreach ($this->formRequestsOf($route) as $class) {
                $source = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());

                if (preg_match("~'(file|image)'|mimes:|mimetypes:|File::~", $source) === 1) {
                    $uploaders[$name] = $class;
                }
            }
        }

        $this->assertSame(['admin.website.media.store'], array_keys($uploaders), 'The media library is Phase 3\'s one uploader (D24).');

        foreach (array_keys($uploaders) as $name) {
            $row = $rows->firstWhere('route', $name);

            $this->assertIsArray($row, sprintf('%s accepts a file and has no upload-manifest row.', $name));
            $this->assertSame('file', $row['field']);
            $this->assertSame(MediaService::DISK, $row['disk'], 'The row names the disk MediaService really writes to.');
            $this->assertSame(array_keys(MediaService::IMAGE_MIMES + MediaService::VIDEO_MIMES), $row['allowed_mimes'], 'The row names the MIME list MediaService really accepts.');
            $this->assertNotContains('image/svg+xml', $row['allowed_mimes'], '[D-W3-15]: SVG is never an upload.');
            $this->assertSame('security.max_upload_mb', $row['max_mb']);
            $this->assertSame($guards[$name]['permission'], $row['permission']);
            $this->assertTrue($row['public_reachable'], 'CMS images are public website content.');
        }
    }

    public function test_every_index_manifest_entry_exists_and_every_foreign_key_is_listed(): void
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
            $this->assertArrayHasKey($table, $manifest, sprintf('index-manifest has no entry for Phase 3\'s %s table.', $table));

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

        $this->assertGreaterThanOrEqual(40, count($foreignKeys), 'Phase 3 declares at least 40 foreign keys.');

        foreach ($foreignKeys as $fk) {
            $this->assertContains([(string) $fk->c], $manifest[(string) $fk->t] ?? [], sprintf('F-9.2: the foreign key %s.%s has no index-manifest row of its own.', $fk->t, $fk->c));
        }
    }

    public function test_every_raw_echo_in_the_phase_3_views_is_allowlisted_with_the_one_sanitiser(): void
    {
        $allowlist = $this->manifest('raw-output-allowlist');
        $root = resource_path('views');
        $found = [];

        foreach ($allowlist as $row) {
            $this->assertSame(self::SANITISER, $row['sanitiser'], sprintf('%s: RichText::sanitize() is the one sanitiser (D25, F-2.5).', $row['view']));
        }

        foreach (self::VIEW_ROOTS as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.DIRECTORY_SEPARATOR.$directory, FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));
                $echoes = [];

                preg_match_all('/\{!!(.*?)!!\}/s', $code, $raw);
                preg_match_all('/\bx-html\s*=\s*"([^"]*)"/', $code, $html);

                array_push($echoes, ...$raw[1], ...$html[1]);

                if ($echoes !== []) {
                    $found[$relative] = $echoes;
                }
            }
        }

        $this->assertArrayHasKey('components/site/prose.blade.php', $found, 'The scan must see x-site.prose, the site\'s one raw echo.');

        foreach ($found as $view => $echoes) {
            $rows = array_values(array_filter($allowlist, static fn (array $row): bool => $row['view'] === $view));

            $this->assertNotSame([], $rows, sprintf('%s prints %d unescaped echo(es) and has no raw-output-allowlist row.', $view, count($echoes)));
            $this->assertSame(count($echoes), array_sum(array_column($rows, 'occurrences')), sprintf('%s: every raw echo needs its row.', $view));

            foreach ($rows as $row) {
                $matching = array_filter($echoes, static fn (string $echo): bool => str_contains($echo, $row['expression']));

                $this->assertCount($row['occurrences'], $matching, sprintf('%s: the allowlisted echo "%s" is not in the view as listed.', $view, $row['expression']));
                $this->assertStringContainsString('RichText::sanitize(', $row['expression'], sprintf('%s: the listed echo must itself be RichText::sanitize() output.', $view));
            }
        }

        foreach ($allowlist as $row) {
            if (($row['owner_phase'] ?? null) === 3) {
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
    private function phase3Routes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (str_starts_with($name, 'admin.website.') || in_array($name, self::PUBLIC_ROUTES, true)) {
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
}
