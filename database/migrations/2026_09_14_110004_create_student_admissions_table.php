<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 · file 4 — `student_admissions`: §69's record and the carrier of §68's pipeline
 * (phase-14-17 §2.15, §2.30.5, §2.31).
 *
 * **One column holds the stage, and it is called `stage` rather than `status` ([D-IN-17]).**
 * `students.status` already exists and the two advance in lockstep; two columns called `status` on
 * two tables joined in every query is how a screen ends up reading the wrong one. Phase 18 asks for
 * `student_admissions.status`, so the model exposes an accessor aliasing `stage` and Phase 18's code
 * compiles unchanged — one column, two spellings, no second source of truth.
 *
 * **This is the spine's default commission document**, which is why INV-I2 exists: once Phase 18
 * issues the first charge it stamps `figures_locked_at`, and from that moment the agreed figures are
 * frozen. A commission was computed from them; editing them afterwards would silently change what a
 * partner earned on money that has already moved. A correction after the lock is a fee adjustment,
 * which leaves its own row.
 *
 * **Four money columns are CACHES that only Phase 18 may write.** `charged_amount`, `paid_amount`,
 * `refunded_amount` and `balance_amount` are sums of `student_fees` and its receipts. The model's
 * `updating` hook refuses a write to any of them outside `StudentFeeService::withinServiceContext()`,
 * so a screen that "just updates the balance" cannot exist. `balance_amount` may go negative — that is
 * an advance, not an error.
 *
 * **`course_fee_net_payable` is generated STORED**, offered to Phase 10 for the case where admission
 * and registration fees are not commissionable (§13). Generated rather than written, because a column
 * the engine divides by must not be able to disagree with the three it is derived from.
 *
 * **`active_guard` + `uq_sadm_live` is one live admission per student per course** ([D-IN-4]). The
 * guard is NULL for every terminal stage, and MariaDB lets NULLs stack, so a completed or cancelled
 * admission leaves the pair free and the student can be re-admitted — which is the normal case, not
 * the exception.
 *
 * `batch_id` carries no FK here: `batches` ships in Phase 16 ([D-IN-1]).
 */
