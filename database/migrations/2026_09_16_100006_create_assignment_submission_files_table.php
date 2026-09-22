<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19 · file 6 — `assignment_submission_files` (phase-19-23 §2.8).
 *
 * One of the five specialised file stores `CLAUDE.md` §3 names as exceptions to `attachments`, and it
 * earns the exception by carrying behaviour a generic table cannot: it belongs to an attempt rather than
 * to a model, it is served only through §6.4's chain, and it is one of two tables in the system where a
 * checksum collision is worth surfacing.
 *
 * **`checksum_sha256` flags a student handing in a classmate's identical file.** §2.8 is emphatic about
 * what that is: a **warning on the grading screen**, never an accusation and never a block. Two students
 * submitting the same provided template is the ordinary case, and a system that refused it would be
 * wrong far more often than right. The column exists so a human can look, not so the software can judge.
 *
 * **Append-only children: timestamps, no soft deletes, no blameable** (`CLAUDE.md` §3). `uploaded_by`
 * records who sent the bytes; the rest of the story is on the submission. `cascadeOnDelete` is right here
 * and nowhere near the submission itself — the parent is never deleted (§2.7), so the cascade is a
 * statement about integrity rather than a route anybody can reach.
 */
return new class extends Migration
{
    private const TABLE = 'assignment_submission_files';

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

            $table->unsignedBigInteger('assignment_submission_id');

            // The full server-decided set — the same seven column names `course_materials` uses, so
            // StoredFile maps onto both without a translation layer.
            $table->string('storage_disk', 32)->default('private');
            // ULID name under the §6.3 layout; never the client's name (INV-19-2).
            $table->string('file_path', 255);
            // Kept only for Content-Disposition, so a student's download is recognisable.
            $table->string('original_name', 255);
            // Server-decided from the sniffed MIME.
            $table->string('extension', 16);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('file_size_bytes');
            $table->char('checksum_sha256', 64)->nullable();

            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->unsignedInteger('download_count')->default(0);

            $table->timestamps();

            $table->index('assignment_submission_id', 'idx_asf_submission');
            // The duplicate-detection lookup: "has this exact file been handed in before".
            $table->index('checksum_sha256', 'idx_asf_checksum');
            $table->index('uploaded_by', 'idx_asf_uploader');

            $table->foreign('assignment_submission_id', RawSchema::foreignKeyName(self::TABLE, 'assignment_submission_id'))
                ->references('id')->on('assignment_submissions')->cascadeOnDelete();
            $table->foreign('uploaded_by', RawSchema::foreignKeyName(self::TABLE, 'uploaded_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        // A zero-byte submission file is a failed upload that reached the row stage.
        $this->ensure('chk_asf_size', '`file_size_bytes` > 0');
        $this->ensure('chk_asf_downloads', '`download_count` >= 0');
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
