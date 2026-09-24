<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 25 · `backup_restores` — the record of the single most consequential act an operator can
 * perform (phase-24-25 §2.2, §6.10.5).
 *
 * **A restore overwrites everything, so its record must outlive the person who ran it.** That is
 * why this table is append-only (D19, "audit, log and run history") with no `deleted_at`, a model
 * `deleting` hook and `trg_brs_no_delete` beneath it, and why `requested_by` is
 * **restrictOnDelete** rather than `nullOnDelete`: a row that says a production database was
 * overwritten on a Tuesday with no one attached to it is not an audit trail, it is an alibi.
 *
 * **Both backup references are RESTRICT, and that is the point of the pair.** `backup_run_id` is
 * what went in; `pre_restore_backup_run_id` is the copy of what it replaced (§6.10.5 step 5, null
 * only for `target = local`). Either one becoming an orphan would leave the row unable to answer
 * the only two questions it exists for — what did we restore, and what could we go back to. The
 * `backup_runs` table refuses deletes outright, so RESTRICT here is the second lock on the same
 * door.
 *
 * **`row_counts_before` / `row_counts_after` are the proof, not decoration.** They hold the
 * §6.10.4 table counts on both sides, with `ledger_rows_before` / `ledger_rows_after` broken out as
 * columns so the financial figure is greppable and indexable rather than buried in JSON. A restore
 * that silently loses a thousand ledger rows is the failure this table was written to make
 * impossible to miss.
 *
 * **D70: MariaDB DDL is not transactional.** The CREATE is guarded; the CHECKs, the indexes and the
 * trigger are ensured on every run.
 */
