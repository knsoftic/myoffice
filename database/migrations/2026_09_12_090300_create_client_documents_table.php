<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 · §2.9 — client_documents: contracts, NDAs, proposals and the other papers of a client (§19).
 *
 * [D-P5-10] / D21: files live on the **private** `local` disk under `clients/{client_id}/documents/` with a
 * hashed name (`path`), and are reachable only through a policy-checked streaming route. `disk` is stored so a
 * later move to S3 does not invalidate old rows. `visible_to_client` is **the only gate** that exposes a file to
 * the client panel and has no effect on staff; its DB default is `false`, the default of
 * `crm.client_visible_documents_default` — `ClientDocumentService::upload()` always writes the resolved value.
 *
 * `mime_type` is sniffed from content (`finfo`), never taken from the request. CHECK `chk_cd_size` refuses an
 * empty file at the database. `expires_at` drives a badge and a filter only.
 *
 * A specialised file store named by CLAUDE.md §3 (not `attachments`) → mutable document table → timestamps +
 * softDeletes + blameable. A soft delete keeps the file on disk so a restore works (§6.8).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_documents')) {
            Schema::create('client_documents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->string('title', 150);
                $table->string('category', 32);
                $table->string('description', 255)->nullable();
                $table->string('disk', 32)->default('local');
                $table->string('path', 255);
                $table->string('original_name', 255);
                $table->string('mime_type', 128);
                $table->string('extension', 16);
                $table->unsignedBigInteger('size_bytes');
                $table->string('checksum', 64)->nullable();
                $table->boolean('visible_to_client')->default(false);
                $table->timestamp('shared_at')->nullable();
                $table->foreignId('shared_by')->nullable()->constrained('users')->nullOnDelete();
                $table->date('valid_from')->nullable();
                $table->date('expires_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.9 Keys. (client_id, ...) also serves client_id's foreign key.
                $table->index(['client_id', 'category']);
                $table->index(['client_id', 'visible_to_client']);
                $table->index('checksum');
                $table->index('expires_at');
                $table->index('shared_by');
            });
        }

        $this->addCheck('client_documents', 'chk_cd_size', '`size_bytes` > 0');
    }

    public function down(): void
    {
        // Nothing references client_documents. DROP TABLE removes its four foreign keys and chk_cd_size.
        // Stored files are not touched by a schema rollback.
        Schema::dropIfExists('client_documents');
    }

    /**
     * Add a named CHECK constraint if MariaDB does not already carry it (idempotent re-run).
     */
    private function addCheck(string $table, string $name, string $expression): void
    {
        if (! Schema::hasTable($table) || $this->hasCheck($table, $name)) {
            return;
        }

        DB::statement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)', $table, $name, $expression));
    }

    private function hasCheck(string $table, string $name): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.CHECK_CONSTRAINTS'
            .' WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [DB::getDatabaseName(), $table, $name]
        ) !== [];
    }
};
