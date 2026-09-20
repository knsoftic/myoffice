<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.27 step 16 — the edges that let a payroll run lock the attendance it was calculated from
 * (HR-18).
 *
 * `attendances` and `attendance_monthly_summaries` are created long before `payroll_runs`, because
 * payroll reads them; so the FK back to the locking run is added here, the same way the circular
 * department/employee edge is.
 *
 * Locking is the point: once a run is locked, every attendance row and every summary in the period
 * carries `locked_at` and the id of the run that locked it, and a later edit or rebuild is refused by
 * name. Without that, correcting a March attendance row after March's salaries were paid would silently
 * make the payroll evidence disagree with the payroll.
 *
 * `nullOnDelete` on both: a cancelled run that is later removed leaves the rows unlocked rather than
 * orphaned, and `PayrollRunService` clears `locked_at` in the same transaction.
 */
return new class extends Migration
{
    private const COLUMN = 'locked_by_payroll_run_id';

    private const TABLES = ['attendances', 'attendance_monthly_summaries'];

    public function up(): void
    {
        if (! Schema::hasTable('payroll_runs')) {
            return;
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, self::COLUMN)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId(self::COLUMN)->nullable()->after('locked_at')
                    ->constrained('payroll_runs')->nullOnDelete();

                $blueprint->index(self::COLUMN);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, self::COLUMN)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign([self::COLUMN]);
                $blueprint->dropIndex([self::COLUMN]);
                $blueprint->dropColumn(self::COLUMN);
            });
        }
    }
};
