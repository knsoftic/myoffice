<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 · §2.5 and §2.6 — menus and menu_items.
 *
 * One navigation container per layout slot, and its at-most-two-level item tree (§102).
 * INV-6 (max depth 2) is a database fact, not only a form rule: CHECK chk_mi_depth and
 * CHECK chk_mi_parent enforce it.
 *
 * Created after `pages` (menu_items.page_id) and before `website_sections`
 * (website_sections.menu_id).
 *
 * Both are mutable content tables → timestamps + softDeletes + blameable (CLAUDE.md §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menus')) {
            Schema::create('menus', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->string('slug', 64);
                $table->string('location', 32);
                $table->string('description', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.5 Keys. One menu per slot ([D-W3-4]) — which is why the footer has
                // three separate locations rather than one menu with columns.
                $table->unique('slug', 'uq_menus_slug');
                $table->unique('location', 'uq_menus_location');
            });
        }

        if (! Schema::hasTable('menu_items')) {
            Schema::create('menu_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
                $table->string('label', 100);
                $table->string('link_type', 24)->default('url');
                $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();
                $table->string('route_name', 100)->nullable();
                $table->json('route_params')->nullable();
                $table->string('url', 500)->nullable();
                $table->string('anchor', 64)->nullable();
                // The hook later phases use for course / service / blog-category links.
                // Phase 3 writes nothing here. nullableMorphs() also supplies §2.6's
                // INDEX (linkable_type, linkable_id).
                $table->nullableMorphs('linkable');
                $table->string('icon', 64)->nullable();
                $table->boolean('open_new_tab')->default(false);
                $table->boolean('rel_nofollow')->default(false);
                $table->string('visibility', 16)->default('all');
                $table->boolean('is_enabled')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedTinyInteger('depth')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.6 Keys. `page_id` is indexed by its own foreign key.
                $table->index(['menu_id', 'parent_id', 'is_enabled', 'sort_order'], 'idx_mi_tree');
            });
        }

        // §2.6 CHECK constraints — INV-6, enforced in the DB and not only in the form.
        $this->addCheck('menu_items', 'chk_mi_depth', '`depth` <= 1');
        $this->addCheck('menu_items', 'chk_mi_parent', '`parent_id` is null or `depth` = 1');
    }

    public function down(): void
    {
        // menu_items owns the foreign keys (its own self-referencing parent_id included),
        // so it is dropped before menus. DROP TABLE removes a table's foreign keys and
        // its named CHECK constraints in one statement; no column has to be dropped
        // separately here because up() created whole tables.
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menus');
    }

    /**
     * Add a named CHECK constraint if MariaDB does not already carry it.
     */
    private function addCheck(string $table, string $name, string $expression): void
    {
        if (! Schema::hasTable($table) || $this->hasCheck($table, $name)) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)',
            $table,
            $name,
            $expression
        ));
    }

    private function hasCheck(string $table, string $name): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM information_schema.CHECK_CONSTRAINTS'
            .' WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [DB::getDatabaseName(), $table, $name]
        );

        return $rows !== [];
    }
};
