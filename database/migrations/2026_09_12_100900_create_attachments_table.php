<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 · §2.9 — attachments: §22's task attachments and §96's "files may belong to projects, tasks,
 * clients, employees…".
 *
 * **[D-P6-6] One polymorphic table**, created here because Phase 6 is the first phase that needs it and
 * governed by Phase 1's existing `files` module slug — there is no `files` table (CLAUDE.md §3). Later
 * phases attach to this table instead of creating a second file store; Phase 22 extends the morph map for
 * tickets, meetings and messages rather than building its own.
 *
 * `attachable_type` holds a **morph map alias**, never a raw FQCN, so a class can be moved without
 * rewriting rows. Phase 6 registers six aliases — `project`, `task`, `task_comment`, `project_milestone`,
 * `collaborator` and `invoice`; the last two are the §96 subjects that had no file store at all (F-13.2)
 * and stay unreachable until phases 8 and 13 ship those models, which must read as a 404, never a 500.
 *
 * `disk` defaults to the **private** disk (D21): no attachment is ever written to the `public` disk, and
 * the only way to read one is the permission-checked download controller of §7, which re-runs the chain
 * and writes an activity row (§60). `path` is a hashed `{ulid}.{ext}` name — the user's filename lives in
 * `original_name` and is used only for the download header. `mime_type` comes from the sniffed content,
 * never from the request header.
 *
 * Soft delete keeps the audit row; the blob itself is removed by a queued job only after 30 days.
 */
return new class extends Migration
{
    private const TABLE = 'attachments';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->string('attachable_type', 191);
                $table->unsignedBigInteger('attachable_id');
                $table->string('disk', 32)->default('local');
                $table->string('path', 255);
                $table->string('original_name', 255);
                $table->string('mime_type', 127);
                $table->string('extension', 16);
                $table->unsignedBigInteger('size_bytes');
                $table->char('checksum_sha256', 64)->nullable();
                $table->string('visibility', 16)->default('team');
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('uploaded_by_name', 150);
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.9 Keys.
                $table->index(['attachable_type', 'attachable_id']);
                $table->index('uploaded_by');
                $table->index('checksum_sha256');
                $table->index('visibility');
            });
        }

        $this->addCheck('chk_att_size', '`size_bytes` > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function addCheck(string $name, string $expression): void
    {
        if (! Schema::hasTable(self::TABLE) || $this->hasCheck($name)) {
            return;
        }

        DB::statement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)', self::TABLE, $name, $expression));
    }

    private function hasCheck(string $name): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.CHECK_CONSTRAINTS'
            .' WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [DB::getDatabaseName(), self::TABLE, $name]
        ) !== [];
    }
};
