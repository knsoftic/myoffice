<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 · §2.14 — sitemap_generations.
 *
 * One row per sitemap build (§105): url count, bytes, duration, trigger, outcome and the
 * per-provider breakdown from `SitemapRegistry`, so a reviewer sees which phase
 * contributed what.
 *
 * Run-history / log category → **no `deleted_at`**, no `updated_at` and no `updated_by`,
 * per decision D19 (CLAUDE.md §3) and §2.14 / §12.2 Q1; the model carries a `deleting`
 * guard instead. It keeps only `created_by` and `created_at`.
 *
 * Depends on `users` only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sitemap_generations')) {
            return;
        }

        Schema::create('sitemap_generations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('url_count')->default(0);
            $table->unsignedInteger('byte_size')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            // manual | publish | scheduled. `trigger` is a MariaDB reserved word; Laravel
            // quotes every identifier, so the column name is safe as written.
            $table->string('trigger', 24)->default('manual');
            $table->string('status', 16)->default('ok');
            $table->string('failure_reason', 500)->nullable();
            $table->json('providers')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // No `updated_at`: a build record is a log line (§2.14).
            $table->timestamp('created_at')->nullable();

            // §2.14 Keys.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        // DROP TABLE removes the one foreign key this table owns (created_by) in the
        // same statement. Nothing references sitemap_generations.
        Schema::dropIfExists('sitemap_generations');
    }
};
