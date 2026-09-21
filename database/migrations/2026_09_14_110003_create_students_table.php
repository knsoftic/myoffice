<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 · file 3 — `students`: every field of §66 (phase-14-17 §2.14).
 *
 * **A student exists before a login does (D2).** `user_id` is nullable and unique: the institute
 * enrols people who have no email address, and the panel account is created later — at activation,
 * when `institute.auto_create_student_login` says so. A schema that required a `users` row would make
 * a login the price of admission.
 *
 * **Two numbers, issued at two different moments, and only one of them is guaranteed.**
 * `student_code` is stamped at creation and every student has one. `registration_number` is stamped at
 * §68's registration stage and is null until then, which is why its unique index has to tolerate NULLs
 * — dozens of unregistered students coexist, and MariaDB lets their NULLs stack.
 *
 * **[D-IN-8] There is no `current_batch_id` or `current_course_id`.** §63 expects a student to take
 * several short courses, sometimes at once, so a single "current" column would be wrong for exactly the
 * students the institute cares most about. Screens read `activeEnrollments` — one indexed query — and
 * render a chip per batch.
 *
 * **The four referral columns are §37's display snapshot, written only by Phase 9's
 * `SyncReferralSnapshot` (INV-I3, INV-R1).** The authority is the `collaborator_referrals` row; these
 * exist so a student list can show "referred by Ahmed Traders" without joining four tables, and
 * nothing financial reads them.
 *
 * **Nothing here can be hard-deleted once it has history.** `student_fees`, `student_fee_payments`,
 * `student_batch_enrollments` and `student_attendances` all point at this table with `restrictOnDelete`
 * (attached by the later guarded migrations), so the database refuses before the policy has to explain.
 */
return new class extends Migration
{
    private const TABLE = 'students';

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

            $table->string('student_code', 32);
            $table->string('registration_number', 40)->nullable();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();

            $table->string('name', 150);
            $table->string('father_name', 150)->nullable();
            $table->string('gender', 16)->nullable();
            $table->date('date_of_birth')->nullable();
            // Digits only in the column, formatted on the way out: a CNIC written with dashes by one
            // receptionist and without by another is the same person, and a duplicate check that
            // compared the typed form would miss them.
            $table->string('cnic', 24)->nullable();

            $table->string('phone', 32);
            $table->string('whatsapp', 32)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('photo_path', 255)->nullable();

            $table->string('guardian_name', 150)->nullable();
            $table->string('guardian_phone', 32)->nullable();
            $table->string('guardian_relation', 40)->nullable();

            $table->string('education', 150)->nullable();
            $table->string('institution_name', 180)->nullable();
            $table->date('joining_date')->nullable();

            $table->string('status', 16)->default('inquiry');
            $table->timestamp('status_changed_at')->nullable();
            $table->string('status_reason', 255)->nullable();

            $table->unsignedBigInteger('collaborator_id')->nullable();
            $table->string('referral_code', 32)->nullable();
            $table->string('referral_source', 24)->nullable();
            $table->date('referral_date')->nullable();
            $table->unsignedBigInteger('referral_visit_id')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('student_code', 'uq_st_code');
            $table->unique('registration_number', 'uq_st_regno');
            $table->unique('user_id', 'uq_st_user');

            $table->index(['status', 'created_at'], 'idx_st_status');
            $table->index(['branch_id', 'status'], 'idx_st_branch');
            $table->index(['collaborator_id', 'status'], 'idx_st_collaborator');
            $table->index('phone', 'idx_st_phone');
            $table->index('cnic', 'idx_st_cnic');
            $table->index('name', 'idx_st_name');
            $table->index('joining_date', 'idx_st_joining');
            $table->index('referral_visit_id', 'idx_st_visit');

            $table->foreign('user_id', RawSchema::foreignKeyName(self::TABLE, 'user_id'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            $table->foreign('collaborator_id', RawSchema::foreignKeyName(self::TABLE, 'collaborator_id'))
                ->references('id')->on('collaborators')->nullOnDelete();

            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_st_status',
            "`status` IN ('inquiry', 'applied', 'registered', 'active', 'completed', 'dropped', 'suspended')");
        $this->ensure('chk_st_gender',
            "`gender` IS NULL OR `gender` IN ('male', 'female', 'other')");
        // §2.30.4 makes the reason mandatory in the service for the two statuses somebody has to answer
        // for. The CHECK makes it true of every row, whatever wrote it.
        $this->ensure('chk_st_status_reason',
            "`status` NOT IN ('suspended', 'dropped') OR (`status_reason` IS NOT NULL AND `status_reason` <> '')");
        $this->ensure('chk_st_cnic_digits',
            "`cnic` IS NULL OR `cnic` REGEXP '^[0-9]+$'");
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
