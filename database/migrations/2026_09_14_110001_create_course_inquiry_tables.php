<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 · file 1 — `course_inquiries` and `course_inquiry_follow_ups` (§86, §68, phase-14-17 §2.11–§2.12).
 *
 * **The institute's funnel head, and it is not a CRM lead.** A software-house enquiry is a `leads` row
 * (§17); a course enquiry is this. They look alike and behave differently: a lead is worked by a
 * pipeline stage and a value, an enquiry is worked by follow-up calls against a date. One table for
 * both would mean every query in either module carrying a `type` filter it could forget.
 *
 * **Three provenance columns that are deliberately not one.** `contact_inquiry_id` says this enquiry
 * came from Phase 4's public contact form and is guarded by `uq_ci_inquiry`, so a replayed or re-run
 * router can never make a second enquiry from one submission (F-3.8). `idempotency_key` says this
 * enquiry came from *this* phase's own form and guards a double-submitted POST. `source` says how the
 * person found us, which is a marketing fact and not a guard at all. Collapsing any two of them loses a
 * different guarantee.
 *
 * **The referral columns are a snapshot and nothing else (INV-I3).** An enquiry has no subject row a
 * `collaborator_referrals` record could point at — the spine's CHECK allows student, project, client and
 * lead, and an enquiry is none of them — so the code is captured verbatim, resolved once for display,
 * and superseded the moment conversion creates a student and `ReferralService::attach()` writes the real
 * attribution. Nothing financial is ever decided from these three columns.
 *
 * **`last_contacted_at` and `contact_attempts` are CACHES** of the follow-up table, rewritten by
 * `CourseInquiryService::logFollowUp()`. The follow-up rows are the record; these two exist so the queue
 * can sort by them without a correlated subquery per row.
 *
 * `batch_id` carries no FK here: `batches` ships in Phase 16, and the guarded migration attaches it then
 * ([D-IN-1]).
 */
