<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 · §2.2 and §2.4 — website_sections and the website_section_media pivot.
 *
 * A placed instance of a registry-declared section type, holding the working draft
 * *and* the published snapshot so the public renderer is one indexed read per page
 * with no joins (decision D22, INV-1). `has_unpublished_changes` is a STORED generated
 * column (INV-4). INV-3: every reference that must survive a delete is a real FK or a
 * pivot row — `cta_block_id`, `menu_id` and `website_section_media`, never JSON.
 *
 * Created after pages, cta_blocks, menus and media_assets, which it references.
 *
 * `website_sections` is a mutable content table → timestamps + softDeletes + blameable.
 * `website_section_media` is a pivot → timestamps only, no softDeletes, no blameable (§2.4).
 */
return new class extends Migration
{
    /** INV-4: derived, never set. Identical expression to `pages` (§2.7). */
    private const UNPUBLISHED_EXPRESSION =
        'case when `published_hash` is null or `content_hash` <> `published_hash` then 1 else 0 end';

    public function up(): void
    {
        if (! Schema::hasTable('website_sections')) {
            Schema::create('website_sections', function (Blueprint $table): void {
                $table->id();
                // Never cast to an enum — later phases add section types without a migration.
                $table->string('section_key', 64);
                $table->string('placement', 32)->default('home');
                $table->foreignId('page_id')->nullable()->constrained('pages')->cascadeOnDelete();
                // "{placement}|{page_id or 0}|{section_key}" for registry types marked
                // is_unique, NULL for repeatable types. MariaDB treats NULLs as distinct,
                // so uq_ws_instance below is what makes "one hero per page" a DB fact.
                $table->string('instance_key', 96)->nullable();
                $table->string('name', 150)->nullable();
                $table->string('anchor', 64)->nullable();
                $table->foreignId('cta_block_id')->nullable()->constrained('cta_blocks')->nullOnDelete();
                $table->foreignId('menu_id')->nullable()->constrained('menus')->nullOnDelete();
                $table->json('content')->nullable();
                $table->json('published_content')->nullable();
                $table->char('content_hash', 40)->nullable();
                $table->char('published_hash', 40)->nullable();
                $table->boolean('has_unpublished_changes')->storedAs(self::UNPUBLISHED_EXPRESSION);
                $table->boolean('is_enabled')->default(true);
                $table->string('status', 16)->default('draft');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamp('published_at')->nullable();
                $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('unpublished_reason', 255)->nullable();
                $table->timestamp('draft_updated_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.2 Keys. `cta_block_id` and `menu_id` are indexed by their own
                // foreign keys, which is the INDEX the contract asks for.
                $table->unique('instance_key', 'uq_ws_instance');
                $table->unique(['placement', 'page_id', 'anchor'], 'uq_ws_anchor');
                $table->index(
                    ['placement', 'page_id', 'is_enabled', 'status', 'sort_order'],
                    'idx_ws_public'
                );
                $table->index('section_key');
                $table->index('has_unpublished_changes');
            });
        }

        // §2.2 CHECK chk_ws_page_placement.
        $this->addCheck(
            'website_sections',
            'chk_ws_page_placement',
            "(`placement` = 'page' and `page_id` is not null)"
            ." or (`placement` <> 'page' and `page_id` is null)"
        );

        if (! Schema::hasTable('website_section_media')) {
            Schema::create('website_section_media', function (Blueprint $table): void {
                $table->unsignedBigInteger('website_section_id');
                $table->unsignedBigInteger('media_asset_id');
                // The registry slot name: hero_image, background_image, background_video,
                // video_poster, image_1, image_2, gallery.
                $table->string('role', 32);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                // §2.4 Keys. The composite PK leads with website_section_id, so it also
                // serves that foreign key; InnoDB creates the INDEX (media_asset_id) the
                // contract asks for when the second foreign key is added.
                $table->primary(['website_section_id', 'role', 'media_asset_id']);

                $table->foreign('website_section_id')
                    ->references('id')->on('website_sections')->cascadeOnDelete();
                // restrictOnDelete: an image in use cannot be deleted out from under a section.
                $table->foreign('media_asset_id')
                    ->references('id')->on('media_assets')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        // The pivot owns the foreign keys into website_sections and media_assets, so it
        // goes first; DROP TABLE removes them in one statement. website_sections is then
        // dropped with the six foreign keys it owns and its named CHECK constraint.
        // Inbound foreign keys (website_section_items, faq_website_section) belong to
        // later Phase 3 migrations and are rolled back before this one.
        Schema::dropIfExists('website_section_media');
        Schema::dropIfExists('website_sections');
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
