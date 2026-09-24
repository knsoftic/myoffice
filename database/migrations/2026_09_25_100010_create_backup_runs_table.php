<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 25 · `backup_runs` — the register of every archive this system has ever written
 * (phase-24-25 §2.1, §6.10).
 *
 * **The row outlives the file, and that is the design.** Rule 4 of §6.10.3: retention deletes
 * archives, never rows — it stamps `file_pruned_at` and moves `status` to `pruned`. So "did we have
 * a backup of the night the wallet drifted" stays answerable years after the archive itself was
 * swept, with the checksum, the size and the proof counts still on the row. A table that deleted
 * its rows with its files would answer that question with silence, and silence reads as "no".
 *
 * **Append-only in the D19 sense** ("audit, log and run history"): no `deleted_at`, a model
 * `deleting` hook that throws, and `trg_br_no_delete` underneath it for the `->delete()` that
 * slipped past the model, the raw query and the database client. Lifecycle columns are UPDATEd —
 * that is what the model's whitelist in §2.1 is for — but a row is never removed.
 *
 * **`uq_br_location (disk, path)` is the guard against recording the same archive twice.** Two
 * `backup_runs` rows pointing at one file is how a prune deletes an archive that another row still
 * advertises as usable, and how the minimum-copies count of §6.10.3 rule 1 comes out one too high
 * on the day it matters. The pair is NULL-tolerant by design: a `pending` row has no path yet, and
 * MariaDB lets any number of those coexist — the guard bites at the moment the path is written,
 * which is the moment it means anything. (There is no `deleted_at` on this table, so the trap
 * CLAUDE.md §3 warns about — a NULL-tolerant unique guard plus a soft delete — cannot form here.)
 *
 * **D70: MariaDB DDL is not transactional.** The CREATE is guarded; the CHECKs, the indexes and the
 * trigger are ensured on every run, so a migration that died halfway heals on the next attempt
 * instead of jamming.
 */
