<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 · §2.5 — lead_imports: one CSV import batch.
 *
 * The staged file lives on the **private** `local` disk under `crm/imports/` (D21), never the public disk, and so
 * does the generated `error_report_path`. `file_hash` (sha256) is indexed so the wizard can warn "this exact file
 * was imported on ..." (§8.6). The four counters are moved only with atomic `increment()` inside each row's
 * transaction (§6.6), so they always sum to the rows actually processed.
 *
 * `leads.lead_import_id` points here; that constraint is added by `add_crm_deferred_foreign_keys` because `leads`
 * is created first.
 *
 * Mutable batch record → timestamps + softDeletes + blameable (CLAUDE.md §3). Its per-line log,
 * `lead_import_rows`, is the append-only child (D19).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lead_imports')) {
            return;
        }

        Schema::create('lead_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('original_filename', 255);
            $table->string('stored_path', 255);
            $table->string('file_hash', 64);
            $table->string('delimiter', 4)->default(',');
            $table->string('encoding', 16)->default('UTF-8');
            $table->json('column_map');
            $table->json('defaults')->nullable();
            $table->string('duplicate_strategy', 24)->default('import_and_flag');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('error_report_path', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.5 Keys. (created_by, created_at) also serves created_by's foreign key.
            $table->index(['status', 'created_at']);
            $table->index('file_hash');
            $table->index(['created_by', 'created_at']);
        });
    }

    public function down(): void
    {
        // Inbound: leads.lead_import_id (deferred migration, rolled back first) and lead_import_rows (rolled back
        // first). DROP TABLE removes the two blameable keys.
        Schema::dropIfExists('lead_imports');
    }
};