return new class extends Migration
{
    private const TABLE = 'backup_restores';

    private const TRIGGER = 'trg_brs_no_delete';

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
            $table->bigIncrements('id');

            $table->char('uuid', 26);

            // Declared as plain columns and wired up at the foot of this closure, so the named
            // indexes below can be created *before* the foreign keys. MariaDB invents an index for
            // a foreign key that has none, and a `constrained()` call here would leave the table
            // carrying both that invented index and `idx_brs_backup` over the same column.
            $table->unsignedBigInteger('backup_run_id');
            $table->unsignedBigInteger('pre_restore_backup_run_id')->nullable();

            $table->string('target', 16);
            $table->string('status', 16)->default('requested');

            // Never defaulted silently: a restore that guessed which database it was writing to is
            // the accident this column exists to prevent.
            $table->string('database_name', 64);

            // NOT NULL, and validated `min:20` by the Form Request — "testing" does not pass review.
            $table->string('reason', 500);

            $table->unsignedBigInteger('requested_by');

            // The four gates of §6.10.5, stamped as each one is passed rather than inferred later.
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('password_confirmed_at')->nullable();
            $table->boolean('checksum_verified')->default(false);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            // `{table: count}` over §6.10.4's proof list — table names and integers only, so the
            // column can be rendered as a diff table without escaping anything user-supplied.
            $table->json('row_counts_before')->nullable();
            $table->json('row_counts_after')->nullable();

            // The financial figure, broken out of the JSON on purpose — see the class note.
            $table->unsignedBigInteger('ledger_rows_before')->nullable();
            $table->unsignedBigInteger('ledger_rows_after')->nullable();

            // `{constraints: pass/fail, reconciliation: {checked, drift, failed}, migrations_pending: n}`
            // — the output of step 10, stored so the verdict survives the terminal it was printed in.
            $table->json('proof')->nullable();

            $table->string('error_class', 191)->nullable();
            $table->text('error_message')->nullable();
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            // No `deleted_at` — see the class note (D19).

            /*
            | Indexes before foreign keys, deliberately. See the note on the column declarations:
            | this ordering is what stops MariaDB from adding a second, auto-named index over
            | `backup_run_id`. They are re-asserted idempotently in constraints() for the case
            | where this table already existed and the CREATE above was skipped (D70).
            */
            $table->index(['backup_run_id'], 'idx_brs_backup');
            $table->index(['status', 'created_at'], 'idx_brs_status_created');
            $table->index(['target', 'created_at'], 'idx_brs_target');

            // What was restored. RESTRICT: `backup_runs` never deletes anyway, so this is the
            // second lock on the same door.
            $table->foreign('backup_run_id', RawSchema::foreignKeyName(self::TABLE, 'backup_run_id'))
                ->references('id')->on('backup_runs')->restrictOnDelete();

            // The safety copy taken first. Null only for `target = local` (RestoreTarget).
            $table->foreign('pre_restore_backup_run_id', RawSchema::foreignKeyName(self::TABLE, 'pre_restore_backup_run_id'))
                ->references('id')->on('backup_runs')->restrictOnDelete();

            // The actor, and the one foreign key here that is not to `backup_runs`. RESTRICT rather
            // than nullOnDelete: `users` soft-deletes, so this bites only on a hard delete — which
            // is precisely the operation that would otherwise erase who overwrote production.
            $table->foreign('requested_by', RawSchema::foreignKeyName(self::TABLE, 'requested_by'))
                ->references('id')->on('users')->restrictOnDelete();

            // Blameable. Nullable and nullOnDelete, unlike `requested_by`: these two are bookkeeping
            // about the row, and the answer to "who did this" is `requested_by`.
            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        // A finished restore must have started.
        if (! RawSchema::checkExists('chk_brs_window')) {
            RawSchema::check(
                self::TABLE,
                'chk_brs_window',
                '`finished_at` IS NULL OR `started_at` IS NOT NULL',
            );
        }

        // The safety copy is never the archive being restored. It is taken *after* that archive was
        // chosen, so the two can only coincide through a bug — and the row it would produce reads
        // as "the way back is the thing we replaced it with".
        if (! RawSchema::checkExists('chk_brs_distinct_backups')) {
            RawSchema::check(
                self::TABLE,
                'chk_brs_distinct_backups',
                '`pre_restore_backup_run_id` IS NULL OR `pre_restore_backup_run_id` <> `backup_run_id`',
            );
        }

        // NOT NULL is not enough: an empty string satisfies it, and "no restore without a written
        // reason" (§6.10.5 gate 4) has to survive a caller that bypassed the Form Request. The
        // `min:20` judgement stays in validation, where it can be argued with; "not blank" does not
        // need arguing.
        if (! RawSchema::checkExists('chk_brs_reason')) {
            RawSchema::check(
                self::TABLE,
                'chk_brs_reason',
                'CHAR_LENGTH(TRIM(`reason`)) > 0',
            );
        }

        /*
        | Deliberately absent, because it is the first constraint a reader will reach for:
        | `target <> 'local' IMPLIES pre_restore_backup_run_id IS NOT NULL`. The row is written
        | `requested` at step 1 and the pre-restore backup is taken at step 5, so that CHECK would
        | reject the insert that starts the very procedure it is trying to enforce. The rule lives
        | in RestoreTarget::requiresPreBackup() and is enforced by BackupRestoreService before it
        | moves the row to `running`.
        */

        if (! RawSchema::indexExists(self::TABLE, 'uq_brs_uuid', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_brs_uuid', ['uuid']);
        }

        // "Has this archive been restored, and when?" — from the backup detail screen.
        if (! RawSchema::indexExists(self::TABLE, 'idx_brs_backup')) {
            RawSchema::index(self::TABLE, 'idx_brs_backup', ['backup_run_id']);
        }

        // The restore register, newest first, filtered by outcome.
        if (! RawSchema::indexExists(self::TABLE, 'idx_brs_status_created')) {
            RawSchema::index(self::TABLE, 'idx_brs_status_created', ['status', 'created_at']);
        }

        // "Every production restore, ever" — the question an audit asks first.
        if (! RawSchema::indexExists(self::TABLE, 'idx_brs_target')) {
            RawSchema::index(self::TABLE, 'idx_brs_target', ['target', 'created_at']);
        }

        if (! RawSchema::triggerExists(self::TRIGGER)) {
            RawSchema::noDeleteTrigger(
                self::TABLE,
                self::TRIGGER,
                'backup_restores is append-only (D19). A restore is the most consequential act an '
                .'operator can perform and its record must outlive the operator.',
            );
        }
    }

    public function down(): void
    {
        // The trigger first: MariaDB will not drop a table out from under one.
        if (RawSchema::triggerExists(self::TRIGGER)) {
            RawSchema::dropTrigger(self::TRIGGER);
        }

        // This runs before `backup_runs` rolls back, which is the order that works: the RESTRICT
        // keys point that way.
        Schema::dropIfExists(self::TABLE);
    }
};
