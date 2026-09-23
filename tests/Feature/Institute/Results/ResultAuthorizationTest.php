<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Results;

use App\Models\Institute\ExamResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\Feature\Institute\Materials\Concerns\BuildsMaterials;
use Tests\Feature\Institute\Results\Concerns\BuildsExams;
use Tests\TestCase;

/**
 * Who may reach what, across three panels (phase-19-23 §4.2, §9, §11).
 *
 * **Isolation is a 404, not a 403.** A 403 confirms that the row exists, which turns an id into
 * something worth guessing — phase-14-17 §9, and it applies to every panel that takes an id.
 */
final class ResultAuthorizationTest extends TestCase
{
    use BuildsAdmissions;
    use BuildsCatalogue;
    use BuildsExams;
    use BuildsMaterials;
    use BuildsRegisters;
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Admin — the permission is required, and hiding a button is not security
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_staff_user_without_the_permission_is_refused_the_exam_screens(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $exam = $this->draftExam($batch, [], $staff);

        $nobody = $this->createUserWithPermissions([]);

        $this->actingAs($nobody)->get(route('admin.exams.index'))->assertForbidden();
        $this->actingAs($nobody)->get(route('admin.exams.show', $exam))->assertForbidden();
        $this->actingAs($nobody)->get(route('admin.exams.create'))->assertForbidden();
        $this->actingAs($nobody)->get(route('admin.grade-scales.index'))->assertForbidden();
        $this->actingAs($nobody)->get(route('admin.results.index'))->assertForbidden();
    }

    #[Test]
    public function viewing_exams_does_not_let_somebody_enter_marks(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $reader = $this->createUserWithPermissions(['exams.view_any', 'exams.view']);

        $this->actingAs($reader)->get(route('admin.exams.index'))->assertOk();
        $this->actingAs($reader)->get(route('admin.exam-results.sheet', $exam))->assertForbidden();
        $this->actingAs($reader)->post(route('admin.exam-results.save', $exam), ['rows' => []])->assertForbidden();
    }

    /**
     * §4.2 splits entering marks from signing them off and from publishing them. A marker who also
     * held `change_status` could enter a sheet and push it straight out.
     */
    #[Test]
    public function entering_marks_does_not_let_somebody_check_or_publish_them(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $marker = $this->createUserWithPermissions(['results.view_any', 'results.view', 'results.create']);

        $this->actingAs($marker)->get(route('admin.exam-results.sheet', $exam))->assertOk();
        $this->actingAs($marker)
            ->post(route('admin.exam-results.save', $exam), ['rows' => $this->sheetRows($seats, ['88', '35'])])
            ->assertRedirect();

        $this->actingAs($marker)->post(route('admin.exam-results.verify', $exam))->assertForbidden();
        $this->actingAs($marker)->post(route('admin.exam-results.publish', $exam))->assertForbidden();
    }

