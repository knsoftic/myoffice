<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.6 — employee_documents: requirement §24's documents, with expiry tracking.
 *
 * **The private disk, always** (D21). `file_path` is a hashed name under `employees/{id}/documents/` on
 * the `local` disk; nothing here is web-reachable and the only way out is a permission-checked download
 * that writes an activity row.
 *
 * `is_confidential` defaults to **true**: a CNIC, a medical report or a police verification is not
 * something the whole office should be able to open, so the closed state is the default and opening one
 * is the deliberate act.
 *
 * `expiry_notified_at` is what stops the reminder sweep from mailing the same expiring contract every
 * morning — a reminder nobody can silence is a reminder everybody learns to ignore.
 *
 * Soft delete keeps the stored file so a restore is lossless; only a force delete removes the bytes.
 */
return new class extends Migration
{
    private const TABLE = 'employee_documents';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('document_type', 32);
            $table->string('title', 150);
            $table->string('document_number', 64)->nullable();
            $table->string('file_path', 255);
            $table->string('file_name', 255);
            $table->string('mime_type', 128);
            $table->unsignedInteger('size_kb')->default(0);
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->unsignedSmallInteger('reminder_days')->nullable();
            $table->boolean('is_confidential')->default(true);
            $table->string('verification_status', 16)->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expiry_notified_at')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.6 Keys.
            $table->index(['employee_id', 'document_type']);
            $table->index(['expires_on', 'expiry_notified_at']);
            $table->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
