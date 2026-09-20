<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.10 — attendance_corrections: the audit trail of every manual attendance change (HR-6).
 *
 * **An attendance row is never silently edited.** Every manual change — by the employee asking or by HR
 * deciding — writes a row here carrying `old_values`, `new_values`, a mandatory reason of at least ten
 * characters and the actor. An HR-direct correction is created already approved and applied in the same
 * transaction, so the audit trail is **identical** whichever path was taken; the difference is the
 * `source` column, not whether evidence exists.
 *
 * `attendance_id` is nullable because a correction can precede its row: asking for a day that was never
 * marked is exactly the case that needs correcting, which is why `attendance_date` is carried here too.
 *
 * **Append-only** (D19): only `status`, the review columns and `applied_at` may change; a `deleting` hook
 * throws and a `BEFORE DELETE` trigger raises underneath it. Deleting this row would destroy the evidence
 * of why a number changed.
 */
return new class extends Migration
{
    private const TABLE = 'attendance_corrections';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            // Carried so a correction can precede the row it corrects.
            $table->date('attendance_date');
            $table->string('correction_type', 32);
            $table->string('source', 16);
            $table->json('old_values')->nullable();
            $table->json('new_values');
            $table->text('reason');
            $table->string('status', 16)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // DATETIME, not TIMESTAMP (D67): a non-null TIMESTAMP silently acquires
            // ON UPDATE CURRENT_TIMESTAMP on this server, which would rewrite when a correction was
            // asked for every time somebody reviewed it.
            $table->dateTime('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_comment', 255)->nullable();
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();
            // No softDeletes(): deleting this row destroys the evidence of why a number changed (D19).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.10 Keys.
            $table->index(['employee_id', 'attendance_date']);
            $table->index(['status', 'requested_at']);
            $table->index('attendance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
