<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.8 — holidays: the dates requirement §26's "absent" must never punish.
 *
 * **One row per date, not a range** ([D-HR-6]). The attendance engine asks "is this date a holiday?"
 * once per employee per day; a range makes that a scan and makes `UNIQUE(branch, date)` impossible.
 * `HolidayService::createRange()` writes the rows, which is also the shape the calendar screen wants.
 *
 * A null `branch_id` means every branch (D11). `holiday_guard` (step 18) carries `uq_hol_guard`, so one
 * active holiday exists per branch per date.
 *
 * `is_paid = false` produces `payable_factor = 0` for that date — an unpaid company shutdown is a real
 * thing and must not silently pay everybody.
 */
return new class extends Migration
{
    private const TABLE = 'holidays';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('holiday_date');
            $table->string('title', 150);
            $table->string('holiday_type', 16)->default('public');
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_recurring_yearly')->default(false);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            // holiday_guard (generated STORED) and uq_hol_guard are added in step 18.
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.8 Keys.
            $table->index(['holiday_date', 'is_active']);
            $table->index(['branch_id', 'holiday_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
