<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 · §2.6 — lead_import_rows: the per-line result of an import, so "row 42: invalid email" is reviewable.
 *
 * `UNIQUE uq_lir_row(lead_import_id, row_number)` is the idempotency guard: a retried chunk re-inserting a line hits
 * 1062 and is skipped, so a lost queue acknowledgement can never double-create a lead (§11 test 39).
 *
 * **Append-only per-row log** (D19 — "audit, log and run history", CLAUDE.md §3): timestamps only, **no
 * `deleted_at` and no blameable pair** — the child rows of a blameable batch (resolutions §8 row 11). The model
 * refuses an Eloquent delete; rows leave only through `crm:prune-imports` (a query-builder delete after
 * `crm.import_row_retention_days`) or the cascade of a force-deleted batch.
 *
 * `row_number` is backticked by the query builder; hand-written SQL must quote it too (it is a window-function name).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lead_import_rows')) {
            return;
        }

        Schema::create('lead_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_import_id')->constrained('lead_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw');
            $table->string('status', 24);
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('duplicate_lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->string('duplicate_match_type', 32)->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            // §2.6 Keys. uq_lir_row and (lead_import_id, status) also serve lead_import_id's foreign key.
            $table->unique(['lead_import_id', 'row_number'], 'uq_lir_row');
            $table->index(['lead_import_id', 'status']);
            $table->index('lead_id');
            $table->index('duplicate_lead_id');
        });
    }

    public function down(): void
    {
        // Nothing references lead_import_rows. DROP TABLE removes its three foreign keys and uq_lir_row.
        Schema::dropIfExists('lead_import_rows');
    }
};
