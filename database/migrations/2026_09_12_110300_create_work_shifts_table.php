<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.7 — work_shifts: the definition late arrival, early leave and working hours are measured
 * against (requirement §26).
 *
 * Created **before** `employees`, because `employees.work_shift_id` points at it (§2.27 step 3).
 *
 * **Editing a shift changes nothing about the past** (HR-2). Every attendance row snapshots the window it
 * was measured against — expected in, expected out, expected minutes and both grace values — so fixing a
 * typo or moving the start time cannot rewrite a late mark from three months ago. The edit screen says so
 * in one sentence, because the opposite assumption is the natural one.
 *
 * `crosses_midnight` and `expected_minutes` are written by `WorkShiftService`, never by hand: both are
 * derived from the times, and a hand-entered value that disagreed would quietly change everybody's hours.
 */
return new class extends Migration
{
    private const TABLE = 'work_shifts';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32);
            $table->string('name', 100);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('crosses_midnight')->default(false);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('expected_minutes');
            $table->unsignedSmallInteger('grace_in_minutes')->default(15);
            $table->unsignedSmallInteger('grace_out_minutes')->default(10);
            $table->unsignedSmallInteger('min_full_day_minutes')->default(480);
            $table->unsignedSmallInteger('min_half_day_minutes')->default(240);
            $table->json('weekly_off_days')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            // default_guard (generated STORED) and uq_ws_default are added in step 18.
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.7 Keys.
            $table->unique('code', 'uq_ws_code');
            $table->index(['branch_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
