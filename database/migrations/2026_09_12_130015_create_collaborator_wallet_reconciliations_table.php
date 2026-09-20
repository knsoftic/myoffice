<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 15 — `collaborator_wallet_reconciliations`: the proof (spine §2.16).
 *
 * §50 asks that totals not rely on a stored balance — that they be reproducible. This table turns that
 * from an intention into a dated, queryable record: one row per collaborator per run, holding the
 * figures re-derived from the ledger **beside** the figures the wallet was storing, the drift between
 * them, and whether anything was repaired.
 *
 * **A repair rewrites the cache only, never the ledger.** When the two disagree the ledger is right by
 * definition — it is the thing the cache is a cache of — and a "repair" that touched a commission entry
 * would be the system quietly deciding what somebody earned.
 *
 * Written once and never edited: no `updated_at`, no `deleted_at`. A proof that can be amended is not a
 * proof.
 */
return new class extends Migration
{
    private const TABLE = 'collaborator_wallet_reconciliations';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            // One UUID per run across every collaborator, so "how many wallets drifted on the 3rd" is
            // one query rather than a date-range guess.
            $table->char('run_uuid', 36);
            $table->string('run_type', 16);
            $table->unsignedBigInteger('collaborator_id');
            $table->unsignedBigInteger('collaborator_wallet_id');
            $table->dateTime('as_of');

            // Nine buckets, each stored twice: what the ledger says and what the wallet said. Written
            // as a loop because nine hand-typed pairs is nine chances to transpose one.
            foreach ([
                'pending', 'available', 'reserved', 'paid', 'lifetime',
                'student', 'project', 'adjustments', 'reversed',
            ] as $bucket) {
                $table->decimal('expected_'.$bucket, 15, 2)->default('0.00');
                $table->decimal('stored_'.$bucket, 15, 2)->default('0.00');
            }

            // The counts catch a row that vanished: the sums can agree while the count does not.
            $table->unsignedBigInteger('expected_entry_count')->default(0);
            $table->unsignedBigInteger('stored_entry_count')->default(0);

            // The sum of absolute differences. 0.00 is the only acceptable value.
            $table->decimal('drift_total', 15, 2)->default('0.00');
            // R2: lifetime = pending + available + reserved + paid.
            $table->boolean('identity_holds')->default(true);
            // R3: paid - SUM(paid payouts.amount); must be 0.00.
            $table->decimal('payout_cross_check', 15, 2)->default('0.00');
            // R4, R4b, R6 and R5 — four different ways the cache and the ledger can disagree, counted
            // separately because each points at a different bug.
            $table->unsignedInteger('allocation_mismatch_count')->default(0);
            $table->unsignedInteger('orphan_allocation_count')->default(0);
            $table->unsignedInteger('reversal_group_mismatch_count')->default(0);
            $table->decimal('bucket_crossfoot_diff', 15, 2)->default('0.00');

            $table->unsignedBigInteger('last_entry_id')->nullable();
            $table->string('status', 16)->default('ok');

            $table->boolean('repaired')->default(false);
            $table->dateTime('repaired_at')->nullable();
            $table->unsignedBigInteger('repaired_by')->nullable();

            // Per-bucket diffs and the offending entry / entitlement / payout ids.
            $table->json('details')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->dateTime('checked_at');

            $table->dateTime('created_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            // A retried job writes one row per collaborator per run, never a pile of duplicates.
            $table->unique(['run_uuid', 'collaborator_id'], 'uq_cwr_run');
            $table->index(['collaborator_id', 'checked_at'], 'idx_cwr_collaborator');
            $table->index(['status', 'checked_at'], 'idx_cwr_status');
            $table->index('run_uuid', 'idx_cwr_run');
            $table->index('collaborator_wallet_id', 'idx_cwr_wallet');
            $table->index('last_entry_id', 'idx_cwr_last_entry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
