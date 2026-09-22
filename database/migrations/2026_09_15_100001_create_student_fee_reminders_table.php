<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 18 · file 1 — `student_fee_reminders`: the only table this phase creates (phase-18 §2.2).
 *
 * Phase 10 owns and created all four money tables (`student_fees`, `student_fee_installments`,
 * `student_fee_discounts`, `student_fee_payments`); this phase adds the services, the screens and this
 * one log. **It carries no money column**, so it is not one of the spine's append-only financial
 * tables — but it is an append-only *log*, which under `CLAUDE.md` §3 / **D19** means no `deleted_at`
 * and no `updated_at`: a row records that a message was sent, and a sent message cannot be edited or
 * un-sent. `created_at` alone is the write time; `sent_at` is the business time.
 *
 * **[D18-1] — the dedupe guard is a generated column, because MariaDB lets NULLs stack.**
 * §97 wants "due soon", "due today" and "overdue" notices, and §77 schedules them per installment. A
 * retried job, a second scheduler tick and a staff member pressing "Send reminder now" must collapse
 * onto one row, and the guard for that is an INSERT that fails, never a SELECT that looks. The natural
 * key is `(student_fee_id, student_fee_installment_id, type, due_date, offset_days)` — but a charge
 * with no installment plan has `student_fee_installment_id IS NULL`, and a unique index in MariaDB
 * permits unlimited NULL rows. That charge would be reminded every single tick, which is the one
 * failure this table exists to prevent. So the index is on the STORED generated column
 * `dedupe_line = COALESCE(student_fee_installment_id, 0)`: the guard bites for both cases while the
 * foreign key column stays honestly nullable. Writing `0` into the FK itself was the alternative and
 * is a dangling foreign key wearing a disguise.
 *
 * The generated column is raw SQL and **fails loudly** if the server rejects it (spine R-3). There is
 * deliberately no fallback to a nullable guard: a dedupe index that silently does not apply to half the
 * rows is worse than no index, because nobody would look for it.
 *
 * **Every FK is `cascadeOnDelete` except `branch_id` and `sent_by`**, and that is the right way round
 * here even though this phase's money tables use `restrictOnDelete` everywhere. A reminder log holds no
 * money and says nothing once its charge is gone; a branch or a user, by contrast, must still be
 * deletable without erasing the evidence of who was told what, so those two are `nullOnDelete` (D11).
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards only the CREATE and then ensures the
 * generated column, the unique index and the CHECK on every run — a half-applied migration heals on the
 * next pass instead of needing a hand-written repair.
 */
