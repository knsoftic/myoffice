<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 · §2.2 — lead_activities: the timeline of one lead.
 *
 * Manual rows (note, call, WhatsApp, email, meeting) and system rows written by the services (status changes with
 * `from_status` / `to_status`, assignments with `from_user_id` / `to_user_id`, follow-up events, conversion,
 * import, duplicate links). A system row (`is_system = true`) is never editable or deletable — policy plus model
 * hook (§11 test 32). CHECK `chk_la_status_pair` makes a `status_changed` row without a target status impossible.
 *
 * `occurred_at` is back-datable (a call logged an hour later) and defaults to the insert time. The only feed query
 * is `idx_la_feed(lead_id, occurred_at, id)`.
 *
 * `lead_follow_up_id` points at `lead_follow_ups`, which is created **after** this table, so it is created bare and
 * indexed here and constrained by `add_crm_deferred_foreign_keys` ([D-P5-2]).
 *
 * The contract gives this table soft deletes (a person's own note may be removed and stays in the audit log) →
 * timestamps + softDeletes + blameable. `lead_id` cascades: a timeline is meaningless without its lead, and a
 * force delete is Super Admin only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lead_activities')) {
            Schema::create('lead_activities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $table->string('type', 32);
                $table->boolean('is_system')->default(false);
                $table->string('subject', 150)->nullable();
                $table->text('body')->nullable();
                $table->string('outcome', 32)->nullable();
                $table->unsignedSmallInteger('duration_minutes')->nullable();
                $table->string('from_status', 32)->nullable();
                $table->string('to_status', 32)->nullable();
                $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
                // [D-P5-2] deferred link → lead_follow_ups.id (nullOnDelete), created after this table.
                $table->unsignedBigInteger('lead_follow_up_id')->nullable();
                $table->foreignId('related_lead_id')->nullable()->constrained('leads')->nullOnDelete();
                $table->dateTime('occurred_at')->useCurrent();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.2 Keys. idx_la_feed also serves lead_id's foreign key.
                $table->index(['lead_id', 'occurred_at', 'id'], 'idx_la_feed');
                $table->index(['type', 'occurred_at']);
                $table->index(['created_by', 'occurred_at']);
                $table->index('lead_follow_up_id');
                $table->index('occurred_at');
            });
        }

        $this->addCheck(
            'lead_activities',
            'chk_la_status_pair',
            "(`type` <> 'status_changed') or (`to_status` is not null)"
        );
    }

    public function down(): void
    {
        // Nothing references lead_activities. The deferred lead_follow_up_id key is dropped by
        // add_crm_deferred_foreign_keys' rollback first; DROP TABLE removes the rest and chk_la_status_pair.
        Schema::dropIfExists('lead_activities');
    }

    /**
     * Add a named CHECK constraint if MariaDB does not already carry it (idempotent re-run).
     */
    private function addCheck(string $table, string $name, string $expression): void
    {
        if (! Schema::hasTable($table) || $this->hasCheck($table, $name)) {
            return;
        }

        DB::statement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)', $table, $name, $expression));
    }

    private function hasCheck(string $table, string $name): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.CHECK_CONSTRAINTS'
            .' WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [DB::getDatabaseName(), $table, $name]
        ) !== [];
    }
};
