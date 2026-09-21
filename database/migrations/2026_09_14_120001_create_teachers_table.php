<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 · file 1 — `teachers` and the `course_teacher` pivot (§72, phase-14-17 §2.17–§2.18).
 *
 * **Two links to two different things, and D32 is why.** `employee_id` points at the *post* — teaching
 * a course is an organisational duty — while `user_id` points at whoever signs in and marks a
 * register. The bridge between them is `employees.user_id`, never a second link here, because two
 * paths to the same person is how they come to disagree.
 *
 * **[D-IN-9] An unlinked teacher is a first-class record.** Many institutes pay visiting trainers per
 * batch rather than a salary, and a schema that demanded an `employees` row would make every one of
 * them an HR record with a payroll line. When the link *is* set, the identity fields are kept in step
 * one-way by Phase 7's employee-updated event and the teacher row stays the read surface, so no
 * institute screen ever has to ask whether a teacher is staff.
 *
 * **`salary` is display only, and is NULL whenever `employee_id` is set.** Phase 7 §13 asks for
 * exactly that: a linked teacher's pay comes from their salary structure and slip, and one person with
 * two salary numbers is one number that is wrong. The column holds the agreed fee of an unlinked
 * trainer and is rendered only to holders of `teachers.view_financial`.
 *
 * `employee_id` carries no FK here — `employees` is Phase 7's and may or may not have migrated when
 * this runs, so the guarded migration attaches it ([D-IN-1]).
 */
return new class extends Migration
{
    private const TABLE = 'teachers';

    private const PIVOT = 'course_teacher';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->createTeachers();
        }

        if (! Schema::hasTable(self::PIVOT)) {
            $this->createPivot();
        }

        $this->constraints();
    }

    private function createTeachers(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->string('teacher_code', 32);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();

            $table->string('slug', 170)->nullable();
            $table->string('name', 150);
            $table->string('photo_path', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('whatsapp', 32)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('gender', 16)->nullable();

            $table->string('qualification', 255)->nullable();
            $table->unsignedTinyInteger('experience_years')->nullable();
            $table->string('experience_note', 255)->nullable();
            $table->json('skills')->nullable();
            $table->string('specialization', 255)->nullable();
            $table->text('bio')->nullable();
            $table->text('public_bio')->nullable();
            $table->json('social_links')->nullable();

            $table->date('joining_date')->nullable();
            // Display only, and never a payroll input. NULL whenever `employee_id` is set.
            $table->decimal('salary', 15, 2)->nullable();

            $table->string('status', 16)->default('active');
            $table->string('status_reason', 255)->nullable();

            $table->boolean('is_public')->default(false);
            $table->integer('sort_order')->default(0);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('teacher_code', 'uq_te_code');
            $table->unique('user_id', 'uq_te_user');
            // One employee is at most one teacher. NULLs stack, so every visiting trainer coexists.
            $table->unique('employee_id', 'uq_te_employee');
            $table->unique('slug', 'uq_te_slug');

            $table->index(['status', 'name'], 'idx_te_status');
            $table->index(['branch_id', 'status'], 'idx_te_branch');
            $table->index(['is_public', 'sort_order'], 'idx_te_public');

            $table->foreign('user_id', RawSchema::foreignKeyName(self::TABLE, 'user_id'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();

            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Append-only in the sense that matters ([D-IN-2]): no soft delete and no blameable. Detaching a
     * teacher from a course removes the row, because the row IS the statement "this person teaches
     * this" — a hidden one would leave a course claiming a trainer it does not have.
     */
    private function createPivot(): void
    {
        Schema::create(self::PIVOT, function (Blueprint $table): void {
            // An id rather than a composite primary key, so the row is addressable by a route.
            $table->id();

            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('teacher_id');
            $table->boolean('is_primary')->default(false);
            $table->date('assigned_on')->nullable();

            $table->timestamps();

            $table->unique(['course_id', 'teacher_id'], 'uq_cte');
            $table->index('teacher_id', 'idx_cte_teacher');

            $table->foreign('course_id', RawSchema::foreignKeyName(self::PIVOT, 'course_id'))
                ->references('id')->on('courses')->cascadeOnDelete();
            $table->foreign('teacher_id', RawSchema::foreignKeyName(self::PIVOT, 'teacher_id'))
                ->references('id')->on(self::TABLE)->cascadeOnDelete();
        });
    }

    private function constraints(): void
    {
        $this->ensure(self::TABLE, 'chk_te_salary', '`salary` IS NULL OR `salary` >= 0');
        $this->ensure(self::TABLE, 'chk_te_status',
            "`status` IN ('active', 'inactive', 'on_leave', 'resigned', 'suspended')");
        $this->ensure(self::TABLE, 'chk_te_gender',
            "`gender` IS NULL OR `gender` IN ('male', 'female', 'other')");
        // §2.30 makes the reason mandatory in the service for the two a person has to answer for.
        $this->ensure(self::TABLE, 'chk_te_status_reason',
            "`status` NOT IN ('suspended', 'resigned') OR (`status_reason` IS NOT NULL AND `status_reason` <> '')");
        // A public profile with no address is a page that cannot be linked to.
        $this->ensure(self::TABLE, 'chk_te_public_slug',
            "`is_public` = 0 OR (`slug` IS NOT NULL AND `slug` <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::PIVOT);
        Schema::dropIfExists(self::TABLE);
    }

    private function ensure(string $table, string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check($table, $name, $expression);
        }
    }
};
