<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.16 — leave_request_days: the day-by-day expansion attendance and the quota both read.
 *
 * **One counted leave day per employee per date** (HR-8), enforced by `uq_lrd_day` over the generated
 * `day_guard`. Two half-day leaves of different types on one date are refused with a message naming the
 * existing request — a rule in a service could be bypassed, a unique index cannot.
 *
 * Skipped weekends and holidays inside a range are **still written**, marked `is_counted = false`. That
 * is what lets the screen explain why five calendar days cost three quota days instead of leaving a gap
 * somebody has to reconstruct.
 *
 * `is_active` goes false when the request is rejected or cancelled, which releases the guard so the date
 * can be requested again.
 *
 * **No `deleted_at`** (D19): this is the evidence behind a quota figure.
 */
return new class extends Migration
{
    private const TABLE = 'leave_request_days';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            // Denormalised so the day guard can exist on this row alone.
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('leave_date');
            $table->string('day_portion', 16)->default('full_day');
            $table->decimal('day_fraction', 8, 4)->default(1);
            $table->boolean('is_counted')->default(true);
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
            // day_guard (generated STORED) and uq_lrd_day are added in step 18.
            $table->timestamps();
            // No softDeletes(): the expansion is evidence behind a quota figure (D19).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.16 Keys.
            $table->index('leave_request_id');
            $table->index(['employee_id', 'leave_date']);
            $table->index('attendance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
