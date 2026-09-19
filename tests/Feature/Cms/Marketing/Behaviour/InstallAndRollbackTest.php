<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;
use Throwable;

/**
 * phase-04 §11 test 1 — install and rollback, and the deferred foreign keys of §2.1.
 *
 * In process, against the suite's own `migrate:fresh --seed` fixture:
 *
 *   · the 20 Phase 4 tables and the four named unique guards exist;
 *   · each of the 14 deferred columns is an indexed unsignedBigInteger and carries **no** foreign key while its
 *     target table does not exist yet — and every foreign key a Phase 4 table does carry points at a table that
 *     exists; the Laravel queue table `jobs` is untouched.
 *
 * DDL auto-commits on MariaDB, so the install / rollback half runs in a child `artisan` process against a
 * **scratch** schema derived from the test database's name, created and dropped here over a separate PDO
 * connection. The child is first asked which database it is connected to, and nothing destructive runs unless
 * the answer is the scratch schema.
 */
final class InstallAndRollbackTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** The first Phase 4 migration: this one and every later migration roll back in reverse. */
    private const FIRST_PHASE_FOUR_MIGRATION = '2026_09_12_080100_create_service_categories_table';

    /** @var list<string> */
    private const PHASE_FOUR_TABLES = [
        'service_categories', 'technologies', 'services', 'service_technology', 'portfolio_categories',
        'portfolio_items', 'portfolio_item_technology', 'portfolio_item_media', 'team_members', 'testimonials',
        'student_reviews', 'success_stories', 'blog_categories', 'blog_tags', 'blog_posts', 'blog_post_blog_tag',
        'blog_post_views', 'job_openings', 'job_applications', 'contact_inquiries',
    ];

    /** @var array<string, string> index => table */
    private const UNIQUE_GUARDS = [
        'uq_pim' => 'portfolio_item_media',
        'uq_blog_post_view_daily' => 'blog_post_views',
        'uq_job_application_per_job' => 'job_applications',
        'uq_contact_inquiry_routed_target' => 'contact_inquiries',
    ];

    /** @var list<array{0: string, 1: string, 2: string}> table, column, the later phase's target table (§2.1) */
    private const DEFERRED_COLUMNS = [
        ['portfolio_items', 'client_id', 'clients'],
        ['testimonials', 'client_id', 'clients'],
        ['testimonials', 'student_id', 'students'],
        ['student_reviews', 'student_id', 'students'],
        ['student_reviews', 'course_id', 'courses'],
        ['success_stories', 'student_id', 'students'],
        ['success_stories', 'course_id', 'courses'],
        ['contact_inquiries', 'course_id', 'courses'],
        ['team_members', 'department_id', 'departments'],
        ['job_openings', 'department_id', 'departments'],
        ['team_members', 'employee_id', 'employees'],
        ['job_applications', 'employee_id', 'employees'],
        ['contact_inquiries', 'collaborator_id', 'collaborators'],
        ['contact_inquiries', 'referral_visit_id', 'collaborator_referral_visits'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    public function test_01_schema_guarantees_and_deferred_foreign_keys(): void
    {
        foreach (self::PHASE_FOUR_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), sprintf('The Phase 4 table [%s] is missing.', $table));
        }

        $this->assertTrue(Schema::hasTable('jobs'), 'Laravel\'s queue table `jobs` is never replaced (§2.18 naming warning).');
        $this->assertFalse(Schema::hasColumn('jobs', 'slug'), 'The careers board is job_openings, never the queue table.');

        foreach (self::UNIQUE_GUARDS as $index => $table) {
            $found = DB::select(
                'SELECT DISTINCT INDEX_NAME AS i FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0',
                [$table, $index],
            );
            $this->assertCount(1, $found, sprintf('The unique guard [%s] on [%s] is missing.', $index, $table));
        }

        $this->assertCount(14, self::DEFERRED_COLUMNS, '§2.1 lists fourteen deferred columns.');

        foreach (self::DEFERRED_COLUMNS as [$table, $column, $target]) {
            $this->assertTrue(Schema::hasColumn($table, $column), sprintf('%s.%s is missing.', $table, $column));

            $type = (string) DB::selectOne(
                'SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column],
            )->type;
            $this->assertStringContainsString('bigint', strtolower($type), sprintf('%s.%s is an unsignedBigInteger.', $table, $column));
            $this->assertStringContainsString('unsigned', strtolower($type), sprintf('%s.%s is an unsignedBigInteger.', $table, $column));

            $indexed = DB::select(
                'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1',
                [$table, $column],
            );
            $this->assertNotSame([], $indexed, sprintf('%s.%s must be indexed.', $table, $column));

            if (! Schema::hasTable($target)) {
                $foreignKeys = DB::select(
                    'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                    [$table, $column],
                );
                $this->assertSame([], $foreignKeys, sprintf('%s.%s must not carry a foreign key while [%s] does not exist (§2.1).', $table, $column, $target));
            }
        }

        $placeholders = implode(',', array_fill(0, count(self::PHASE_FOUR_TABLES), '?'));
        $references = DB::select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS r FROM information_schema.KEY_COLUMN_USAGE'
            .' WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME IN ('.$placeholders.')',
            self::PHASE_FOUR_TABLES,
        );

        $this->assertNotSame([], $references, 'Phase 4 tables do carry their real foreign keys (users, media_assets, services …).');

        foreach ($references as $reference) {
            $this->assertTrue(Schema::hasTable((string) $reference->r), sprintf('%s.%s references [%s], which does not exist.', $reference->t, $reference->c, $reference->r));
        }
    }

    public function test_01_every_phase_four_migration_rolls_back_in_reverse_and_migrates_again(): void
    {
        $this->assertFalse(app()->configurationIsCached(), 'A cached configuration would ignore the scratch database: run php artisan config:clear.');

        $config = (array) config('database.connections.'.config('database.default'));
        $testDatabase = (string) ($config['database'] ?? '');
        $this->assertStringContainsString('test', $testDatabase, 'Test 1 only ever runs against a test database.');

        $scratch = $testDatabase.'_install_p4';
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s', (string) $config['host'], (string) $config['port']),
            (string) $config['username'],
            (string) ($config['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $pdo->exec('DROP DATABASE IF EXISTS `'.$scratch.'`');
        $pdo->exec('CREATE DATABASE `'.$scratch.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        try {
            $probe = $this->artisanInScratch($scratch, ['tinker', '--execute=echo "DB:".Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName();']);
            $this->assertStringContainsString('DB:'.$scratch, $probe, 'The child process is not connected to the scratch database; refusing to continue.');

            $this->artisanInScratch($scratch, ['migrate', '--force']);

            foreach (self::PHASE_FOUR_TABLES as $table) {
                $this->assertTrue($this->scratchHasTable($pdo, $scratch, $table), sprintf('migrate did not create [%s].', $table));
            }

            $phaseFourAndLater = array_values(array_filter(
                $this->migrationNames(),
                static fn (string $name): bool => strcmp($name, self::FIRST_PHASE_FOUR_MIGRATION) >= 0,
            ));
            $this->assertGreaterThanOrEqual(15, count($phaseFourAndLater), 'The fifteen Phase 4 migrations must be present.');

            $output = $this->artisanInScratch($scratch, ['migrate:rollback', '--step='.count($phaseFourAndLater), '--force']);

            $positions = [];

            foreach ($phaseFourAndLater as $name) {
                $position = strpos($output, $name);
                $this->assertNotFalse($position, sprintf('The migration [%s] did not roll back.', $name));
                $positions[$name] = $position;
            }

            asort($positions);
            $this->assertSame(array_reverse($phaseFourAndLater), array_keys($positions), 'Phase 4 migrations must roll back in reverse order.');

            foreach (self::PHASE_FOUR_TABLES as $table) {
                $this->assertFalse($this->scratchHasTable($pdo, $scratch, $table), sprintf('Rolling back left the table [%s] behind.', $table));
            }

            foreach (['users', 'settings', 'modules', 'media_assets', 'pages', 'jobs'] as $table) {
                $this->assertTrue($this->scratchHasTable($pdo, $scratch, $table), sprintf('Rolling back Phase 4 must not touch the earlier table [%s].', $table));
            }

            $this->artisanInScratch($scratch, ['migrate', '--force']);

            foreach (self::PHASE_FOUR_TABLES as $table) {
                $this->assertTrue($this->scratchHasTable($pdo, $scratch, $table), sprintf('Migrating forward again did not recreate [%s].', $table));
            }
        } finally {
            try {
                $pdo->exec('DROP DATABASE IF EXISTS `'.$scratch.'`');
            } catch (Throwable) {
                // Best effort: the next run drops it before creating it again.
            }
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function artisanInScratch(string $scratch, array $arguments): string
    {
        $result = Process::path(base_path())
            ->timeout(600)
            ->env([
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => (string) config('database.default'),
                'DB_DATABASE' => $scratch,
                'DB_URL' => '',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'array',
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
}
