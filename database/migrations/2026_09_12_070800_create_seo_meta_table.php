<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 · §2.12 — seo_meta.
 *
 * The one SEO store for every public target (decision D23, §105): no phase adds SEO
 * columns to its own table. Two addressing forms in one table — a model
 * (`seoable_type` / `seoable_id`) or a named route (`route_key`) for pages that have no
 * row of their own — kept mutually exclusive by CHECK chk_seo_target.
 *
 * Append-only category (snapshot attribute) → **no `deleted_at`**, per decision D19
 * (CLAUDE.md §3) and §2.14 / §12.2 Q1. Its history lives in `activity_log`, so the
 * model carries a `deleting` guard instead. It does keep blameable.
 *
 * Created after media_assets (og_image_media_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seo_meta')) {
            Schema::create('seo_meta', function (Blueprint $table): void {
                $table->id();
                // The morph pair is written out rather than declared with
                // nullableMorphs() so that uq_seo_target below is the only index on the
                // pair: nullableMorphs() would add a second, redundant, non-unique index
                // on exactly the same two columns.
                $table->string('seoable_type', 255)->nullable();
                $table->unsignedBigInteger('seoable_id')->nullable();
                $table->string('route_key', 100)->nullable();
                $table->string('title', 180)->nullable();
                $table->string('meta_description', 320)->nullable();
                $table->string('meta_keywords', 500)->nullable();
                $table->string('canonical_url', 500)->nullable();
                $table->string('robots', 24)->default('index_follow');
                $table->string('og_title', 180)->nullable();
                $table->string('og_description', 320)->nullable();
                $table->foreignId('og_image_media_id')->nullable()
                    ->constrained('media_assets')->nullOnDelete();
                $table->string('og_type', 32)->default('website');
                $table->boolean('sitemap_include')->default(true);
                // decimal, never float — the default is passed as a string so no float
                // literal appears in PHP (CLAUDE.md §1.4).
                $table->decimal('sitemap_priority', 2, 1)->default('0.5');
                $table->string('sitemap_changefreq', 16)->default('weekly');
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamps();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.12 Keys. MariaDB treats NULLs as distinct, so uq_seo_target does not
                // collide across the many route-keyed rows and uq_seo_route does not
                // collide across the many model-keyed rows.
                $table->unique(['seoable_type', 'seoable_id'], 'uq_seo_target');
                $table->unique('route_key', 'uq_seo_route');
                $table->index(['robots', 'sitemap_include']);
            });
        }

        // §2.12 CHECK constraints.
        $this->addCheck(
            'seo_meta',
            'chk_seo_priority',
            '`sitemap_priority` >= 0.0 and `sitemap_priority` <= 1.0'
        );

        $this->addCheck(
            'seo_meta',
            'chk_seo_target',
            '(`seoable_id` is not null and `route_key` is null)'
            .' or (`seoable_id` is null and `route_key` is not null)'
        );
    }

    public function down(): void
    {
        // DROP TABLE removes the three foreign keys this table owns
        // (og_image_media_id, created_by, updated_by) and both named CHECK constraints
        // in one statement. Nothing references seo_meta.
        Schema::dropIfExists('seo_meta');
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
