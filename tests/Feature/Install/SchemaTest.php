<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-01 §1 and §10 "Install": the schema the rest of the phase is built on.
 *
 * Reaching this test at all already proves the forward half of the install row — RefreshDatabase
 * runs `migrate:fresh` with the seeders once per test process, so a migration that does not apply
 * cleanly fails the whole suite before a single assertion runs. What is left to assert is that the
 * tables carry the columns, casts-backing types, indexes and blameable/soft-delete bookkeeping the
 * contract fixes, and that every Phase-1 migration is reversible.
 *
 * Everything here is read-only: no table is dropped or rolled back, so the shared fixture the rest
 * of the suite depends on is never disturbed.
 */
final class SchemaTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /**
     * Every table Phase 1 owns or extends.
     *
     * @var list<string>
     */
    private const TABLES = [
        'users',
        'sessions',
        'password_reset_tokens',
        'branches',
        'modules',
        'settings',
        'login_histories',
        'roles',
        'permissions',
        'model_has_roles',
        'model_has_permissions',
        'role_has_permissions',
        'activity_log',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    #[Test]
    public function every_phase_one_table_exists(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), sprintf('Table %s is missing.', $table));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Columns (phase-01 §1.1 – §1.7)
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function columnProvider(): array
    {
        return [
            'users (§1.1)' => ['users', [
                'phone', 'whatsapp', 'avatar_path', 'status', 'status_reason', 'status_changed_at',
                'theme', 'locale', 'timezone', 'last_login_at', 'last_login_ip', 'password_changed_at',
                'must_change_password', 'branch_id', 'created_by', 'updated_by', 'deleted_at',
            ]],
            'permissions (§1.2)' => ['permissions', [
                'module', 'ability', 'group', 'label', 'description', 'sort_order',
            ]],
            'roles (§1.2)' => ['roles', [
                'label', 'description', 'panel', 'level', 'is_system', 'is_default',
                'created_by', 'updated_by',
            ]],
            'modules (§1.3)' => ['modules', [
                'slug', 'name', 'description', 'icon', 'group', 'is_enabled', 'is_core',
                'sort_order', 'settings', 'created_at', 'updated_at',
            ]],
            'settings (§1.4)' => ['settings', [
                'group', 'key', 'value', 'type', 'options', 'is_encrypted', 'is_public',
                'label', 'description', 'sort_order',
            ]],
            'branches (§1.5)' => ['branches', [
                'code', 'name', 'phone', 'email', 'city', 'address', 'is_default', 'is_active',
                'sort_order', 'deleted_at', 'created_by', 'updated_by',
            ]],
            'login_histories (§1.6)' => ['login_histories', [
                'user_id', 'email', 'status', 'ip_address', 'user_agent', 'device', 'platform',
                'browser', 'session_id', 'logged_in_at', 'logged_out_at', 'created_at', 'updated_at',
            ]],
            'activity_log (§1.7)' => ['activity_log', [
                'ip_address', 'user_agent', 'device', 'module', 'reason',
            ]],
        ];
    }

    /**
     * @param  array<int, string>  $columns
     */
    #[Test]
    #[DataProvider('columnProvider')]
    public function the_contracted_columns_exist(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                sprintf('%s.%s is missing.', $table, $column)
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Soft deletes and blameable (CLAUDE.md §3)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_business_tables_carry_soft_deletes_and_blameable_columns(): void
    {
        foreach (['users', 'branches'] as $table) {
            foreach (['deleted_at', 'created_by', 'updated_by'] as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    sprintf('%s.%s is missing.', $table, $column)
                );
            }
        }
    }

    /**
     * phase-01 §1: these tables deliberately do **not** soft delete. A `deleted_at` appearing on
     * `activity_log` or `login_histories` would mean an audit row could be hidden.
     */
    #[Test]
    public function the_append_only_and_lookup_tables_do_not_soft_delete(): void
    {
        foreach (['roles', 'permissions', 'modules', 'settings', 'login_histories', 'activity_log'] as $table) {
            $this->assertFalse(
                Schema::hasColumn($table, 'deleted_at'),
                sprintf('%s must not be soft-deletable (phase-01 §1).', $table)
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Indexes
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function uniqueIndexProvider(): array
    {
        return [
            'settings (group, key)' => ['settings', ['group', 'key']],
            'modules.slug' => ['modules', ['slug']],
            'branches.code' => ['branches', ['code']],
            'users.email' => ['users', ['email']],
        ];
    }

    /**
     * @param  array<int, string>  $columns
     */
    #[Test]
    #[DataProvider('uniqueIndexProvider')]
    public function the_contracted_unique_indexes_exist(string $table, array $columns): void
    {
        $this->assertTrue(
            $this->hasIndex($table, $columns, unique: true),
            sprintf('%s needs a unique index on (%s).', $table, implode(', ', $columns))
        );
    }

    #[Test]
    public function login_histories_carries_the_composite_lookup_index(): void
    {
        // phase-01 §1.6: composite index (user_id, created_at) — the login-history screen's
        // "this user, newest first" query.
        $this->assertTrue($this->hasIndex('login_histories', ['user_id', 'created_at']));
        $this->assertTrue($this->hasIndex('login_histories', ['status']));
        $this->assertTrue($this->hasIndex('login_histories', ['session_id']));
    }

    #[Test]
    public function the_filtered_columns_are_indexed(): void
    {
        $this->assertTrue($this->hasIndex('users', ['status']), 'users.status is filtered on every list.');
        $this->assertTrue($this->hasIndex('users', ['branch_id']));
        $this->assertTrue($this->hasIndex('modules', ['is_enabled']), 'The module gate reads this on every request.');
        $this->assertTrue($this->hasIndex('modules', ['group']));
        $this->assertTrue($this->hasIndex('permissions', ['module']));
        $this->assertTrue($this->hasIndex('roles', ['panel']));
        $this->assertTrue($this->hasIndex('activity_log', ['module']));
    }

    /*
    |--------------------------------------------------------------------------
    | Engine and charset
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_phase_one_table_is_innodb_and_utf8mb4(): void
    {
        $rows = DB::select(
            'select table_name as name, engine, table_collation as collation
             from information_schema.tables
             where table_schema = database()'
        );

        $byTable = [];

        foreach ($rows as $row) {
            $byTable[strtolower((string) $row->name)] = $row;
        }

        foreach (self::TABLES as $table) {
            $this->assertArrayHasKey($table, $byTable, $table.' is missing.');

            $this->assertSame(
                'InnoDB',
                (string) $byTable[$table]->engine,
                sprintf('%s must be InnoDB (foreign keys and transactions).', $table)
            );

            $this->assertStringStartsWith(
                'utf8mb4',
                (string) $byTable[$table]->collation,
                sprintf('%s must be utf8mb4.', $table)
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Foreign keys
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_nullable_owner_keys_null_out_rather_than_cascade(): void
    {
        // Deleting a user must never take their audit trail or the rows they created with them
        // (phase-01 §1.1: nullable FK, nullOnDelete).
        $expected = [
            ['users', 'branch_id', 'branches'],
            ['users', 'created_by', 'users'],
            ['users', 'updated_by', 'users'],
            ['branches', 'created_by', 'users'],
            ['branches', 'updated_by', 'users'],
            ['login_histories', 'user_id', 'users'],
        ];

        foreach ($expected as [$table, $column, $references]) {
            $constraint = $this->foreignKey($table, $column);

            $this->assertNotNull($constraint, sprintf('%s.%s has no foreign key.', $table, $column));
            $this->assertSame($references, strtolower((string) $constraint->referenced_table));
            $this->assertSame(
                'SET NULL',
                strtoupper((string) $constraint->delete_rule),
                sprintf('%s.%s must be nullOnDelete.', $table, $column)
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Reversibility (phase-01 §10 "Install", CLAUDE.md §8.1)
    |--------------------------------------------------------------------------
    */

    /**
     * Every migration has to be reversible (CLAUDE.md §8.1). The rollback itself is deliberately
     * not executed here — that would drop the schema the rest of the suite shares — so this asserts
     * the structural requirement instead: every migration file declares a `down()` whose body
     * actually reverses something, rather than the empty stub the generator leaves behind.
     *
     * The files are read as text rather than `require`d, because the published vendor migrations
     * declare named classes that the migrator has already loaded in this process.
     *
     * "Reverses something" means a schema operation (`Schema::create/table/drop…`, `->drop…`) or, for
     * a data migration (D61's timestamp re-base, the settings supersede/unpublish migrations), a write
     * (`->update(`, `->insert(`, `->upsert(`, `->statement(`). A `Schema::has…()` guard on its own is
     * NOT reversal. Private helpers `down()` calls through `$this->…()` are inspected too, so a
     * migration whose `up()` and `down()` share one helper is judged on what that helper does.
     */
    #[Test]
    public function every_phase_one_migration_declares_a_reversing_down_method(): void
    {
        $files = glob(database_path('migrations/*.php')) ?: [];

        $this->assertNotEmpty($files);

        $checked = 0;

        foreach ($files as $file) {
            $name = basename((string) $file);
            $source = (string) file_get_contents((string) $file);

            $this->assertStringContainsString(
                'function down(',
                $source,
                sprintf('%s has no down() method, so it cannot be rolled back.', $name)
            );

            // An assertion migration alters nothing, so there is nothing for `down()` to undo and an
            // empty body is the honest one. The exception is **derived from the file rather than
            // from a list**: a migration only qualifies if its own `up()` performs no schema
            // operation and no write, which a migration that actually changes something can never
            // claim. See {@see self::altersNothing()}.
            if ($this->altersNothing($source)) {
                $checked++;

                continue;
            }

            $this->assertNotSame(
                '',
                $this->downBody($source),
                sprintf('%s::down() is empty — the migration is not reversible.', $name)
            );

            $this->assertTrue(
                $this->downReverses($source),
                sprintf('%s::down() does not reverse anything.', $name)
            );

            $checked++;
        }

        $this->assertGreaterThanOrEqual(14, $checked, 'Every Phase-1 migration must be inspected.');
    }

    /**
     * The reversibility rule itself: it must reject a stub, a guard-only body and a helper that only
     * reads, and accept a schema reversal, a data write, and a write reached through a helper.
     */
    #[Test]
    #[DataProvider('downBodies')]
    public function the_reversibility_rule_tells_a_real_down_from_a_stub(string $source, bool $reverses): void
    {
        $this->assertSame($reverses, $this->downReverses($source));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function downBodies(): array
    {
        return [
            'comment only' => ['<?php class M { public function down(): void { // nothing } }', false],
            'guard only' => ["<?php class M { public function down(): void { if (! Schema::hasTable('t')) { return; } } }", false],
            'helper that only reads' => ["<?php class M { public function down(): void { \$this->probe(); } private function probe(): void { DB::table('t')->count(); } }", false],
            'drop table' => ["<?php class M { public function down(): void { Schema::dropIfExists('t'); } }", true],
            'alter table' => ["<?php class M { public function down(): void { Schema::table('t', function (\$table) { \$table->dropColumn('c'); }); } }", true],
            'data write' => ["<?php class M { public function down(): void { DB::table('t')->where('a', 1)->update(['b' => 0]); } }", true],
            'write through a helper' => ["<?php class M { public function down(): void { \$this->rebase(false); } private function rebase(bool \$f): void { \$this->convert(); } private function convert(): void { \$c->update('update t set a = 1'); } }", true],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Does an index covering exactly these columns, in this order, exist?
     *
     * @param  array<int, string>  $columns
     */
    private function hasIndex(string $table, array $columns, bool $unique = false): bool
    {
        $rows = DB::select(
            'select index_name as name, seq_in_index as position, column_name as column_name, non_unique
             from information_schema.statistics
             where table_schema = database() and table_name = ?
             order by index_name, seq_in_index',
            [$table]
        );

        $indexes = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;
            $indexes[$name]['columns'][(int) $row->position] = strtolower((string) $row->column_name);
            $indexes[$name]['unique'] = (int) $row->non_unique === 0;
        }

        $wanted = array_map('strtolower', $columns);

        foreach ($indexes as $index) {
            ksort($index['columns']);

            if (array_values($index['columns']) !== $wanted) {
                continue;
            }

            if (! $unique || $index['unique']) {
                return true;
            }
        }

        return false;
    }

    private function foreignKey(string $table, string $column): ?object
    {
        $rows = DB::select(
            'select kcu.referenced_table_name as referenced_table, rc.delete_rule as delete_rule
             from information_schema.key_column_usage kcu
             join information_schema.referential_constraints rc
               on rc.constraint_name = kcu.constraint_name
              and rc.constraint_schema = kcu.table_schema
             where kcu.table_schema = database()
               and kcu.table_name = ?
               and kcu.column_name = ?
               and kcu.referenced_table_name is not null',
            [$table, $column]
        );

        return $rows[0] ?? null;
    }

    /**
     * The body of `down()`, with comments and whitespace stripped — so a `down()` that holds only
     * an explanatory comment still reads as empty.
     */
    private function downBody(string $source): string
    {
        return $this->methodBody($source, 'down');
    }

    /**
     * Does `down()` — together with every `$this->helper()` it reaches — perform a schema reversal or
     * a data write? A `Schema::has…()` guard alone does not count.
     */
    /**
     * Does this migration's `up()` change nothing at all?
     *
     * Phase 23's `assert_attachment_morphs_for_support_tables` is the first of these: it exists to
     * fail loudly on every deployment if §96's one `attachments` table has lost its `visibility`
     * column, and it creates, alters and drops nothing. A test run catches that when somebody runs
     * the suite; a migration catches it on the deployment where a hotfix reordered things.
     *
     * The same walk `downReverses()` uses, pointed at `up()` instead — so a migration that reaches
     * a schema call or a write **through a private helper** is not mistaken for one that alters
     * nothing. `Schema::has…()` guards and `throw` do not count as alteration, which is exactly
     * what an assertion migration is made of.
     */
    private function altersNothing(string $source): bool
    {
        $seen = ['up' => true];
        $queue = ['up'];
        $reached = [];

        while ($queue !== []) {
            $body = $this->methodBody($source, (string) array_shift($queue));
            $reached[] = $body;

            preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $body, $calls);

            foreach ($calls[1] as $call) {
                if (! isset($seen[$call]) && $this->methodBody($source, $call) !== '') {
                    $seen[$call] = true;
                    $queue[] = $call;
                }
            }
        }

        return preg_match(
            '/Schema::(?!has)|->drop|dropIfExists|->(?:update|insert|upsert|statement)\s*\('
            .'|DB::(?:statement|unprepared)\s*\(|RawSchema::(?!.*Exists)/i',
            implode(' ', $reached)
        ) !== 1;
    }

    private function downReverses(string $source): bool
    {
        if ($this->downBody($source) === '') {
            return false;
        }

        $seen = ['down' => true];
        $queue = ['down'];
        $reached = [];

        while ($queue !== []) {
            $body = $this->methodBody($source, (string) array_shift($queue));
            $reached[] = $body;

            preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $body, $calls);

            foreach ($calls[1] as $call) {
                if (! isset($seen[$call]) && $this->methodBody($source, $call) !== '') {
                    $seen[$call] = true;
                    $queue[] = $call;
                }
            }
        }

        // `DB::statement()` / `DB::unprepared()` count too: a migration whose up() writes raw SQL —
        // generated columns, CHECK constraints, triggers — reverses itself the same way, and phase-07's
        // step-18 migration is the first in the project to do so. Without them the check would demand a
        // schema-builder call that cannot express what was created in the first place.
        return preg_match(
            '/Schema::(?!has)|->drop|dropIfExists|->(?:update|insert|upsert|statement)\s*\('
            .'|DB::(?:statement|unprepared)\s*\(/i',
            implode(' ', $reached)
        ) === 1;
    }

    /**
     * The body of the named method, with comments and whitespace stripped.
     */
    private function methodBody(string $source, string $method): string
    {
        if (preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        $open = strpos($source, '{', $match[0][1]);

        if ($open === false) {
            return '';
        }

        $depth = 0;
        $body = '';

        for ($index = $open, $length = strlen($source); $index < $length; $index++) {
            $character = $source[$index];

            if ($character === '{') {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            }

            if ($character === '}') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            }

            $body .= $character;
        }

        // Drop comments, then collapse whitespace.
        $body = (string) preg_replace('#/\*.*?\*/#s', '', $body);
        $body = (string) preg_replace('#//[^\n]*#', '', $body);

        return trim((string) preg_replace('/\s+/', ' ', $body));
    }
}
