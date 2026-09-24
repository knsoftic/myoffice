<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 23 · file 1 — `report_exports`, the phase's one table (phase-19-23 §2.26, §99).
 *
 * **A row here is an artefact, not a business record**, which is why it carries no soft delete and
 * no `created_by`/`updated_by`: `requested_by` *is* the actor, and there is no second person who
 * edits somebody else's export. A blameable pair on a row nobody ever edits is two columns that
 * always agree with a third.
 *
 * **`uuid` is the only id that ever reaches a URL.** A sequential id in a download link is an
 * invitation to try the one before it, and the download route's first check is that the row belongs
 * to the person asking — but a guessable id means that check is load-bearing rather than a
 * backstop.
 *
 * **`filters` stores the request verbatim**, so a file can be explained months later and rebuilt
 * exactly. An export nobody can reproduce is a number somebody has to take on trust.
 *
 * **`error_message` holds the real exception text**, shown to the requester. A queued job that
 * failed silently is a person refreshing a page for ten minutes; "it did not build, here is what
 * the database said" is a person who can act.
 *
 * **`expires_at` removes the file and never the row** (`ExportStatus::Expired`). Somebody who
 * bookmarked a link is told the file has gone rather than that it never existed, and the record of
 * what left the building survives the bytes.
 *
 * **D70: MariaDB DDL is not transactional.** The CREATE is guarded; the CHECK and the indexes are
 * ensured on every run, so a half-applied migration heals rather than jams.
 */
return new class extends Migration
{
    private const TABLE = 'report_exports';

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

            // The only id a URL ever carries.
            $table->char('uuid', 36);

            // Re-authorised at download: the row remembers which report it was, and the download
            // asks that report's own permissions again rather than trusting the row.
            $table->string('report_key', 64);
            $table->string('format', 16);

            // The exact ReportRequest payload. Verbatim, so the file can be explained and rebuilt.
            $table->json('filters');

            // Denormalised out of `filters` purely so the index below can be used — the list screen
            // filters on a date range and JSON cannot be indexed usefully for that.
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();

            // `restrictOnDelete`: a user with exports cannot be hard-deleted out from under them,
            // because the file on disk is theirs and the row is the only record of who asked.
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();

            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('row_count')->nullable();

            $table->string('storage_disk', 32)->default('private');
            $table->string('file_path', 255)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();

            // Written when the file is closed, checked when it is served: a file that changed on
            // disk between the two is not the file that was built.
            $table->char('checksum_sha256', 64)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // `completed_at + reports.export_retention_days`. datetime rather than timestamp: a
            // retention long enough to cross 2038 is a configuration mistake, not a crash.
            $table->dateTime('expires_at')->nullable();

            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();

            $table->string('error_class', 180)->nullable();
            $table->string('error_message', 500)->nullable();

            $table->timestamps();

            // No `deleted_at` and no blameable — see the class note.
        });
    }

    private function constraints(): void
    {
        if (! RawSchema::checkExists('chk_rx_counts')) {
            RawSchema::check(
                self::TABLE,
                'chk_rx_counts',
                '(`row_count` IS NULL OR `row_count` >= 0) AND `download_count` >= 0',
            );
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_rx_uuid', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_rx_uuid', ['uuid']);
        }

        // "My exports, newest first" — the only list screen this table has.
        if (! RawSchema::indexExists(self::TABLE, 'idx_rx_requester')) {
            RawSchema::index(self::TABLE, 'idx_rx_requester', ['requested_by', 'created_at']);
        }

        // The prune sweep: completed rows whose expiry has passed.
        if (! RawSchema::indexExists(self::TABLE, 'idx_rx_expiry')) {
            RawSchema::index(self::TABLE, 'idx_rx_expiry', ['status', 'expires_at']);
        }

        // "How often is this report exported?" — §99's own usage question.
        if (! RawSchema::indexExists(self::TABLE, 'idx_rx_report')) {
            RawSchema::index(self::TABLE, 'idx_rx_report', ['report_key', 'created_at']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
