<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Materials;

use App\Enums\PanelType;
use App\Models\Branch;
use App\Models\Module;
use App\Models\User;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\Feature\Institute\Materials\Concerns\BuildsMaterials;
use Tests\TestCase;

/**
 * Data isolation across the three panels (phase-19-23 §9, §11, `CLAUDE.md` §1.10).
 *
 * **Every refusal here is checked for its status code, not just for "not 200".** A 403 says the row
 * exists and you may not have it; a 404 says nothing. Which one a route answers is the whole of §7.9's
 * rule, and getting it wrong turns an id field into an enumeration oracle.
 */
final class MaterialAuthorizationTest extends TestCase
{
    use BuildsAdmissions;
    use BuildsCatalogue;
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

    /*
    |--------------------------------------------------------------------------
    | Permissions gate the admin screens
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_material_library_needs_its_own_permission(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $material = $this->sharedMaterial($batch, $staff);

        $stranger = $this->createUserWithPermissions(['courses.view_any']);

        $this->actingAs($stranger)->get(route('admin.course-materials.index'))->assertForbidden();
        $this->actingAs($stranger)->get(route('admin.course-materials.show', $material))->assertForbidden();

        $reader = $this->createUserWithPermissions(['course_materials.view_any', 'course_materials.view']);

        $this->actingAs($reader)->get(route('admin.course-materials.index'))->assertOk();
    }

    /**
     * §4.2: `assign` **is** the targeting ability, and it is separate from `edit` so a coordinator can
     * tidy a library without pushing a handout at another batch.
     */
    #[Test]
    public function targeting_is_a_different_right_from_editing(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $material = $this->sharedMaterial($batch, $staff);

        $editor = $this->createUserWithPermissions([
            'course_materials.view_any', 'course_materials.view', 'course_materials.edit',
        ]);

        $this->assertTrue($editor->can('update', $material));
        $this->assertFalse($editor->can('assign', $material), 'Editing a title is not deciding who receives it.');

        $this->actingAs($editor)
            ->post(route('admin.course-materials.targets.store', $material), ['targets' => []])
            ->assertForbidden();
    }

    /** Reading the library is not taking the bytes. */
    #[Test]
    public function downloading_is_a_different_right_from_reading(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $material = $this->sharedMaterial($batch, $staff);

        $auditor = $this->createUserWithPermissions(['course_materials.view_any', 'course_materials.view']);

        $this->actingAs($auditor)->get(route('admin.course-materials.show', $material))->assertOk();
        $this->actingAs($auditor)->get(route('admin.course-materials.download', $material))->assertForbidden();
    }

    /**
     * §4.1's whole justification: grading and authoring are different rights, gated by different
     * modules.
     */
    #[Test]
    public function marking_and_authoring_are_separately_grantable(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $submission = $this->handedIn($assignment, $student, $staff);

        $marker = $this->createUserWithPermissions([
            'assignment_submissions.view_any', 'assignment_submissions.view', 'assignment_submissions.edit',
        ]);

        $this->assertTrue($marker->can('update', $submission), 'They may mark.');
        $this->assertFalse($marker->can('create', $assignment::class), 'They may not publish new work.');

        $author = $this->createUserWithPermissions([
            'assignments.view_any', 'assignments.view', 'assignments.create', 'assignments.edit',
        ]);

        $this->assertTrue($author->can('update', $assignment), 'They may set work.');
        $this->assertFalse($author->can('update', $submission), 'They may not mark it.');
    }

    /** A module switched off denies everyone, Super Admin included (`Gate::before` step 1). */
    #[Test]
    public function a_disabled_module_closes_the_screens_for_everybody(): void
    {
        $staff = $this->createSuperAdmin();

        $this->actingAs($staff)->get(route('admin.course-materials.index'))->assertOk();

        Module::query()->where('slug', 'course_materials')->update(['is_enabled' => false]);
        Modules::flushCache();

        $this->actingAs($staff)->get(route('admin.course-materials.index'))->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | The student panel
    |--------------------------------------------------------------------------
    */

    /**
     * Another student's material is a **404**, not a 403. A 403 would confirm the id names something.
     */
    #[Test]
    public function another_students_material_does_not_resolve(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $material = $this->sharedMaterial($batch, $staff);

        $elsewhere = $this->unrelatedBatch($staff);
        [, $outsiderUser] = $this->studentWithLogin($elsewhere, $staff);

        $this->actingAs($outsiderUser)->get(route('student.materials.show', $material))->assertNotFound();
        $this->actingAs($outsiderUser)->get(route('student.materials.download', $material))->assertNotFound();
    }

    #[Test]
    public function a_student_sees_only_their_own_library(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [, $studentUser] = $this->studentWithLogin($batch, $staff);

        $mine = $this->sharedMaterial($batch, $staff, ['title' => 'My own handout']);

        $elsewhere = $this->unrelatedBatch($staff);
        $theirs = $this->sharedMaterial($elsewhere, $staff, ['title' => 'Somebody else\'s handout']);

        $this->actingAs($studentUser)
            ->get(route('student.materials.index'))
            ->assertOk()
            ->assertSee('My own handout')
            ->assertDontSee('Somebody else');
    }

    /** §4.3: the list and the files are separately grantable, which is the point of the second one. */
    #[Test]
    public function a_student_can_be_shown_the_list_and_denied_the_files(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student, $studentUser] = $this->studentWithLogin($batch, $staff);

        $material = $this->sharedMaterial($batch, $staff);

        $limited = $this->createRoleWithPermissions(
            ['student_portal.dashboard', 'student_portal.materials'],
            PanelType::Student,
            10,
        );

        $studentUser->syncRoles([$limited]);
        $this->forgetPermissionCache();

        $this->actingAs($studentUser)->get(route('student.materials.index'))->assertOk();
        $this->actingAs($studentUser)->get(route('student.materials.download', $material))->assertForbidden();
    }

