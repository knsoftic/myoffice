<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 · §2.13 — media_assets.
 *
 * The single CMS image/video library (decision D24): one row per uploaded original,
 * with its generated derivative set carried in the `variants` JSON column rather than
 * a second table — §2.13 describes one row as "one uploaded original plus its generated
 * derivatives", and §6.8's pipeline writes width-keyed entries into `variants`.
 *
 * Created first in the Phase 3 batch: cta_blocks, pages, website_sections,
 * website_section_items, website_section_media and seo_meta all reference it.
 *
 * Mutable content table → timestamps + softDeletes + blameable (CLAUDE.md §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_assets')) {
            Schema::create('media_assets', function (Blueprint $table): void {
                $table->id();
                $table->string('disk', 32)->default('public');
                $table->string('directory', 191);
                $table->string('filename', 191);
                $table->string('original_name', 191);
                $table->string('mime_type', 100);
                $table->string('extension', 12);
                $table->unsignedBigInteger('size_bytes');
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->char('checksum', 64);
                $table->string('collection', 32)->default('general');
                $table->string('profile', 24)->nullable();
                $table->json('variants')->nullable();
                $table->string('alt_text', 255)->nullable();
                $table->string('title', 191)->nullable();
                $table->string('caption', 500)->nullable();
                $table->string('derivatives_status', 16)->default('pending');
                $table->timestamp('derivatives_generated_at')->nullable();
                $table->string('failure_reason', 255)->nullable();
                $table->unsignedInteger('usage_count')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.13 Keys.
                $table->unique('checksum', 'uq_media_checksum');
                $table->index(['collection', 'created_at']);
                $table->index('derivatives_status');
                $table->index('usage_count');
                $table->index('mime_type');
            });
        }

        // §2.13 CHECK chk_media_size.
        $this->addCheck('media_assets', 'chk_media_size', '`size_bytes` > 0');
    }

    public function down(): void
    {
        // Dropping the table also drops the foreign keys it owns (created_by, updated_by).
        // Foreign keys that point *at* media_assets are owned by cta_blocks, pages,
        // website_sections, website_section_items, website_section_media and seo_meta —
        // every one of them created by a later Phase 3 migration, so each is rolled back
        // (and its own ALTER ... DROP FOREIGN KEY executed) before this one runs. The
        // named CHECK constraint is removed with the table.
        Schema::dropIfExists('media_assets');
    }

    /**
     * Add a named CHECK constraint if MariaDB does not already carry it.
     *
     * MariaDB 10.4 lists CHECK constraints in information_schema.CHECK_CONSTRAINTS,
     * so the guard makes a re-run of this migration safe.
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
