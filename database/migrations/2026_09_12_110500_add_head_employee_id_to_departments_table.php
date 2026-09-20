<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.2 / §2.27 step 5 — the circular edge `departments.head_employee_id -> employees.id`.
 *
 * `departments` is created first because `employees.department_id` needs it, and `employees` cannot be
 * created first because a department head is an employee. One of the two edges therefore has to be added
 * afterwards, and this is it — the same shape [D-FS-1] and [D-P6-1] use elsewhere, so `migrate:fresh`
 * works in any order and every rollback is clean.
 *
 * `nullOnDelete`: removing an employee record leaves the department headless rather than refusing, because
 * a department without a head is an ordinary state a business passes through.
 */
return new class extends Migration
{
    private const TABLE = 'departments';

    private const COLUMN = 'head_employee_id';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasTable('employees')) {
            return;
        }

        if (Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->foreignId(self::COLUMN)->nullable()->after('description')
                ->constrained('employees')->nullOnDelete();

            $table->index(self::COLUMN);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropForeign([self::COLUMN]);
            $table->dropIndex([self::COLUMN]);
            $table->dropColumn(self::COLUMN);
        });
    }
};
