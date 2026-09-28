<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two agreed-money columns the two-step registration bills on: `extra_fee` and `tax_amount`.
 *
 * The registration screen shows a full billing breakdown, and the owner asked for an extra fee and a
 * tax that apply **only when they are configured in Admin Settings**. Both therefore default to zero
 * and an institute that configures neither sees exactly the breakdown it sees today.
 *
 * **They have to be columns, not a calculation the screen does.** `StudentFeeService::generateStructure()`
 * asserts that the sum of the charge heads it writes equals the admission's `net_payable`, and aborts
 * the whole transaction when it does not. So a fee head that exists on the bill but not on the
 * admission would not merely be untidy — it would make the fee structure unissuable. Storing them is
 * also what makes the bill reproducible a year later, when the settings have moved on: the admission
 * is the snapshot of what was agreed, and a tax rate read live at print time would re-tax an old
 * receipt at today's rate.
 *
 * **Where they sit in the arithmetic**, which is the ordinary order and the one an accountant expects:
 *
 *     course_fee + admission_fee + registration_fee + extra_fee   = total_amount
 *     total_amount − discount − scholarship                        = taxable
 *     taxable + tax_amount                                         = net_payable
 *
 * `tax_amount` is a rounded money figure, not a rate — the rate lives in settings and is applied by
 * `AdmissionService::figuresFrom()` through `Money::percentage()`, so the paisa is decided once, in
 * bcmath, by the same code that decides every other figure on the row.
 *
 * **`chk_sadm_discount_ceiling` is deliberately left alone.** It reads
 * `discount + scholarship <= course_fee + admission_fee + registration_fee`, so neither new column
 * widens what may be discounted. That is the conservative direction and it is also correct: nobody
 * discounts a tax.
 *
 * A second CHECK rather than a rewrite of `chk_sadm_nonneg`: altering a live constraint means
 * dropping and recreating it, and MariaDB DDL is not transactional (**D70**), so a failure halfway
 * would leave the table with no non-negativity guard at all. Adding one leaves the existing guard
 * untouched whatever happens.
 *
 * Reversible: `down()` drops the constraint and the two columns, and neither is referenced by a
 * foreign key, an index or a generated column.
 */
return new class extends Migration
{
    private const TABLE = 'student_admissions';

    private const CHECK = 'chk_sadm_extra_nonneg';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            // Checked one at a time so a half-applied run heals rather than throwing (D70).
            if (! Schema::hasColumn(self::TABLE, 'extra_fee')) {
                $table->decimal('extra_fee', 15, 2)->default(0)->after('registration_fee');
            }

            if (! Schema::hasColumn(self::TABLE, 'tax_amount')) {
                $table->decimal('tax_amount', 15, 2)->default(0)->after('scholarship_amount');
            }
        });

        if (Schema::hasColumn(self::TABLE, 'extra_fee') && Schema::hasColumn(self::TABLE, 'tax_amount')) {
            RawSchema::check(self::TABLE, self::CHECK, '`extra_fee` >= 0 AND `tax_amount` >= 0');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // `RawSchema` adds checks but does not drop them; the one other migration that needs to
        // drop one does it the same way.
        if (RawSchema::checkExists(self::CHECK)) {
            DB::statement(sprintf('ALTER TABLE `%s` DROP CONSTRAINT `%s`', self::TABLE, self::CHECK));
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            foreach (['extra_fee', 'tax_amount'] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