return new class extends Migration
{
    private const TABLE = 'student_admissions';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->string('admission_number', 32);
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->unsignedBigInteger('student_application_id')->nullable();
            $table->unsignedBigInteger('course_inquiry_id')->nullable();

            $table->string('stage', 24)->default('application');
            $table->date('admission_date');
            $table->date('registration_date')->nullable();
            $table->date('activated_on')->nullable();
            $table->date('completed_on')->nullable();

            $table->unsignedBigInteger('counselor_id')->nullable();
            $table->string('delivery_mode', 16)->nullable();
            $table->string('preferred_timing', 16)->nullable();

            // The agreed price, snapshotted from the course at admission. A later change to the
            // catalogue price moves what the NEXT student is quoted and nothing that was already sold.
            $table->decimal('course_fee', 15, 2)->default(0);
            $table->decimal('admission_fee', 15, 2)->default(0);
            $table->decimal('registration_fee', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('scholarship_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('net_payable', 15, 2)->default(0);

            $table->string('discount_reason', 255)->nullable();
            $table->string('payment_method', 32)->nullable();
            $table->decimal('monthly_fee', 15, 2)->nullable();
            $table->boolean('installment_plan_requested')->default(false);
            $table->unsignedTinyInteger('requested_installments')->default(0);

            $table->timestamp('figures_locked_at')->nullable();

            $table->decimal('charged_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('refunded_amount', 15, 2)->default(0);
            $table->decimal('balance_amount', 15, 2)->default(0);

            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('withdrawal_reason', 255)->nullable();

            $table->unsignedBigInteger('collaborator_id')->nullable();
            $table->string('referral_code', 32)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('admission_number', 'uq_sadm_number');

            $table->index(['stage', 'admission_date'], 'idx_sadm_stage');
            $table->index(['student_id', 'stage'], 'idx_sadm_student');
            $table->index(['course_id', 'stage'], 'idx_sadm_course');
            $table->index('batch_id', 'idx_sadm_batch');
            $table->index(['counselor_id', 'admission_date'], 'idx_sadm_counselor');
            $table->index('collaborator_id', 'idx_sadm_collaborator');
            $table->index(['branch_id', 'stage'], 'idx_sadm_branch');
            $table->index('admission_date', 'idx_sadm_date');
            $table->index('student_application_id', 'idx_sadm_application');
            $table->index('course_inquiry_id', 'idx_sadm_inquiry');
            $table->index('cancelled_by', 'idx_sadm_canceller');

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            $table->foreign('student_id', RawSchema::foreignKeyName(self::TABLE, 'student_id'))
                ->references('id')->on('students')->restrictOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName(self::TABLE, 'course_id'))
                ->references('id')->on('courses')->restrictOnDelete();
            $table->foreign('student_application_id', RawSchema::foreignKeyName(self::TABLE, 'student_application_id'))
                ->references('id')->on('student_applications')->nullOnDelete();
            $table->foreign('course_inquiry_id', RawSchema::foreignKeyName(self::TABLE, 'course_inquiry_id'))
                ->references('id')->on('course_inquiries')->nullOnDelete();
            $table->foreign('counselor_id', RawSchema::foreignKeyName(self::TABLE, 'counselor_id'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by', RawSchema::foreignKeyName(self::TABLE, 'cancelled_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('collaborator_id', RawSchema::foreignKeyName(self::TABLE, 'collaborator_id'))
                ->references('id')->on('collaborators')->nullOnDelete();

            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });

        $this->generatedColumns();
    }

    /**
     * Two STORED generated columns, added after the table exists because Laravel's blueprint has no
     * portable way to express `GREATEST` or a `CASE` that yields NULL.
     */
    private function generatedColumns(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'course_fee_net_payable')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'course_fee_net_payable',
                'DECIMAL(15,2)',
                'GREATEST(`course_fee` - `discount_amount` - `scholarship_amount`, 0)',
            );
        }

        if (! Schema::hasColumn(self::TABLE, 'active_guard')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'active_guard',
                'TINYINT',
                "CASE WHEN `stage` NOT IN ('cancelled', 'withdrawn', 'completed') THEN 1 ELSE NULL END",
            );
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_sadm_live', unique: true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_sadm_live', ['student_id', 'course_id', 'active_guard']);
        }
    }

    private function constraints(): void
    {
        $this->ensure('chk_sadm_stage',
            "`stage` IN ('application', 'registration', 'fee_collection', 'batch_assignment', 'active',"
            ." 'completed', 'cancelled', 'withdrawn')");
        $this->ensure('chk_sadm_nonneg',
            '`course_fee` >= 0 AND `admission_fee` >= 0 AND `registration_fee` >= 0'
            .' AND `discount_amount` >= 0 AND `scholarship_amount` >= 0'
            .' AND `total_amount` >= 0 AND `net_payable` >= 0'
            .' AND `charged_amount` >= 0 AND `paid_amount` >= 0 AND `refunded_amount` >= 0'
            .' AND (`monthly_fee` IS NULL OR `monthly_fee` >= 0)');
        // Nothing may be discounted below free. The service computes with Money and would refuse first;
        // this is what makes it true of a row written by an import or a console script.
        $this->ensure('chk_sadm_discount_ceiling',
            '`discount_amount` + `scholarship_amount` <= `course_fee` + `admission_fee` + `registration_fee`');
        $this->ensure('chk_sadm_mode',
            "`delivery_mode` IS NULL OR `delivery_mode` IN ('physical', 'online', 'hybrid')");
        $this->ensure('chk_sadm_timing',
            "`preferred_timing` IS NULL OR `preferred_timing` IN ('morning', 'afternoon', 'evening', 'night', 'weekend', 'flexible')");
        $this->ensure('chk_sadm_cancel_reason',
            "`stage` <> 'cancelled' OR (`cancellation_reason` IS NOT NULL AND `cancellation_reason` <> '')");
        $this->ensure('chk_sadm_withdraw_reason',
            "`stage` <> 'withdrawn' OR (`withdrawal_reason` IS NOT NULL AND `withdrawal_reason` <> '')");
        // A plan with no installments is the option switched on and then not offered.
        $this->ensure('chk_sadm_installments',
            '(`installment_plan_requested` = 0 AND `requested_installments` = 0)'
            .' OR (`installment_plan_requested` = 1 AND `requested_installments` BETWEEN 1 AND 36)');
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