    /**
     * **`results.delete` is not a registered ability at all**, so no role can hold it, there is no
     * route to reach, and the model refuses the act besides. Phase 1 registered it through
     * `self::CRUD`; Phase 20 assembles the list by hand to leave it out.
     */
    #[Test]
    public function no_role_can_be_granted_the_right_to_delete_a_result(): void
    {
        $this->assertFalse(
            Permission::query()->where('name', 'results.delete')->exists(),
            'results.delete is seeded again — the ability list has drifted back to self::CRUD.',
        );
        $this->assertFalse(
            Permission::query()->where('name', 'results.restore')->exists(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Teacher — their own batches, through TeacherScope
    |--------------------------------------------------------------------------
    */

    /**
     * The teacher panel is governed by `teacher_portal.*`. Checking the office's `results.create`
     * here produced a 403 for the very teacher whose batch it was — the controller was asking the
     * wrong question, and this test is what asks it the right way round.
     */
    #[Test]
    public function a_teacher_reaches_the_marking_sheet_for_their_own_batch(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);
        $teacher = $this->teacherWithLogin($batch);

        $this->assertInstanceOf(User::class, $teacher);
        $this->assertFalse($teacher->can('results.create'), 'A teacher does not hold the office ability.');

        $this->actingAs($teacher)->get(route('teacher.results.sheet', $exam))->assertOk();
        $this->actingAs($teacher)->get(route('teacher.exams.show', $exam))->assertOk();
        $this->actingAs($teacher)->get(route('teacher.results.index'))->assertOk();
    }

    #[Test]
    public function another_teachers_exam_is_a_404_not_a_403(): void
    {
        $staff = $this->createSuperAdmin();
        $mine = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $theirs = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);

        $this->roster($theirs, 2, $staff);
        $theirExam = $this->conductedExam($theirs, [], $staff);
        $teacher = $this->teacherWithLogin($mine);

        $this->assertInstanceOf(User::class, $teacher);

        $this->actingAs($teacher)->get(route('teacher.exams.show', $theirExam))->assertNotFound();
        $this->actingAs($teacher)->get(route('teacher.results.sheet', $theirExam))->assertNotFound();
    }

    /** No verify and no publish on the teacher panel — §2.28.4, not an omission. */
    #[Test]
    public function the_teacher_panel_offers_no_way_to_publish(): void
    {
        $names = collect(app('router')->getRoutes())
            ->map(static fn ($route): string => (string) $route->getName())
            ->filter(static fn (string $name): bool => str_starts_with($name, 'teacher.'))
            ->values();

        $this->assertNotContains('teacher.results.publish', $names);
        $this->assertNotContains('teacher.results.verify', $names);
        $this->assertNotContains('teacher.results.unpublish', $names);
    }

    /*
    |--------------------------------------------------------------------------
    | Student — their own rows, published only
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function another_students_result_is_a_404(): void
    {
        $staff = $this->createSuperAdmin();
        $checker = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);

        [$mine, $myUser, $myEnrollment] = $this->studentWithLogin($batch, $staff);
        [$theirs, $theirUser] = $this->studentWithLogin($batch, $staff);

        $exam = $this->conductedExam($batch, [], $staff);
        $this->publishedExam($exam, [
            ['student_id' => (int) $mine->getKey(), 'attendance_status' => 'appeared', 'obtained_marks' => '88'],
            ['student_id' => (int) $theirs->getKey(), 'attendance_status' => 'appeared', 'obtained_marks' => '41'],
        ], $staff, $checker);

        $myResult = ExamResult::query()
            ->where('exam_id', $exam->getKey())
            ->where('student_id', $mine->getKey())
            ->firstOrFail();

        $this->actingAs($myUser)->get(route('student.results.show', $myResult))->assertOk();
        $this->actingAs($theirUser)->get(route('student.results.show', $myResult))->assertNotFound();
        $this->actingAs($theirUser)->get(route('student.results.card', $myEnrollment))->assertNotFound();
    }

    /**
     * An unpublished mark is a 404 rather than a 403 for the same reason as everything else on this
     * panel: a 403 would confirm the mark exists, which is exactly what an unpublished sheet hides.
     */
    #[Test]
    public function an_unpublished_result_is_a_404_for_the_student_it_belongs_to(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        [$student, $studentUser] = $this->studentWithLogin($batch, $staff);

        $exam = $this->conductedExam($batch, [], $staff);
        $this->resultService()->saveSheet($exam, [
            ['student_id' => (int) $student->getKey(), 'attendance_status' => 'appeared', 'obtained_marks' => '88'],
        ], $staff);

        $result = ExamResult::query()->where('exam_id', $exam->getKey())->firstOrFail();

        $this->actingAs($studentUser)->get(route('student.results.show', $result))->assertNotFound();
    }

    #[Test]
    public function a_student_cannot_reach_the_admin_or_teacher_screens(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        [, $studentUser] = $this->studentWithLogin($batch, $staff);
        $this->roster($batch, 1, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->actingAs($studentUser)->get(route('admin.exam-results.sheet', $exam))->assertForbidden();
        $this->actingAs($studentUser)->get(route('admin.exams.index'))->assertForbidden();
        $this->actingAs($studentUser)->get(route('teacher.results.sheet', $exam))->assertForbidden();
    }
}