return new class extends Migration
{
    private const TABLE = 'backup_runs';

    private const TRIGGER = 'trg_br_no_delete';

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

            // ULID, like every other run table here: 26 characters, lexically sortable, and safe to
            // quote in a notification or read aloud over the phone during a restore.
            $table->char('uuid', 26);

            $table->string('type', 16);
            $table->string('status', 16)->default('pending');

            // Reserved word in MariaDB, and quoted everywhere it is used — by Laravel's grammar in
            // queries, and by hand in the CHECK expressions below.
            $table->string('trigger', 16);

            $table->string('disk', 32)->default('backups');

            // Relative to `disk`. Nullable because the row is written `pending` before the archive
            // exists, which is what makes a crashed run leave a trace instead of nothing.
            $table->string('path', 512)->nullable();
            $table->string('filename', 255)->nullable();

            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('checksum_sha256', 64)->nullable();

            $table->boolean('is_encrypted')->default(false);

            // HD-8: only ever true when the archive is encrypted, and `chk_br_env_encrypted` below
            // is what makes that a fact rather than an intention.
            $table->boolean('includes_env')->default(false);

            $table->string('database_name', 64)->nullable();

            // The proof figures of §6.10.4, captured at dump time and compared again on restore.
            $table->unsignedInteger('table_count')->nullable();
            $table->unsignedBigInteger('row_count_total')->nullable();
            $table->unsignedInteger('file_count')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            // Class only, and a message the `RedactSensitive` scrubber has already been through: an
            // exception from the dump can carry a connection string, and this table is read by every
            // admin who can see the backups screen.
            $table->string('error_class', 191)->nullable();
            $table->text('error_message')->nullable();

            // `transient` / `daily` / `weekly` / `monthly` / `yearly`, decided at creation by
            // BackupRetentionService::classify(). Deliberately not an enum and deliberately not a
            // CHECK — see the note in constraints().
            $table->string('retention_class', 16)->default('daily');
            $table->date('retention_until')->nullable();

            // The file went, the row stayed. Null on every row that still has its archive.
            $table->timestamp('file_pruned_at')->nullable();
            $table->foreignId('pruned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('verification_status', 16)->default('unverified');
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();

            // The 3-2-1 copy. Null until `CopyBackupOffsite` has written it.
            $table->string('offsite_disk', 32)->nullable();
            $table->timestamp('offsite_copied_at')->nullable();

            // Mandatory for `trigger = manual`, enforced by the Form Request rather than a CHECK:
            // the row is written before the operator's input is on it in some paths, and a
            // constraint satisfied by a placeholder is how this column fills with the word "backup".
            $table->string('reason', 255)->nullable();

            $table->string('app_version', 32)->nullable();
            $table->string('php_version', 16)->nullable();
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            // Blameable, nullable on both: most runs are the scheduler's and have no actor, and a
            // backup must outlive the operator who took it.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // No `deleted_at` — see the class note (D19).
        });
    }

    private function constraints(): void
    {
        // HD-8, and the one constraint on this table that is about secrets rather than bookkeeping:
        // an unencrypted archive may never carry `.env`. The setting is `prohibited_unless`, the
        // service checks it, and this is the layer that holds when a row is written by something
        // that did neither — a seeder, a fixture, a console command written in a hurry.
        if (! RawSchema::checkExists('chk_br_env_encrypted')) {
            RawSchema::check(
                self::TABLE,
                'chk_br_env_encrypted',
                '`includes_env` = 0 OR `is_encrypted` = 1',
            );
        }

        // A finished run must have started. The reverse is legitimate: that is a run in flight, or
        // one whose process was killed between the two stamps.
        if (! RawSchema::checkExists('chk_br_window')) {
            RawSchema::check(
                self::TABLE,
                'chk_br_window',
                '`finished_at` IS NULL OR `started_at` IS NOT NULL',
            );
        }

        /*
        | Two constraints deliberately absent, because a later reader will think of both:
        |
        | `retention_class IN (...)` — the five classes are a policy parameter owned by
        | BackupRetentionService (§3), read against settings. Pinning them in DDL would turn a
        | policy change into a migration, which is the opposite of what §3 decided.
        |
        | `status = 'pruned' IMPLIES file_pruned_at IS NOT NULL` — true of every row the prune job
        | writes, but the job sets the two in one UPDATE and a constraint here would fire on any
        | legitimate two-statement ordering somebody writes later. The invariant lives in
        | BackupRetentionService, where the ordering is visible.
        */

        if (! RawSchema::indexExists(self::TABLE, 'uq_br_uuid', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_br_uuid', ['uuid']);
        }

        // The same archive is never recorded twice — see the class note. The key is
        // 32+512 utf8mb4 characters = 2176 bytes, inside InnoDB's 3072-byte DYNAMIC limit, so it
        // needs no prefix length.
        if (! RawSchema::indexExists(self::TABLE, 'uq_br_location', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_br_location', ['disk', 'path']);
        }

        // The register screen: "database backups, completed, newest first". `type` is the leftmost
        // column, so this also serves the plain `type` filter — a separate single-column index on
        // `type` would be dead weight, and that is why there is not one.
        if (! RawSchema::indexExists(self::TABLE, 'idx_br_type_status_created')) {
            RawSchema::index(self::TABLE, 'idx_br_type_status_created', ['type', 'status', 'created_at']);
        }

        // The prune job: rows whose retention has expired and whose file is still there.
        if (! RawSchema::indexExists(self::TABLE, 'idx_br_retention')) {
            RawSchema::index(self::TABLE, 'idx_br_retention', ['retention_until', 'file_pruned_at']);
        }

        // `backup:verify` and the go-live gate: "what has never reached restore_ok?"
        if (! RawSchema::indexExists(self::TABLE, 'idx_br_verification')) {
            RawSchema::index(self::TABLE, 'idx_br_verification', ['verification_status', 'verified_at']);
        }

        // The health screen's "when did we last back up?", which sorts on the attempt, not the row.
        if (! RawSchema::indexExists(self::TABLE, 'idx_br_started')) {
            RawSchema::index(self::TABLE, 'idx_br_started', ['started_at']);
        }

        if (! RawSchema::triggerExists(self::TRIGGER)) {
            RawSchema::noDeleteTrigger(
                self::TABLE,
                self::TRIGGER,
                'backup_runs is append-only (D19). Retention removes the archive and stamps '
                .'file_pruned_at; the record of the backup is kept for ever.',
            );
        }
    }

    public function down(): void
    {
        // The trigger first: MariaDB will not drop a table out from under one.
        //
        // `backup_restores` holds RESTRICT foreign keys into this table, so this rollback only
        // succeeds after that migration's own `down()` has run — which is the order the rollback
        // already uses, and the right order: a restore record whose backup row vanished would be
        // evidence of nothing.
        if (RawSchema::triggerExists(self::TRIGGER)) {
            RawSchema::dropTrigger(self::TRIGGER);
        }

        Schema::dropIfExists(self::TABLE);
    }
};
