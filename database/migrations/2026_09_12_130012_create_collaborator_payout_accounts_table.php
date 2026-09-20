<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 12 — `collaborator_payout_accounts`: reusable encrypted destinations (spine §2.15).
 *
 * **Kept out of the payout row on purpose.** A bank account is typed once and snapshotted at each
 * payment; re-typing an IBAN per request is both a data-entry risk and a money risk, and the one error
 * it produces is money sent to the wrong person.
 *
 * `details_encrypted` holds the account number, IBAN, branch code or mobile number behind Laravel's
 * `encrypted` cast. **Only `account_last4` is ever rendered** (INV-C6): no ability anywhere reveals the
 * rest, which is why `collaborator_payout_accounts` deliberately has no `view_financial` permission —
 * there is nothing to unmask.
 *
 * `uq_cpacc_default` over a generated column (files 16-17) makes "exactly one default per collaborator"
 * a database fact rather than a clear-all-others loop that can half-fail and leave two defaults or none.
 *
 * Soft deletes **are** allowed here: this is a contact detail, not a movement. A paid payout keeps its
 * own encrypted snapshot, so removing the account never makes a past transfer unexplainable.
 */
return new class extends Migration
{
    private const TABLE = 'collaborator_payout_accounts';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('collaborator_id');
            $table->string('label', 60);
            $table->string('method', 32);

            // Not secret, and needed to verify a transfer went to the right name.
            $table->string('account_title', 150);
            $table->string('bank_name', 150)->nullable();

            // Never logged, never in an activity diff, never in a response body (INV-C6).
            $table->text('details_encrypted');
            // The only part ever shown.
            $table->string('account_last4', 8)->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->string('status', 16)->default('active');
            // `default_guard` is a STORED generated column added by file 16.

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['collaborator_id', 'status'], 'idx_cpacc_collaborator');
            $table->index('deleted_at', 'idx_cpacc_deleted');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
