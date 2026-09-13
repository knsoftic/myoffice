<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Phase2;

use App\Models\Module;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-02 §6 "Module data safety": row counts for a disabled module's tables are identical before
 * and after disable + re-enable.
 *
 * Two nets, because "the module's tables" grows with every phase:
 *
 *  1. **Every table in the database** is counted before and after (bar the two a toggle is meant
 *     to write to: `activity_log`, and `sessions` for the HTTP round trips). A later phase's table
 *     is covered the moment its migration exists, with no edit here.
 *  2. **The rows a module owns today are fingerprinted**, not just counted — its permissions, every
 *     role and user grant on them, its own `modules` row apart from the four switch columns, and
 *     content rows in a stand-in content store the test defines itself (no Phase 3 table is ever
 *     read or written here). A toggle that rewrote a row without changing the count would slip
 *     past a count; it cannot slip past a hash.
 */
final class ModuleDataSafetyTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** Tables a toggle legitimately writes to, or that the HTTP test harness itself touches. */
    private const VOLATILE = ['activity_log', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    #[Test]
    public function disabling_and_re_enabling_a_cascade_of_modules_leaves_every_table_exactly_as_it_was(): void
    {
        $admin = $this->createSuperAdmin();
        $this->seedModuleOwnedRows($admin, ['clients', 'projects', 'invoices', 'tasks']);

        $counts = $this->rowCounts();
        $fingerprints = $this->fingerprints(['clients', 'projects', 'invoices', 'tasks', 'time_tracking', 'project_milestones', 'payments']);

        $clients = Module::query()->where('slug', 'clients')->firstOrFail();

        $this->actingAs($admin)
            ->post('/admin/modules/'.$clients->getKey().'/toggle', ['enabled' => false, 'reason' => 'Data safety drill', 'cascade' => true])
            ->assertRedirect();

        $this->assertFalse((bool) $clients->fresh()->is_enabled, 'The drill must actually have disabled the module.');
        $this->assertSame($counts, $this->rowCounts(), 'Disabling changed a row count.');

        foreach (['clients', 'projects', 'invoices', 'tasks', 'time_tracking', 'project_milestones', 'payments'] as $slug) {
            $this->actingAs($admin)
                ->post('/admin/modules/'.Module::query()->where('slug', $slug)->value('id').'/toggle', ['enabled' => true])
                ->assertRedirect();
        }

        $this->assertTrue((bool) $clients->fresh()->is_enabled);
        $this->assertSame($counts, $this->rowCounts(), 'Disable + re-enable changed a row count.');
        $this->assertSame($fingerprints, $this->fingerprints(['clients', 'projects', 'invoices', 'tasks', 'time_tracking', 'project_milestones', 'payments']), 'A module-owned row was rewritten.');
    }

    #[Test]
    public function a_cms_module_keeps_its_content_rows_through_disable_and_re_enable(): void
    {
        $admin = $this->createSuperAdmin();

        // The content comes from a stand-in store this test defines, never from Phase 3's schema:
        // a Phase 2 test has to pass, not skip, on a tree that carries no CMS code at all, and must
        // not turn red when Phase 3 changes a column or adds a trigger to its own tables.
        $tables = $this->createStandInCmsContent($admin);

        try {
            $counts = $this->rowCounts();
            $content = $this->tableHashes($tables);
            $fingerprints = $this->fingerprints(['menus', 'pages']);

            $this->assertCount(3, $tables);
            $this->assertNotContains(md5('[]'), $content, 'Every stand-in table must hold rows, or a hash proves nothing.');

            foreach (['menus', 'pages'] as $slug) {
                $module = Module::query()->where('slug', $slug)->firstOrFail();

                $this->actingAs($admin)
                    ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false, 'reason' => 'Website freeze'])
                    ->assertRedirect();

                $this->assertFalse((bool) $module->fresh()->is_enabled, $slug.' must really be off.');
                $this->assertSame($content, $this->tableHashes($tables), 'Disabling '.$slug.' touched its content.');

                $this->actingAs($admin)
                    ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => true])
                    ->assertRedirect();
            }

            $this->assertSame($counts, $this->rowCounts());
            $this->assertSame($content, $this->tableHashes($tables));
            $this->assertSame($fingerprints, $this->fingerprints(['menus', 'pages']));
        } finally {
            foreach (array_reverse($tables) as $table) {
                // TEMPORARY again: a plain DROP TABLE would implicitly commit RefreshDatabase's transaction.
                DB::statement('DROP TEMPORARY TABLE IF EXISTS `'.$table.'`');
            }
        }
    }

    #[Test]
    public function a_bulk_disable_of_a_whole_group_deletes_nothing(): void
    {
        $admin = $this->createSuperAdmin();
        $this->seedModuleOwnedRows($admin, ['students', 'courses', 'batches', 'student_fees']);

        $counts = $this->rowCounts();
        $slugs = Module::query()->where('group', 'institute')->pluck('slug')->all();
        $fingerprints = $this->fingerprints($slugs);

        $this->actingAs($admin)
            ->post('/admin/modules/bulk-toggle', ['group' => 'institute', 'enabled' => false, 'reason' => 'Term break'])
            ->assertRedirect();

        $this->assertSame(0, Module::query()->where('group', 'institute')->where('is_core', false)->where('is_enabled', true)->count(), 'The drill must actually have disabled the group.');

        $this->actingAs($admin)
            ->post('/admin/modules/bulk-toggle', ['group' => 'institute', 'enabled' => true])
            ->assertRedirect();

        $this->assertSame($counts, $this->rowCounts());
        $this->assertSame($fingerprints, $this->fingerprints($slugs));
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Give each module something to lose: a role that holds all of its permissions, a user who
     * holds two of them directly, and a `modules.settings` document.
     *
     * @param  list<string>  $slugs
     */
    private function seedModuleOwnedRows(User $admin, array $slugs): void
    {
        foreach ($slugs as $slug) {
            $names = Permission::query()->where('module', $slug)->pluck('name')->all();

            $this->assertNotSame([], $names, $slug.' should have seeded permissions.');

            $this->createUserWithPermissions($names);
            $this->grantPermissions(User::factory()->create(), ...array_slice($names, 0, 2));

            DB::table('modules')->where('slug', $slug)->update(['settings' => json_encode(['board_columns' => ['todo', 'doing', 'done'], 'owner' => $admin->getKey()])]);
        }
    }

    /**
     * A stand-in for the content the `pages` and `menus` modules own, defined by this test.
     *
     * Session-local TEMPORARY tables with only inline columns (no separate index statement):
     * `CREATE TEMPORARY TABLE` does not implicitly commit, so RefreshDatabase's wrapping transaction
     * survives, and the tables vanish with the connection. They carry the shape a content store
     * has — a blameable page, a menu and its items — and names no real table, so nothing Phase 3
     * builds can collide with or change them. SHOW FULL TABLES does not list them, so rowCounts()
     * still measures exactly the real schema.
     *
     * @return list<string> the stand-in tables, each holding rows
     */
    private function createStandInCmsContent(User $admin): array
    {
        $pages = 'phase2_standin_cms_pages';
        $menus = 'phase2_standin_cms_menus';
        $items = 'phase2_standin_cms_menu_items';

        DB::statement('CREATE TEMPORARY TABLE `'.$pages.'` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(191) NOT NULL,
            `slug` VARCHAR(191) NOT NULL,
            `body` TEXT NULL,
            `created_by` BIGINT UNSIGNED NULL,
            `created_at` TIMESTAMP NULL,
            `updated_at` TIMESTAMP NULL
        )');

        DB::statement('CREATE TEMPORARY TABLE `'.$menus.'` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(191) NOT NULL,
            `location` VARCHAR(64) NOT NULL,
            `created_at` TIMESTAMP NULL,
            `updated_at` TIMESTAMP NULL
        )');

        DB::statement('CREATE TEMPORARY TABLE `'.$items.'` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `menu_id` BIGINT UNSIGNED NOT NULL,
            `label` VARCHAR(191) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NULL,
            `updated_at` TIMESTAMP NULL
        )');

        foreach (['about-us', 'admissions-policy', 'refund-policy'] as $slug) {
            DB::table($pages)->insert([
                'title' => ucwords(str_replace('-', ' ', $slug)),
                'slug' => $slug,
                'body' => '<p>'.ucwords(str_replace('-', ' ', $slug)).' content.</p>',
                'created_by' => $admin->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $menu = DB::table($menus)->insertGetId(['name' => 'Header', 'location' => 'header', 'created_at' => now(), 'updated_at' => now()]);

        foreach (['Home', 'Courses', 'Contact'] as $position => $label) {
            DB::table($items)->insert(['menu_id' => $menu, 'label' => $label, 'sort_order' => $position, 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$pages, $menus, $items];
    }

    /*
    |--------------------------------------------------------------------------
    | Measurements
    |--------------------------------------------------------------------------
    */

    /**
     * table => row count, for every table in the schema.
     *
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $counts = [];

        // SHOW TABLES, not Schema::getTableListing(): the latter lists every schema on the server.
        foreach (DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
            $table = (string) array_values((array) $row)[0];

            if (in_array($table, self::VOLATILE, true)) {
                continue;
            }

            $counts[$table] = DB::table($table)->count();
        }

        ksort($counts);

        $this->assertArrayHasKey('permissions', $counts, 'The table listing is not reading this schema.');

        return $counts;
    }

    /**
     * A hash of everything a module owns apart from its switch.
     *
     * @param  list<string>  $slugs
     * @return array<string, string>
     */
    private function fingerprints(array $slugs): array
    {
        $prints = [];

        foreach ($slugs as $slug) {
            $permissions = DB::table('permissions')->where('module', $slug)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $ids = array_column($permissions, 'id');

            $prints[$slug] = md5((string) json_encode([
                'permissions' => $permissions,
                'role_grants' => DB::table('role_has_permissions')->whereIn('permission_id', $ids)->orderBy('role_id')->orderBy('permission_id')->get(),
                'user_grants' => DB::table('model_has_permissions')->whereIn('permission_id', $ids)->orderBy('model_id')->orderBy('permission_id')->get(),
                'module' => DB::table('modules')
                    ->where('slug', $slug)
                    ->first(['id', 'slug', 'name', 'description', 'icon', 'group', 'is_core', 'sort_order', 'settings', 'depends_on']),
            ]));
        }

        return $prints;
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, string>
     */
    private function tableHashes(array $tables): array
    {
        $hashes = [];

        foreach ($tables as $table) {
            $hashes[$table] = md5((string) DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $hashes;
    }
}