    #[Test]
    public function another_students_submission_does_not_resolve(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$mine, $mineUser] = $this->studentWithLogin($batch, $staff);
        [$theirs] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $somebodyElses = $this->handedIn($assignment, $theirs, $staff, withFile: true);

        $this->actingAs($mineUser)
            ->delete(route('student.submissions.withdraw', $somebodyElses))
            ->assertNotFound();

        $file = $somebodyElses->files()->firstOrFail();

        $this->actingAs($mineUser)
            ->get(route('student.submissions.file', [$somebodyElses, $file]))
            ->assertNotFound();
    }

    /** A draft assignment must not reach a student at all. */
    #[Test]
    public function a_draft_assignment_does_not_resolve_for_a_student(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [, $studentUser] = $this->studentWithLogin($batch, $staff);

        $draft = $this->draftAssignment($batch, $staff);

        $this->actingAs($studentUser)->get(route('student.assignments.show', $draft))->assertNotFound();
    }

    /** Feedback is withheld until it is released, and the check is on the server. */
    #[Test]
    public function a_student_cannot_reach_feedback_before_it_is_released(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student, $studentUser] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff, ['marks_visible_to_students' => false]);
        $graded = $this->submissionService()->grade(
            $this->handedIn($assignment, $student, $staff),
            ['obtained_marks' => '40', 'feedback' => 'Well argued'],
            $staff,
            $this->pdf('feedback.pdf'),
        );

        $this->actingAs($studentUser)
            ->get(route('student.submissions.feedback', $graded))
            ->assertNotFound();

        $this->submissionService()->releaseMarks($assignment, $staff);

        $this->actingAs($studentUser)
            ->get(route('student.submissions.feedback', $graded->refresh()))
            ->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | The teacher panel
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function another_teachers_assignment_does_not_resolve(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $elsewhere = $this->unrelatedBatch($staff);

        $teacherUser = $this->teacherWithLogin($elsewhere);

        if (! $teacherUser instanceof User) {
            $this->markTestSkipped('The fixture batch has no teacher to sign in as.');
        }

        $notTheirs = $this->publishedAssignment($batch, $staff);

        $this->actingAs($teacherUser)
            ->get(route('teacher.submissions.index', $notTheirs))
            ->assertNotFound();
    }

    /** A teacher may target only batches they actually reach — the form is never trusted. */
    #[Test]
    public function a_teacher_cannot_target_a_batch_they_do_not_teach(): void
    {
        $staff = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1], $staff);
        $mine = $this->runningBatch($course, [], $staff);
        $notMine = $this->runningBatch($course, [], $staff);

        $teacherUser = $this->teacherWithLogin($mine);

        if (! $teacherUser instanceof User) {
            $this->markTestSkipped('The fixture batch has no teacher to sign in as.');
        }

        $material = $this->sharedMaterial($mine, $staff);

        $this->actingAs($teacherUser)
            ->post(route('teacher.materials.targets', $material), [
                'targets' => [['type' => 'batch', 'id' => (int) $notMine->getKey()]],
            ]);

        $this->assertFalse(
            $material->targets()->where('target_batch_id', $notMine->getKey())->exists(),
            'A batch id the teacher does not reach is dropped, never written.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Nothing deletes a submission, through any route
    |--------------------------------------------------------------------------
    */

    /** There is no admin destroy route, and asserting that is cheaper than hoping. */
    #[Test]
    public function there_is_no_route_that_deletes_a_submission(): void
    {
        $names = collect(app('router')->getRoutes()->getRoutesByName())->keys();

        $this->assertEmpty(
            $names->filter(static fn (string $name): bool => str_contains($name, 'assignment-submissions.destroy')
                || str_contains($name, 'submissions.destroy'))->all(),
            'A submission is superseded or returned. It is never deleted, so no route offers it.',
        );
    }

    /** A student's withdraw route reaches drafts only. */
    #[Test]
    public function the_withdraw_route_refuses_work_that_was_handed_in(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student, $studentUser] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $submitted = $this->handedIn($assignment, $student, $staff);

        $this->actingAs($studentUser)
            ->delete(route('student.submissions.withdraw', $submitted))
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('assignment_submissions', [
            'id' => $submitted->getKey(),
            'status' => 'submitted',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Branch scoping
    |--------------------------------------------------------------------------
    */

    /** §9: a user with a branch sees their branch; a row with no branch belongs to everyone. */
    #[Test]
    public function a_branch_user_cannot_read_another_branchs_material(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $material = $this->sharedMaterial($batch, $staff);

        $here = Branch::query()->create(['name' => 'Lahore', 'code' => 'LHR', 'is_active' => true]);
        $there = Branch::query()->create(['name' => 'Karachi', 'code' => 'KHI', 'is_active' => true]);

        $material->forceFill(['branch_id' => $there->getKey()])->saveQuietly();

        $reader = $this->createUserWithPermissions(
            ['course_materials.view_any', 'course_materials.view'],
            attributes: ['branch_id' => $here->getKey()],
        );

        $this->assertFalse($reader->can('view', $material->refresh()));

        $material->forceFill(['branch_id' => null])->saveQuietly();

        $this->assertTrue(
            $reader->can('view', $material->refresh()),
            'A material with no branch is offered everywhere, so it is visible to all.',
        );
    }
}
