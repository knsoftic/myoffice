<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 01 — `student_fees`: what a student owes for one fee head (spine §2.2).
 *
 * **It never holds cash.** A charge is a document; the money against it lives in `student_fee_payments`,
 * and every amount column here except `gross_amount` is a **cache** of rows in another table. That is
 * stated on each column rather than assumed, because a cache somebody starts treating as the truth is
 * how a fee total and a receipt total come to disagree.
 *
 * **`generation_key` makes the INSERT the duplicate check** (F-3.15). Phase 18's two generators compose
 * it — `structure:{admission}:{head}` or `monthly:{admission}:{YYYY-MM}` — and a retried or
 * doubly-scheduled run collides on `uq_sf_generation` and reads 1062 as "already generated". No
 * generator guards with a SELECT under a row lock, because that is the race this column exists to close.
 * MariaDB unique indexes ignore NULLs, so a hand-entered charge stacks freely.
 *
 * **[D-IMP-1]: columns and indexes only.** Every foreign key in this set is added by files 18, 20 and
 * 21 — the in-spine ones form cycles across creation order, and the external ones live in phases that
 * have not migrated yet.
 */
return new class extends Migration
{
    private const TABLE = 'student_fees';

    public function up(): void
    {
        // Guards the CREATE only. The constraints below are ensured on every run, so a table left
        // behind by a half-applied migration cannot end up looking complete without them.
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->string('fee_number', 32);
            $table->string('generation_key', 64)->nullable();

            // D11. Nullable: a charge raised before branches were set up still has to be legal.
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('student_admission_id')->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();

            $table->string('fee_type', 32);
            $table->string('title', 150)->nullable();

            $table->decimal('gross_amount', 15, 2)->default('0.00');
            // CACHE of the non-scholarship rows in student_fee_discounts.
            $table->decimal('discount_amount', 15, 2)->default('0.00');
            // CACHE of the scholarship rows, kept apart because scholarships are budgeted separately.
            $table->decimal('scholarship_amount', 15, 2)->default('0.00');
            // gross - discount - scholarship, written only by StudentFeeService through Money.
            $table->decimal('net_amount', 15, 2)->default('0.00');
            // CACHE of the receipts that still count as received.
            $table->decimal('paid_amount', 15, 2)->default('0.00');
            $table->decimal('refunded_amount', 15, 2)->default('0.00');
            // CACHE of net - (paid - refunded). **May be negative** — that is an advance, not an error.
            $table->decimal('balance_amount', 15, 2)->default('0.00');

            $table->boolean('has_installment_plan')->default(false);
            $table->unsignedTinyInteger('installment_count')->default(0);
            $table->date('due_date')->nullable();
            $table->string('status', 32)->default('pending');

            // **Display snapshot only** (D37). The commission engine never reads it: it resolves the
            // referral effective on the payment date, because who referred somebody is a fact with a
            // timeline and this column is a convenience for printing a receipt.
            $table->unsignedBigInteger('collaborator_id')->nullable();

            $table->text('notes')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancellation_reason', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('fee_number', 'uq_sf_number');
            $table->unique('generation_key', 'uq_sf_generation');
            $table->index(['student_id', 'status']);
            $table->index('student_admission_id');
            $table->index(['batch_id', 'status']);
            $table->index(['due_date', 'status']);
            $table->index('branch_id');
            $table->index('collaborator_id');
            $table->index('deleted_at');
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_sf_nonneg',
            '`gross_amount` >= 0 AND `discount_amount` >= 0 AND `scholarship_amount` >= 0 '
            .'AND `net_amount` >= 0 AND `paid_amount` >= 0 AND `refunded_amount` >= 0');

        // A discount can never exceed the fee. Without this a percentage typed as 1000 instead of 10
        // produces a negative net that every downstream cache then faithfully carries.
        $this->ensure('chk_sf_discount_ceiling',
            '`discount_amount` + `scholarship_amount` <= `gross_amount`');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function ensure(string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check(self::TABLE, $name, $expression);
        }
    }
};