return new class extends Migration
{
    private const TABLE = 'course_inquiries';

    private const FOLLOW_UPS = 'course_inquiry_follow_ups';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->createInquiries();
        }

        if (! Schema::hasTable(self::FOLLOW_UPS)) {
            $this->createFollowUps();
        }

        $this->constraints();
    }

    private function createInquiries(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->string('inquiry_number', 32);
            $table->unsignedBigInteger('branch_id')->nullable();

            $table->string('name', 150);
            $table->string('phone', 32);
            $table->string('whatsapp', 32)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('city', 100)->nullable();
            // Free text on purpose: §66 and §67 take qualifications as the applicant words them, and a
            // select would force "BSc (Hons) Computer Science, 3rd semester" into "Bachelors".
            $table->string('education', 150)->nullable();

            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('preferred_delivery_mode', 16)->nullable();
            $table->string('preferred_timing', 16)->nullable();

            // Phase 4's InquirySource, eleven cases, declared once (F-5.3). §86's eight channels are a
            // subset of those values as identical strings, so there is nothing to map and nothing to lose.
            $table->string('source', 24)->default('website');
            $table->string('source_url', 500)->nullable();
            $table->unsignedBigInteger('contact_inquiry_id')->nullable();

            $table->string('status', 24)->default('new');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->unsignedSmallInteger('contact_attempts')->default(0);

            $table->text('message')->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('collaborator_id')->nullable();
            $table->string('referral_code', 32)->nullable();
            $table->boolean('referral_code_valid')->default(false);
            $table->unsignedBigInteger('referral_visit_id')->nullable();

            $table->string('lost_reason', 255)->nullable();

            $table->unsignedBigInteger('converted_application_id')->nullable();
            $table->unsignedBigInteger('converted_student_id')->nullable();
            $table->timestamp('converted_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('idempotency_key', 64)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('inquiry_number', 'uq_ci_number');
            // MariaDB lets NULLs stack in a unique index, which is the whole point of both of these: a
            // walk-in typed in by a receptionist has neither key, and any number of them coexist.
            $table->unique('idempotency_key', 'uq_ci_idem');
            $table->unique('contact_inquiry_id', 'uq_ci_inquiry');

            $table->index(['status', 'follow_up_date'], 'idx_ci_queue');
            $table->index(['assigned_to', 'status'], 'idx_ci_assignee');
            $table->index(['course_id', 'status'], 'idx_ci_course');
            $table->index(['source', 'created_at'], 'idx_ci_source');
            $table->index('phone', 'idx_ci_phone');
            $table->index(['branch_id', 'status'], 'idx_ci_branch');
            $table->index('collaborator_id', 'idx_ci_collaborator');
            $table->index('referral_visit_id', 'idx_ci_visit');
            $table->index('converted_application_id', 'idx_ci_application');
            $table->index('converted_student_id', 'idx_ci_student');
            $table->index('batch_id', 'idx_ci_batch');

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName(self::TABLE, 'course_id'))
                ->references('id')->on('courses')->nullOnDelete();
            $table->foreign('assigned_to', RawSchema::foreignKeyName(self::TABLE, 'assigned_to'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('collaborator_id', RawSchema::foreignKeyName(self::TABLE, 'collaborator_id'))
                ->references('id')->on('collaborators')->nullOnDelete();

            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Append-only ([D-IN-2]): no `deleted_at`. A contact attempt either happened or it did not, and a
     * row that can be hidden turns "we called four times" into a number nobody can stand behind.
     */
    private function createFollowUps(): void
    {
        Schema::create(self::FOLLOW_UPS, function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('course_inquiry_id');
            $table->string('channel', 16);
            $table->string('outcome', 24);
            $table->timestamp('contacted_at');
            $table->string('notes', 1000)->nullable();
            $table->date('next_follow_up_at')->nullable();

            $table->string('status_before', 24)->nullable();
            $table->string('status_after', 24)->nullable();

            $table->unsignedBigInteger('user_id')->nullable();
            // Snapshotted beside the id: a counsellor who leaves takes their `users` row's name with
            // them through `nullOnDelete`, and "called by (deleted user)" is not a contact log.
            $table->string('user_name', 150)->nullable();

            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['course_inquiry_id', 'contacted_at'], 'idx_cif_inquiry');
            $table->index(['user_id', 'contacted_at'], 'idx_cif_user');
            $table->index('next_follow_up_at', 'idx_cif_next');

            $table->foreign('course_inquiry_id', RawSchema::foreignKeyName(self::FOLLOW_UPS, 'course_inquiry_id'))
                ->references('id')->on(self::TABLE)->cascadeOnDelete();
            $table->foreign('user_id', RawSchema::foreignKeyName(self::FOLLOW_UPS, 'user_id'))
                ->references('id')->on('users')->nullOnDelete();

            $table->foreign('created_by', RawSchema::foreignKeyName(self::FOLLOW_UPS, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::FOLLOW_UPS, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        $this->ensure(self::TABLE, 'chk_ci_status',
            "`status` IN ('new', 'contacted', 'interested', 'demo_scheduled', 'admission_confirmed', 'not_interested')");
        $this->ensure(self::TABLE, 'chk_ci_mode',
            "`preferred_delivery_mode` IS NULL OR `preferred_delivery_mode` IN ('physical', 'online', 'hybrid')");
        $this->ensure(self::TABLE, 'chk_ci_timing',
            "`preferred_timing` IS NULL OR `preferred_timing` IN ('morning', 'afternoon', 'evening', 'night', 'weekend', 'flexible')");
        // §2.30.2 makes the reason mandatory in the service; the CHECK makes it true of the data, so a
        // row written by an import or a console script cannot be a loss with no explanation.
        $this->ensure(self::TABLE, 'chk_ci_lost_reason',
            "`status` <> 'not_interested' OR (`lost_reason` IS NOT NULL AND `lost_reason` <> '')");

        $this->ensure(self::FOLLOW_UPS, 'chk_cif_channel',
            "`channel` IN ('call', 'whatsapp', 'sms', 'email', 'in_person', 'other')");
        $this->ensure(self::FOLLOW_UPS, 'chk_cif_outcome',
            "`outcome` IN ('reached', 'no_answer', 'busy', 'wrong_number', 'interested', 'not_interested',"
            ." 'demo_requested', 'admission_requested', 'call_later')");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::FOLLOW_UPS);
        Schema::dropIfExists(self::TABLE);
    }

    private function ensure(string $table, string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check($table, $name, $expression);
        }
    }
};
