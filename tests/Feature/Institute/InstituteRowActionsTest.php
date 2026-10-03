<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Models\Institute\Assignment;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\CourseInquiry;
use App\Models\Institute\CourseMaterial;
use App\Models\Institute\Exam;
use App\Models\Institute\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\Feature\Institute\Materials\Concerns\BuildsMaterials;
use Tests\Feature\Institute\Results\Concerns\BuildsExams;
use Tests\TestCase;

/**
 * Every Institute destroy route is reachable from a screen, and only for a row its policy would let go.
 *
 * The delete control is asserted by the form's `action="…"`, not by the URL alone: a resource's
 * destroy URL is the same as its show URL, and the show link is on every row.
 */
final class InstituteRowActionsTest extends TestCase
{
    use BuildsAdmissions;
    use BuildsCatalogue;
    use BuildsExams;
    use BuildsMaterials;
    use BuildsRegisters;
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    private function seesForm(TestResponse $response, string $url): void
    {
        $response->assertOk()->assertSee('action="'.$url.'"', false);
    }

    private function seesNoForm(TestResponse $response, string $url): void
    {
        $response->assertOk()->assertDontSee('action="'.$url.'"', false);
    }

    /**
     * A row with history is refused twice over: a role holding `<module>.delete` gets the policy's
     * 403, and a Super Admin - whom Gate::before waves past every policy - gets the controller's
     * error toast. Neither removes anything.
     *
     * @param  list<string>  $permissions
     */
    private function refusesDelete(string $url, array $permissions, $admin, string $listRoute): void
    {
        $holder = $this->createUserWithPermissions($permissions);

        $this->seesNoForm($this->actingAs($holder)->get(route($listRoute)), $url);
        $this->actingAs($holder)->delete($url)->assertForbidden();

        $this->actingAs($admin)->from(route($listRoute))->delete($url)
            ->assertRedirect(route($listRoute))
            ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'error');
    }

    /*
    |--------------------------------------------------------------------------
    | Batches
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_empty_batch_offers_delete_and_is_soft_deleted(): void
    {
        $admin = $this->createSuperAdmin();
        $batch = $this->batch(actor: $admin);
        $url = route('admin.batches.destroy', $batch);

        $this->seesForm($this->actingAs($admin)->get(route('admin.batches.index')), $url);
        $this->seesForm($this->actingAs($admin)->get(route('admin.batches.show', $batch)), $url);
        $this->actingAs($admin)->get(route('admin.batches.index'))
            ->assertSee(route('admin.batches.edit', $batch), false);

        $this->actingAs($admin)->delete($url)
            ->assertRedirect(route('admin.batches.index'))
            ->assertSessionHas('toast');

        $this->assertSoftDeleted('batches', ['id' => $batch->getKey()]);
    }

    #[Test]
    public function a_batch_anyone_was_enrolled_in_offers_no_delete_and_refuses_it(): void
    {
        $admin = $this->createSuperAdmin();
        $batch = $this->enrollingBatch(actor: $admin);
        $this->seat($batch, null, [], $admin);
        $url = route('admin.batches.destroy', $batch);

        $this->seesNoForm($this->actingAs($admin)->get(route('admin.batches.index')), $url);
        $this->seesNoForm($this->actingAs($admin)->get(route('admin.batches.show', $batch)), $url);

        $this->refusesDelete($url, ['batches.view_any', 'batches.view', 'batches.delete'], $admin, 'admin.batches.index');
        $this->assertNotNull(Batch::query()->find($batch->getKey()));
    }

    /*
    |--------------------------------------------------------------------------
    | Courses
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_course_nothing_was_sold_against_offers_delete_and_is_soft_deleted(): void
    {
        $admin = $this->createSuperAdmin();
        $course = $this->draftCourse(actor: $admin);
        $url = route('admin.courses.destroy', $course);

        $this->seesForm($this->actingAs($admin)->get(route('admin.courses.index')), $url);
        $this->seesForm($this->actingAs($admin)->get(route('admin.courses.show', $course)), $url);

        $this->actingAs($admin)->delete($url)
            ->assertRedirect(route('admin.courses.index'))
            ->assertSessionHas('toast');

        $this->assertSoftDeleted('courses', ['id' => $course->getKey()]);
    }

    #[Test]
    public function a_course_with_a_batch_offers_no_delete_and_refuses_it(): void
    {
        $admin = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $admin);
        $this->batch($course, [], $admin);
        $url = route('admin.courses.destroy', $course);

        $this->seesNoForm($this->actingAs($admin)->get(route('admin.courses.index')), $url);
        $this->seesNoForm($this->actingAs($admin)->get(route('admin.courses.show', $course)), $url);

        $this->refusesDelete($url, ['courses.view_any', 'courses.view', 'courses.delete'], $admin, 'admin.courses.index');
        $this->assertNotNull(Course::query()->find($course->getKey()));
    }

    #[Test]
    public function a_user_without_courses_delete_sees_no_control(): void
    {
        $admin = $this->createSuperAdmin();
        $course = $this->draftCourse(actor: $admin);
        $url = route('admin.courses.destroy', $course);

        $editor = $this->createUserWithPermissions(['courses.view_any', 'courses.view', 'courses.edit']);

        $this->seesNoForm($this->actingAs($editor)->get(route('admin.courses.index')), $url);
        $this->actingAs($editor)->delete($url)->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Course inquiries
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_open_inquiry_offers_delete_and_is_soft_deleted(): void
    {
        $admin = $this->createSuperAdmin();
        $inquiry = $this->inquiry([], $admin);
        $url = route('admin.course-inquiries.destroy', $inquiry);

        $this->seesForm($this->actingAs($admin)->get(route('admin.course-inquiries.index')), $url);
        $this->seesForm($this->actingAs($admin)->get(route('admin.course-inquiries.show', $inquiry)), $url);

        $this->actingAs($admin)->delete($url)
            ->assertRedirect(route('admin.course-inquiries.index'))
            ->assertSessionHas('toast');

        $this->assertSoftDeleted('course_inquiries', ['id' => $inquiry->getKey()]);
    }

    #[Test]
    public function a_promoted_inquiry_offers_no_delete_and_refuses_it(): void
    {
        $admin = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $admin);
        $inquiry = $this->inquiry(['course_id' => $course->getKey()], $admin);

        $this->actingAs($admin)
            ->post(route('admin.course-inquiries.promote', $inquiry), ['course_id' => $course->getKey()])
            ->assertRedirect();

        $this->assertNotNull($inquiry->refresh()->converted_application_id);
        $url = route('admin.course-inquiries.destroy', $inquiry);

        $this->seesNoForm($this->actingAs($admin)->get(route('admin.course-inquiries.index')), $url);
        $this->seesNoForm($this->actingAs($admin)->get(route('admin.course-inquiries.show', $inquiry)), $url);

        $this->refusesDelete($url, ['course_inquiries.view_any', 'course_inquiries.view', 'course_inquiries.delete'], $admin, 'admin.course-inquiries.index');
        $this->assertNotNull(CourseInquiry::query()->find($inquiry->getKey()));
    }

    /*
    |--------------------------------------------------------------------------
    | Course materials
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_material_offers_delete_and_is_soft_deleted(): void
    {
        $admin = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $admin), [], $admin);
        $material = $this->sharedMaterial($batch, $admin);
        $url = route('admin.course-materials.destroy', $material);

        $this->seesForm($this->actingAs($admin)->get(route('admin.course-materials.index')), $url);
        $this->seesForm($this->actingAs($admin)->get(route('admin.course-materials.show', $material)), $url);

        $this->actingAs($admin)->delete($url)
            ->assertRedirect(route('admin.course-materials.index'))
            ->assertSessionHas('toast');

        $this->assertSoftDeleted('course_materials', ['id' => $material->getKey()]);

        // The show route reads trashed rows; a trashed material offers no second delete.
        $this->seesNoForm($this->actingAs($admin)->get(route('admin.course-materials.show', $material)), $url);
    }

    #[Test]
    public function a_user_without_material_delete_sees_no_control_and_is_refused(): void
    {
        $admin = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $admin), [], $admin);
        $material = $this->sharedMaterial($batch, $admin);
        $url = route('admin.course-materials.destroy', $material);

        $reader = $this->createUserWithPermissions([
            'course_materials.view_any', 'course_materials.view', 'course_materials.edit',
        ]);

        $this->seesNoForm($this->actingAs($reader)->get(route('admin.course-materials.index')), $url);
        $this->seesNoForm($this->actingAs($reader)->get(route('admin.course-materials.show', $material)), $url);

        $this->actingAs($reader)->delete($url)->assertForbidden();
        $this->assertNotNull(CourseMaterial::query()->find($material->getKey()));
    }

    /*
    |--------------------------------------------------------------------------
    | Teachers and the employee link
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_teacher_without_classes_offers_delete_and_is_soft_deleted(): void
    {
        $admin = $this->createSuperAdmin();
        $teacher = $this->teacher([], $admin);
        $url = route('admin.teachers.destroy', $teacher);

        $this->seesForm($this->actingAs($admin)->get(route('admin.teachers.index')), $url);
        $this->seesForm($this->actingAs($admin)->get(route('admin.teachers.show', $teacher)), $url);
        $this->actingAs($admin)->get(route('admin.teachers.index'))
            ->assertSee(route('admin.teachers.edit', $teacher), false);

        $this->actingAs($admin)->delete($url)
            ->assertRedirect(route('admin.teachers.index'))
            ->assertSessionHas('toast');

        $this->assertSoftDeleted('teachers', ['id' => $teacher->getKey()]);
    }

    #[Test]
    public function a_teacher_with_a_batch_offers_no_delete_and_refuses_it(): void
    {
        $admin = $this->createSuperAdmin();
        $teacher = $this->teacher([], $admin);
        $this->batch(null, ['teacher_id' => $teacher->getKey()], $admin);
        $url = route('admin.teachers.destroy', $teacher);

        $this->seesNoForm($this->actingAs($admin)->get(route('admin.teachers.index')), $url);
        $this->seesNoForm($this->actingAs($admin)->get(route('admin.teachers.show', $teacher)), $url);

        $this->refusesDelete($url, ['teachers.view_any', 'teachers.view', 'teachers.delete'], $admin, 'admin.teachers.index');
        $this->assertNotNull(Teacher::query()->find($teacher->getKey()));
    }

    #[Test]
    public function a_linked_teacher_can_be_unlinked_from_the_show_page_with_a_reason(): void
    {
        $admin = $this->createSuperAdmin();
        $teacher = $this->teacherService()->linkEmployee($this->teacher([], $admin), 4242, 'Joined payroll', $admin);
        $url = route('admin.teachers.employee-link.destroy', $teacher);

        $this->seesForm($this->actingAs($admin)->get(route('admin.teachers.show', $teacher)), $url);

        // The reason is required, and refusing it leaves the link in place.
        $this->actingAs($admin)->delete($url, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(4242, (int) $teacher->refresh()->employee_id);

        $this->actingAs($admin)->delete($url, ['reason' => 'Left payroll, still visiting'])
            ->assertRedirect()
            ->assertSessionHas('toast');

        $this->assertNull($teacher->refresh()->employee_id);
        $this->assertNotNull(Teacher::query()->find($teacher->getKey()), 'unlinking never removes the teacher');
    }

    #[Test]
    public function the_unlink_control_needs_teacher_edit(): void
    {
        $admin = $this->createSuperAdmin();
        $teacher = $this->teacherService()->linkEmployee($this->teacher([], $admin), 4243, 'Joined payroll', $admin);
        $url = route('admin.teachers.employee-link.destroy', $teacher);

        $reader = $this->createUserWithPermissions(['teachers.view_any', 'teachers.view']);

        $this->seesNoForm($this->actingAs($reader)->get(route('admin.teachers.show', $teacher)), $url);
        $this->actingAs($reader)->delete($url, ['reason' => 'No'])->assertForbidden();
        $this->assertSame(4243, (int) $teacher->refresh()->employee_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Exams and assignments — row actions on lists that had none
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_exam_without_results_offers_edit_and_delete_on_the_list(): void
    {
        $admin = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $admin), [], $admin);
        $exam = $this->draftExam($batch, [], $admin);
        $url = route('admin.exams.destroy', $exam);

        $response = $this->actingAs($admin)->get(route('admin.exams.index'));
        $this->seesForm($response, $url);
        $response->assertSee(route('admin.exams.edit', $exam), false);

        $this->actingAs($admin)->delete($url)->assertRedirect(route('admin.exams.index'));
        $this->assertSoftDeleted('exams', ['id' => $exam->getKey()]);

        $reader = $this->createUserWithPermissions(['exams.view_any', 'exams.view']);
        $other = $this->draftExam($batch, ['scheduled_date' => $this->nextMonday()->addDays(8)->toDateString()], $admin);
        $this->seesNoForm($this->actingAs($reader)->get(route('admin.exams.index')), route('admin.exams.destroy', $other));
        $this->actingAs($reader)->delete(route('admin.exams.destroy', $other))->assertForbidden();
        $this->assertNotNull(Exam::query()->find($other->getKey()));
    }

    #[Test]
    public function an_assignment_with_work_handed_in_offers_no_delete(): void
    {
        $admin = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $admin), [], $admin);

        $clean = $this->draftAssignment($batch, $admin, ['title' => 'Clean one']);
        $used = $this->publishedAssignment($batch, $admin, ['title' => 'Used one']);
        [$student] = $this->studentWithLogin($batch, $admin);
        $this->handedIn($used, $student, $admin);

        $response = $this->actingAs($admin)->get(route('admin.assignments.index'));
        $this->seesForm($response, route('admin.assignments.destroy', $clean));
        $this->seesNoForm($response, route('admin.assignments.destroy', $used));

        $this->refusesDelete(route('admin.assignments.destroy', $used), ['assignments.view_any', 'assignments.view', 'assignments.delete'], $admin, 'admin.assignments.index');
        $this->seesNoForm($this->actingAs($admin)->get(route('admin.assignments.show', $used)), route('admin.assignments.destroy', $used));
        $this->assertNotNull(Assignment::query()->find($used->getKey()));

        $this->actingAs($admin)->delete(route('admin.assignments.destroy', $clean))->assertRedirect(route('admin.assignments.index'));
        $this->assertSoftDeleted('assignments', ['id' => $clean->getKey()]);
    }

    #[Test]
    public function the_application_list_links_each_row(): void
    {
        $admin = $this->createSuperAdmin();
        $application = $this->staffApplication(null, [], $admin);

        $this->actingAs($admin)->get(route('admin.student-applications.index'))
            ->assertOk()
            ->assertSee(route('admin.student-applications.show', $application), false);
    }
}
