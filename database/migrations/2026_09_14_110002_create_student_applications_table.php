<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 · file 2 — `student_applications`: §67's public admission form, landed as a reviewable
 * record (phase-14-17 §2.13).
 *
 * **[D-IN-7] The public form creates one row and nothing else.** No student, no `users` row (D2/D15),
 * no fee. A stranger on the internet filling in a form is a request, not an admission, and a system
 * that turned it into a student record would have a student directory anybody could write to. Staff
 * convert; `StudentApplicationService::convert()` is the one transaction that does it.
 *
 * **The same table takes a walk-in typed in by a receptionist**, so §68's "application" stage has
 * exactly one shape and the funnel report counts one kind of row.
 *
 * **`idempotency_key` blocks, `duplicate_fingerprint` flags — and the difference is the point.** The
 * key is one ULID per rendered form and is unique, so a double-tapped submit button is silently the
 * same application. The fingerprint is sha1(normalised phone + course + lowercased name) and is
 * deliberately **not** unique: the same person really does apply twice for the same course, six months
 * apart, and a database that refused the second one would refuse a real customer. It turns the row
 * amber and puts the two side by side for a human.
 *
 * **The referral evidence is captured here and attached at conversion ([D-IN-13]).** There is no
 * subject row yet for `collaborator_referrals` to point at, so the code, the resolver's verdict, the
 * visit id and the landing URL are written down and `convert()` turns them into the one authoritative
 * attribution through `ReferralService::attach()`. A `collaborator_id` posted by the browser is
 * discarded before it reaches this table (INV-I4).
 *
 * `batch_id` carries no FK: `batches` ships in Phase 16 ([D-IN-1]).
 */
return new class extends Migration
{
    private const TABLE = 'student_applications';

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

            $table->string('application_number', 32);
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('course_inquiry_id')->nullable();

            $table->string('name', 150);
            $table->string('father_name', 150)->nullable();
            $table->string('phone', 32);
            $table->string('whatsapp', 32)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('education', 150)->nullable();

            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('preferred_timing', 16)->nullable();
            $table->string('preferred_delivery_mode', 16)->nullable();
            $table->text('message')->nullable();

            $table->string('referral_code', 32)->nullable();
            $table->boolean('referral_code_valid')->default(false);
            $table->unsignedBigInteger('collaborator_id')->nullable();
            $table->string('referral_source', 24)->nullable();
            $table->unsignedBigInteger('referral_visit_id')->nullable();
            $table->string('landing_url', 500)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('idempotency_key', 64);
            $table->string('duplicate_fingerprint', 64);
            $table->unsignedBigInteger('duplicate_of_application_id')->nullable();

            $table->string('status', 24)->default('submitted');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_notes', 500)->nullable();
            $table->string('rejection_reason', 255)->nullable();

            $table->unsignedBigInteger('converted_student_id')->nullable();
            $table->unsignedBigInteger('converted_admission_id')->nullable();
            $table->timestamp('converted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('application_number', 'uq_sap_number');
            $table->unique('idempotency_key', 'uq_sap_idem');

            $table->index(['status', 'created_at'], 'idx_sap_inbox');
            $table->index('duplicate_fingerprint', 'idx_sap_fingerprint');
            $table->index('phone', 'idx_sap_phone');
            $table->index(['course_id', 'status'], 'idx_sap_course');
            $table->index('collaborator_id', 'idx_sap_collaborator');
            $table->index(['branch_id', 'status'], 'idx_sap_branch');
            $table->index('course_inquiry_id', 'idx_sap_inquiry');
            $table->index('batch_id', 'idx_sap_batch');
            $table->index('referral_visit_id', 'idx_sap_visit');
            $table->index('duplicate_of_application_id', 'idx_sap_duplicate_of');
            $table->index('reviewed_by', 'idx_sap_reviewer');
            $table->index('converted_student_id', 'idx_sap_student');
            $table->index('converted_admission_id', 'idx_sap_admission');

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            // restrictOnDelete: an application names the course somebody asked for. Deleting the course
            // would either erase what they asked for or leave a row that cannot say.
            $table->foreign('course_id', RawSchema::foreignKeyName(self::TABLE, 'course_id'))
                ->references('id')->on('courses')->restrictOnDelete();
            $table->foreign('course_inquiry_id', RawSchema::foreignKeyName(self::TABLE, 'course_inquiry_id'))
                ->references('id')->on('course_inquiries')->nullOnDelete();
            $table->foreign('duplicate_of_application_id', RawSchema::foreignKeyName(self::TABLE, 'duplicate_of_application_id'))
                ->references('id')->on(self::TABLE)->nullOnDelete();
            $table->foreign('collaborator_id', RawSchema::foreignKeyName(self::TABLE, 'collaborator_id'))
                ->references('id')->on('collaborators')->nullOnDelete();
            $table->foreign('reviewed_by', RawSchema::foreignKeyName(self::TABLE, 'reviewed_by'))
                ->references('id')->on('users')->nullOnDelete();

            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_sap_status',
            "`status` IN ('submitted', 'under_review', 'converted', 'rejected', 'duplicate', 'withdrawn')");
        $this->ensure('chk_sap_timing',
            "`preferred_timing` IS NULL OR `preferred_timing` IN ('morning', 'afternoon', 'evening', 'night', 'weekend', 'flexible')");
        $this->ensure('chk_sap_mode',
            "`preferred_delivery_mode` IS NULL OR `preferred_delivery_mode` IN ('physical', 'online', 'hybrid')");
        // §2.30.3 makes both mandatory in the service; here they are mandatory of the data.
        $this->ensure('chk_sap_rejection_reason',
            "`status` <> 'rejected' OR (`rejection_reason` IS NOT NULL AND `rejection_reason` <> '')");
        $this->ensure('chk_sap_duplicate_of',
            "`status` <> 'duplicate' OR `duplicate_of_application_id` IS NOT NULL");
        // "An application is not its own duplicate" is deliberately NOT a CHECK: MariaDB refuses any
        // CHECK that reads an AUTO_INCREMENT column (error 1901), so the constraint cannot exist here.
        // `StudentApplicationService::markDuplicate()` refuses it instead, and a test pins that — a
        // self-reference would render one row twice on the review screen and loop anything that walks
        // to the original.
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
