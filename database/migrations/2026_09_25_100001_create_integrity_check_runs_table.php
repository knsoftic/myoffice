<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 · file 1 — `integrity_check_runs`, the record that a proof was run (phase-24-25 §2.3, §110).
 *
 * **HD-3: nothing is proven by a screenshot.** Every suite this system can run against itself leaves
 * a row here, so "is the money intact" has an answer with a date on it rather than somebody's
 * recollection of a green terminal. That is also why `command` stores the exact command line: a
 * finding nobody can reproduce is a finding nobody can fix.
 *
 * **Append-only, and the trigger says so** (D19, "audit, log and run history"). The retention sweep
 * deletes nothing — `ops:prune-integrity-runs` nulls `findings` on old rows and sets
 * `findings_truncated`, keeping the counts and the verdict for ever. A failed run keeps its findings
 * for three years, because the one row somebody will come back to is the one that went wrong.
 *
 * **`scope` is a string, deliberately not a polymorphic edge** (§2.4). A reconciliation finding
 * about `collaborator:12` has to stay readable after that collaborator is soft-deleted, and a
 * `morphTo` would either break or quietly resolve to null.
 *
 * **`run_uuid` groups, `uuid` identifies.** One `integrity:verify --suite=all` writes nine rows that
 * share a `run_uuid`, so "what did last night's sweep say" is one indexed lookup rather than a
 * timestamp range somebody has to guess the width of.
 *
 * **D70: MariaDB DDL is not transactional.** The CREATE is guarded; the CHECK, the indexes and the
 * trigger are ensured on every run, so a half-applied migration heals rather than jams.
 */
return new class extends Migration
{
    private const TABLE = 'integrity_check_runs';

    private const TRIGGER = 'trg_icr_no_delete';

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

            // ULID rather than uuid4: 26 characters, lexically sortable, so a list of runs is in
            // time order without reaching for `created_at` in the index.
            $table->char('uuid', 26);
            $table->char('run_uuid', 26);

            $table->string('suite', 32);
            $table->string('status', 16)->default('passed');

            // `all`, `collaborator:12`, `table:student_fees` — see the class note.
            $table->string('scope', 64)->nullable();

            $table->unsignedInteger('checks_total')->default(0);
            $table->unsignedInteger('checks_passed')->default(0);
            $table->unsignedInteger('checks_warned')->default(0);
            $table->unsignedInteger('checks_failed')->default(0);

            // Bounded at 200 entries by the service, because a suite that found nine thousand
            // problems has one problem, and storing all nine thousand helps nobody read it.
            $table->json('findings')->nullable();
            $table->boolean('findings_truncated')->default(false);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->string('triggered_by', 16)->default('scheduled');

            // The exact command line. A finding nobody can reproduce is a finding nobody can fix.
            $table->string('command', 128)->nullable();
            $table->tinyInteger('exit_code')->nullable();
            $table->string('app_version', 32)->nullable();

            $table->timestamps();

            // Blameable, and nullable on both: the scheduler has no actor, and a run must outlive
            // the operator who started it (§2.3).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // No `deleted_at` — see the class note.
        });
    }

    private function constraints(): void
    {
        // The four counters have to add up, or the row is reporting something that did not happen.
        // A suite that says it ran 40 checks and lists 3+2+1 is a suite whose own arithmetic is
        // wrong, and that is exactly the kind of thing an integrity table must not tolerate.
        if (! RawSchema::checkExists('chk_icr_counts')) {
            RawSchema::check(
                self::TABLE,
                'chk_icr_counts',
                '`checks_passed` + `checks_warned` + `checks_failed` = `checks_total`',
            );
        }

        if (! RawSchema::checkExists('chk_icr_exit')) {
            RawSchema::check(
                self::TABLE,
                'chk_icr_exit',
                '`exit_code` IS NULL OR `exit_code` BETWEEN 0 AND 2',
            );
        }

        // A finished run must have started. The other way round is legitimate — that is a run in
        // flight, or one the process died in the middle of.
        if (! RawSchema::checkExists('chk_icr_window')) {
            RawSchema::check(
                self::TABLE,
                'chk_icr_window',
                '`finished_at` IS NULL OR `started_at` IS NOT NULL',
            );
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_icr_uuid', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_icr_uuid', ['uuid']);
        }

        // "How has this suite been doing?" — the sparkline on the integrity screen.
        if (! RawSchema::indexExists(self::TABLE, 'idx_icr_suite_created')) {
            RawSchema::index(self::TABLE, 'idx_icr_suite_created', ['suite', 'created_at']);
        }

        // "What has gone wrong lately?" — the only question the screen opens on.
        if (! RawSchema::indexExists(self::TABLE, 'idx_icr_status_created')) {
            RawSchema::index(self::TABLE, 'idx_icr_status_created', ['status', 'created_at']);
        }

        // "What did last night's sweep say?" — one lookup rather than a guessed time range.
        if (! RawSchema::indexExists(self::TABLE, 'idx_icr_run')) {
            RawSchema::index(self::TABLE, 'idx_icr_run', ['run_uuid']);
        }

        // The retention sweep: rows old enough to have their findings nulled.
        if (! RawSchema::indexExists(self::TABLE, 'idx_icr_prune')) {
            RawSchema::index(self::TABLE, 'idx_icr_prune', ['findings_truncated', 'created_at']);
        }

        if (! RawSchema::triggerExists(self::TRIGGER)) {
            RawSchema::noDeleteTrigger(
                self::TABLE,
                self::TRIGGER,
                'integrity_check_runs is the record that a proof was run (D19). '
                .'Retention nulls findings; it never removes the verdict.',
            );
        }
    }

    public function down(): void
    {
        // The trigger first: MariaDB will not drop a table out from under one.
        if (RawSchema::triggerExists(self::TRIGGER)) {
            RawSchema::dropTrigger(self::TRIGGER);
        }

        Schema::dropIfExists(self::TABLE);
    }
};
