<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 · §2.1 — collaborators: the partner record (requirements §33, §34).
 *
 * Separate from `users` per **D2**: a collaborator exists before, and sometimes without, a login — an
 * application arrives, a profile is built, and the account is provisioned at approval.
 *
 * **No `branch_id`.** A collaborator is not branch-bound, which is the one deliberate exception to D11
 * ([D-P8-2]): a referral partner introduces a student to whichever branch suits the student.
 *
 * **No CHECK constraints.** Every rule here — the code format, the reason on a suspension — is a Form
 * Request rule plus a model hook, because a regex CHECK would block the seeder and the import path
 * without adding a guarantee the service does not already give.
 */
return new class extends Migration
{
    private const TABLE = 'collaborators';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            // Immutable once issued (INV-C1): it is quoted in commission disputes.
            $table->string('collaborator_code', 32);
            // Initialised equal to the collaborator code; immutable once anything references it (INV-C2).
            $table->string('referral_code', 32);

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name', 150);
            $table->string('company_name', 150)->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('whatsapp', 32)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('address', 255)->nullable();

            $table->string('collaboration_type', 32);
            $table->date('joining_date')->nullable();

            $table->string('status', 32)->default('pending');
            $table->string('status_reason', 255)->nullable();
            // DATETIME, not TIMESTAMP: MariaDB's explicit_defaults_for_timestamp is OFF on this server, so
            // the first TIMESTAMP NOT NULL column of a table silently gains ON UPDATE CURRENT_TIMESTAMP
            // (D67). These are clock columns; a value that moved on its own would be a lie about when.
            $table->dateTime('status_changed_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('applied_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.1 Keys. Both codes are globally unique: the collaborator code because two concurrent
            // creations must not share it, and the referral code because it is the public lookup key —
            // a duplicate would make attribution ambiguous.
            $table->unique('collaborator_code', 'uq_col_code');
            $table->unique('referral_code', 'uq_col_referral_code');
            $table->unique('user_id', 'uq_col_user');
            $table->unique('email', 'uq_col_email');
            $table->index(['status', 'collaboration_type']);
            $table->index('joining_date');
            $table->index('company_name');
            $table->index('created_at');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