return new class extends Migration
{
    private const TABLE = 'student_fee_reminders';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->guard();
        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('student_fee_id');
            // Null = the charge itself has no installment plan, so the reminder is about the charge.
            $table->unsignedBigInteger('student_fee_installment_id')->nullable();
            // Denormalised: the student panel scopes on it, and "how many reminders has this student
            // had" is a question asked far more often than the join it would otherwise need.
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('branch_id')->nullable();

            $table->string('type', 32);
            // `database` now, `mail` when Phase 22's mailer lands — the value actually used, not the
            // value configured, so a night the mailer was down reads as what happened (§97).
            $table->string('channel', 16)->default('database');

            // Part of the dedupe key: the due date the reminder was *about*. A rebuilt plan that moves
            // a line's due date legitimately earns a fresh reminder, and this is what says so.
            $table->date('due_date');
            // Signed, per FeeReminderType::offsetSign(): +3 = three days before, 0 = due today,
            // -5 = five days overdue.
            $table->smallInteger('offset_days');

            // Snapshot at send time, so the message can be reproduced exactly as the student saw it.
            $table->decimal('amount_due', 15, 2)->default('0.00');
            $table->string('recipient_email', 191)->nullable();
            // No SMS channel in this phase; the column exists so adding one needs no migration.
            $table->string('recipient_phone', 32)->nullable();
            // The `notifications.id` Laravel wrote, for the delivery trail.
            $table->char('notification_id', 36)->nullable();

            // `useCurrent()` is load-bearing, not decoration. MariaDB gives the FIRST timestamp
            // column of a table an implicit `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
            // unless it carries an explicit default — and `ON UPDATE` on an append-only log is a
            // column that rewrites itself. An explicit default suppresses it.
            $table->timestamp('sent_at')->useCurrent();
            // Null when the scheduler sent it; set when a person pressed the button.
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->boolean('is_manual')->default(false);
            // One UUID per scheduled run, so "who did the 09:00 run reach" is answerable.
            $table->char('run_uuid', 36)->nullable();

            // Written once: no `updated_at` and no `deleted_at` (D19).
            $table->timestamp('created_at')->nullable();

            // No index on `student_fee_id` alone: `uq_sfr_dedupe` already leads with it, and a
            // prefix of a longer index counts as present for F-9.2. A second copy would only
            // cost writes.
            $table->index(['student_id', 'sent_at'], 'idx_sfr_student');
            $table->index(['type', 'sent_at'], 'idx_sfr_type');
            $table->index('run_uuid', 'idx_sfr_run');
            $table->index('student_fee_installment_id', 'idx_sfr_line');
            $table->index('branch_id', 'idx_sfr_branch');
            $table->index('sent_by', 'idx_sfr_sender');

            $table->foreign('student_fee_id', RawSchema::foreignKeyName(self::TABLE, 'student_fee_id'))
                ->references('id')->on('student_fees')->cascadeOnDelete();
            $table->foreign('student_fee_installment_id', RawSchema::foreignKeyName(self::TABLE, 'student_fee_installment_id'))
                ->references('id')->on('student_fee_installments')->cascadeOnDelete();
            $table->foreign('student_id', RawSchema::foreignKeyName(self::TABLE, 'student_id'))
                ->references('id')->on('students')->cascadeOnDelete();
            // D11: a branch may be removed without erasing who was told what.
            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            $table->foreign('sent_by', RawSchema::foreignKeyName(self::TABLE, 'sent_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * [D18-1]: the generated column and the unique index that actually bites.
     */
    private function guard(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'dedupe_line')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'dedupe_line',
                'bigint unsigned',
                'COALESCE(`student_fee_installment_id`, 0)',
            );
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_sfr_dedupe', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_sfr_dedupe', [
                'student_fee_id', 'dedupe_line', 'type', 'due_date', 'offset_days',
            ]);
        }
    }

    private function constraints(): void
    {
        $this->ensure('chk_sfr_amount', '`amount_due` >= 0');
        $this->ensure('chk_sfr_type', "`type` IN ('upcoming_due', 'due_today', 'overdue')");
        $this->ensure('chk_sfr_channel', "`channel` IN ('database', 'mail')");
        // A manual send has a sender; a scheduled one has a run. Neither may be anonymous, because
        // "who sent this" is the question a complaint about being chased starts with.
        $this->ensure('chk_sfr_origin', '(`is_manual` = 1 AND `sent_by` IS NOT NULL)'
            .' OR (`is_manual` = 0 AND `run_uuid` IS NOT NULL)');
    }

    public function down(): void
    {
        // The unique index reads the generated column, so it goes first — dropping the column while an
        // index depends on it is error 1553, and PH18-39 rolls this back over a table holding rows.
        if (Schema::hasTable(self::TABLE)) {
            if (RawSchema::indexExists(self::TABLE, 'uq_sfr_dedupe', true)) {
                RawSchema::dropIndex(self::TABLE, 'uq_sfr_dedupe');
            }

            if (Schema::hasColumn(self::TABLE, 'dedupe_line')) {
                RawSchema::dropColumn(self::TABLE, 'dedupe_line');
            }
        }

        Schema::dropIfExists(self::TABLE);
    }

    private function ensure(string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check(self::TABLE, $name, $expression);
        }
    }
};
