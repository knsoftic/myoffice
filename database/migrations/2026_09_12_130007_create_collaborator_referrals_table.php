<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 07 — `collaborator_referrals`: who referred whom, and for which window (spine §2.8).
 *
 * **Versioned, not overwritten.** Changing a subject's collaborator **supersedes**: the old row stays
 * with a closed window and a forward pointer, so a ledger entry can always prove which attribution
 * caused it even after an admin switches partners. That is the difference between an audit trail and a
 * current-value column.
 *
 * **`referral_code` is a snapshot.** A partner who later moves onto a vanity code does not rewrite the
 * rows that named the old one — which is exactly why INV-C2 freezes a code once something references it.
 *
 * **`superseded_by_id` carries a plain, deliberately NON-unique index** (ND-12). One winner legitimately
 * supersedes several rows: `change()` supersedes the previously active referral, and
 * `recordLosingCandidate()` inserts one superseded row per losing candidate pointing at the **same**
 * winner. A unique index there would raise 1062 on the second of those perfectly legal writes and
 * destroy the evidence this table exists to keep. It is a navigation pointer, not a guarantee.
 *
 * **"At most one active referral per subject" lives entirely in the four `uq_cr_*_current` indexes**
 * (file 17) over the generated `current_guard` column (file 16). A superseded row has
 * `current_guard = NULL` and leaves the active slot free however many siblings it has.
 */
return new class extends Migration
{
    private const TABLE = 'collaborator_referrals';

    public function up(): void
    {
        // Guards the CREATE only. The constraints below are ensured on every run, so a table left
        // behind by a half-applied migration cannot end up looking complete without them.
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('collaborator_id');

            // Explicit beside the four nullable ids, for indexes and reports. `chk_cr_one_subject`
            // makes "exactly one of them" a database fact rather than a service convention.
            $table->string('subject_type', 16);
            $table->unsignedBigInteger('student_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();

            $table->string('commission_for', 16)->nullable();

            // SNAPSHOT of the code used. A later code change never rewrites history.
            $table->string('referral_code', 32);
            $table->string('referral_source', 32);
            $table->date('referral_date');

            // A payment with paid_on >= effective_from can credit this collaborator.
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // False stops FUTURE commission and keeps every past entry.
            $table->boolean('commission_eligible')->default(true);
            $table->string('status', 16)->default('active');

            $table->string('landing_url', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->unsignedBigInteger('referral_visit_id')->nullable();

            $table->unsignedBigInteger('previous_referral_id')->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->dateTime('superseded_at')->nullable();
            $table->string('change_reason', 255)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();

            $table->string('notes', 255)->nullable();

            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['collaborator_id', 'referral_date'], 'idx_cr_collaborator_date');
            $table->index('referral_code', 'idx_cr_code');
            $table->index(['subject_type', 'status'], 'idx_cr_subject_status');
            $table->index(['student_id', 'effective_from'], 'idx_cr_student_from');
            $table->index(['project_id', 'effective_from'], 'idx_cr_project_from');
            $table->index('client_id', 'idx_cr_client');
            $table->index('lead_id', 'idx_cr_lead');
            $table->index('referral_visit_id', 'idx_cr_visit');
            $table->index('previous_referral_id', 'idx_cr_previous');
            // Plain, NOT unique — see the class docblock.
            $table->index('superseded_by_id', 'idx_cr_superseded_by');
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_cr_one_subject',
            '(`student_id` IS NOT NULL) + (`project_id` IS NOT NULL) '
            .'+ (`client_id` IS NOT NULL) + (`lead_id` IS NOT NULL) = 1');

        $this->ensure('chk_cr_dates',
            '`effective_to` IS NULL OR `effective_to` >= `effective_from`');

        // A non-active referral always has a closed window, which is what makes date-based resolution
        // deterministic: without it a superseded row with an open window would still match a payment.
        $this->ensure('chk_cr_closed',
            "`status` = 'active' OR `effective_to` IS NOT NULL");
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
