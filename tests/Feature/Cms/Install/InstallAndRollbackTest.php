<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Install;

use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\ContentPublisher;
use App\Services\Cms\SectionService;
use Database\Seeders\WebsiteCmsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use PDO;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;
use Throwable;

/**
 * phase-03 FT-50 — install and rollback.
 *
 *   · `migrate:fresh --seed` runs clean and the home page renders;
 *   · every Phase 3 migration rolls back cleanly, in reverse, and migrates forward again;
 *   · re-running WebsiteCmsSeeder twice changes no row count and overwrites no edited heading;
 *   · the STORED generated columns, the CHECK constraints and the unique guards exist.
 *
 * The suite's own fixture is already a `migrate:fresh --seed` of the test database, so the in-process
 * half runs against it inside the RefreshDatabase transaction. DDL auto-commits on MariaDB, so the
 * install-and-rollback half runs in a child `artisan` process against a **scratch** schema derived from
 * the test database's name, created and dropped by this test over a separate PDO connection. The child is
 * first asked which database it is connected to, and nothing destructive runs unless the answer is the
 * scratch schema: a cached configuration or a stray environment can never aim it at another database.
 */
final class InstallAndRollbackTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** The first Phase 3 migration: this one and every later migration roll back in reverse. */
    private const FIRST_PHASE_THREE_MIGRATION = '2026_09_12_070100_create_media_assets_table';

    /** @var list<string> */
    private const PHASE_THREE_TABLES = [
        'media_assets', 'cta_blocks', 'pages', 'menus', 'menu_items', 'website_sections', 'website_section_items',
        'website_section_media', 'faq_categories', 'faqs', 'faq_website_section', 'seo_meta', 'cms_revisions',
        'sitemap_generations',
    ];

    /** @var list<string> */
    private const CHECK_CONSTRAINTS = [
        'chk_media_size', 'chk_mi_depth', 'chk_mi_parent', 'chk_seo_priority', 'chk_seo_target',
        'chk_ws_page_placement', 'chk_wsi_value',
    ];

    /** @var list<string> */
    private const UNIQUE_GUARDS = [
        'uq_cta_key', 'uq_faqcat_slug', 'uq_media_checksum', 'uq_menus_location', 'uq_menus_slug', 'uq_pages_slug',
        'uq_seo_route', 'uq_seo_target', 'uq_ws_anchor', 'uq_ws_instance',
    ];

    /** Row counts the seeder must never change on a re-run. */
    private const SEEDED_TABLES = [
        'website_sections', 'website_section_items', 'pages', 'menus', 'menu_items', 'cta_blocks', 'faq_categories',
        'faqs', 'seo_meta', 'cms_revisions',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    /** FT-50 */
    public function test_install_and_rollback(): void
    {
        $this->assertTheSchemaGuaranteesExist();
        $this->assertTheSeededHomePageRenders();
        $this->assertReseedingChangesNothing();
        $this->assertAFreshInstallRollsBackAndMigratesAgain();
    }

    private function assertTheSchemaGuaranteesExist(): void
    {
        foreach (self::PHASE_THREE_TABLES as $table) {
            $this->assertTrue(DB::getSchemaBuilder()->hasTable($table), "The Phase 3 table [$table] is missing.");
        }

        $generated = collect(DB::select(
            "SELECT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'has_unpublished_changes' AND EXTRA LIKE '%STORED GENERATED%'"
        ))->pluck('t')->map(static fn (mixed $t): string => (string) $t)->sort()->values()->all();
        $this->assertSame(['pages', 'website_sections'], $generated, 'has_unpublished_changes must be a STORED generated column on both snapshot tables.');

        $checks = collect(DB::select(
            'SELECT CONSTRAINT_NAME AS c FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()'
        ))->pluck('c')->map(static fn (mixed $c): string => (string) $c)->all();

        foreach (self::CHECK_CONSTRAINTS as $constraint) {
            $this->assertContains($constraint, $checks, "The CHECK constraint [$constraint] was silently skipped.");
        }

        $uniques = collect(DB::select(
            'SELECT DISTINCT INDEX_NAME AS i FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0'
        ))->pluck('i')->map(static fn (mixed $i): string => (string) $i)->all();

        foreach (self::UNIQUE_GUARDS as $index) {
            $this->assertContains($index, $uniques, "The unique guard [$index] is missing.");
        }
    }

    private function assertTheSeededHomePageRenders(): void
    {
        $this->assertSame(6, DB::table('website_sections')->where('status', 'published')->where('is_enabled', true)->whereNull('deleted_at')->count());

        $response = $this->get('/');
        $response->assertOk();

        $hero = $this->heroSection();
        $heading = (string) (json_decode((string) DB::table('website_sections')->where('id', $hero->getKey())->value('published_content'), true)['fields']['heading'] ?? '');
        $this->assertNotSame('', $heading);
        $response->assertSee($heading);

        $this->get('/privacy-policy')->assertOk();
    }

    private function assertReseedingChangesNothing(): void
    {
        $sections = app(SectionService::class);
        $publisher = app(ContentPublisher::class);

        $hero = $sections->saveDraft($this->heroSection(), ['heading' => 'FT50 Edited Heading']);
        $publisher->publish($hero);

        $before = $this->rowCounts();

        $this->seed(WebsiteCmsSeeder::class);
        $this->seed(WebsiteCmsSeeder::class);

        $this->assertSame($before, $this->rowCounts(), 'Re-running WebsiteCmsSeeder must not insert or remove a row.');

        $row = DB::table('website_sections')->where('id', $this->heroSection()->getKey())->first();
        $this->assertSame('FT50 Edited Heading', json_decode((string) $row->content, true)['heading'] ?? null);
        $this->assertSame('FT50 Edited Heading', json_decode((string) $row->published_content, true)['fields']['heading'] ?? null);
        $this->get('/')->assertOk()->assertSee('FT50 Edited Heading');
    }

    private function assertAFreshInstallRollsBackAndMigratesAgain(): void
    {
        $this->assertFalse(app()->configurationIsCached(), 'A cached configuration would ignore the scratch database: run php artisan config:clear.');

        $config = (array) config('database.connections.'.config('database.default'));
        $testDatabase = (string) ($config['database'] ?? '');
        $this->assertStringContainsString('test', $testDatabase, 'FT-50 only ever runs against a test database.');

        $scratch = $testDatabase.'_install';
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s', (string) $config['host'], (string) $config['port']),
            (string) $config['username'],
            (string) ($config['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $pdo->exec('DROP DATABASE IF EXISTS `'.$scratch.'`');
        $pdo->exec('CREATE DATABASE `'.$scratch.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        try {
            // Guard: the child must be connected to the scratch schema before anything destructive runs.
            $probe = $this->artisanInScratch($scratch, ['tinker', '--execute=echo "DB:".Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName();']);
            $this->assertStringContainsString('DB:'.$scratch, $probe, 'The child process is not connected to the scratch database; refusing to continue.');

            $this->artisanInScratch($scratch, ['migrate:fresh', '--seed', '--force']);

            $phaseThreeAndLater = array_values(array_filter(
                $this->migrationNames(),
                static fn (string $name): bool => strcmp($name, self::FIRST_PHASE_THREE_MIGRATION) >= 0,
            ));
            $this->assertGreaterThanOrEqual(10, count($phaseThreeAndLater), 'The ten Phase 3 migrations must be present.');
            $this->assertSame(6, $this->scratchCount($pdo, $scratch, "SELECT COUNT(*) FROM `website_sections` WHERE status = 'published' AND is_enabled = 1"), 'A fresh install seeds the day-one site.');

            $output = $this->artisanInScratch($scratch, ['migrate:rollback', '--step='.count($phaseThreeAndLater), '--force']);

            // Rolled back in reverse order, each one named in the output.
            $positions = [];
            foreach ($phaseThreeAndLater as $name) {
                $position = strpos($output, $name);
                $this->assertNotFalse($position, "The migration [$name] did not roll back.");
                $positions[$name] = $position;
            }
            $expected = array_reverse($phaseThreeAndLater);
            asort($positions);
            $this->assertSame($expected, array_keys($positions), 'Phase 3 migrations must roll back in reverse order.');

            foreach (self::PHASE_THREE_TABLES as $table) {
                $this->assertFalse($this->scratchHasTable($pdo, $scratch, $table), "Rolling back left the table [$table] behind.");
            }
            foreach (['users', 'settings', 'modules', 'permissions'] as $table) {
                $this->assertTrue($this->scratchHasTable($pdo, $scratch, $table), "Rolling back Phase 3 must not touch the earlier table [$table].");
            }

            $this->artisanInScratch($scratch, ['migrate', '--force']);

            foreach (self::PHASE_THREE_TABLES as $table) {
                $this->assertTrue($this->scratchHasTable($pdo, $scratch, $table), "Migrating forward again did not recreate [$table].");
            }
            $this->assertSame(7, $this->scratchCount($pdo, $scratch, sprintf(
                "SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '%s' AND CONSTRAINT_NAME IN ('%s')",
                $scratch,
                implode("','", self::CHECK_CONSTRAINTS),
            )));
        } finally {
            try {
                $pdo->exec('DROP DATABASE IF EXISTS `'.$scratch.'`');
            } catch (Throwable) {
                // Best effort: the next run drops it before creating it again.
            }
        }
    }

    /**
     * Run one `artisan` command against the scratch schema, in a child process.
     *
     * **The timeout is 1800 seconds and it used to be 600.** That is a schema growing past a number
     * somebody picked when it was half the size, not a slow migration: measured standalone on this
     * machine, the exact command this runs — `migrate:fresh --seed --force` with `SEED_DEMO=true`
     * over 159 tables — takes **207 seconds**. Inside the test it exceeds 600, because the parent
     * PHPUnit process is holding its own connection and its own `RefreshDatabase` transaction while
     * the child drops and rebuilds every table, and InnoDB's metadata locking makes DDL under a
     * concurrent transaction far slower than DDL alone.
     *
     * Raised rather than worked around, because the alternative is worse in both directions: a
     * timeout that fires on a healthy migration teaches everybody to re-run the suite and ignore it,
     * and serialising the child against the parent would stop the test exercising the thing it
     * exists to exercise. If this needs raising again, measure the standalone time first — if *that*
     * has grown past a couple of minutes, the migrations are the problem and the number is not.
     *
     * @param  list<string>  $arguments
     */
    private function artisanInScratch(string $scratch, array $arguments): string
    {
        $result = Process::path(base_path())
            ->timeout(1800)
            ->env([
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => (string) config('database.default'),
                'DB_DATABASE' => $scratch,
                'DB_URL' => '',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'array',
                'SEED_DEMO' => 'true',
                'BCRYPT_ROUNDS' => '4',
            ])
            ->run(array_merge([PHP_BINARY, 'artisan'], $arguments, ['--no-ansi', '--no-interaction']));

        $this->assertTrue(
            $result->successful(),
            sprintf("artisan %s failed (exit %s):\n%s\n%s", implode(' ', $arguments), (string) $result->exitCode(), $result->output(), $result->errorOutput()),
        );

        return $result->output();
    }

    /**
     * @return list<string>
     */
    private function migrationNames(): array
    {
        $names = array_map(
            static fn (string $path): string => basename($path, '.php'),
            glob(database_path('migrations/*.php')) ?: [],
        );
        sort($names);

        return $names;
    }

    private function scratchHasTable(PDO $pdo, string $scratch, string $table): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $statement->execute([$scratch, $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function scratchCount(PDO $pdo, string $scratch, string $sql): int
    {
        $pdo->exec('USE `'.$scratch.'`');

        return (int) $pdo->query($sql)->fetchColumn();
    }

    private function heroSection(): WebsiteSection
    {
        /** @var WebsiteSection */
        return WebsiteSection::query()
            ->where('section_key', 'hero')
            ->where('placement', SectionPlacement::Home->value)
            ->whereNull('page_id')
            ->orderBy('id')
            ->firstOrFail();
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (self::SEEDED_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
