<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Models\Branch;
use App\Models\Institute\Batch;
use App\Models\Institute\ClassSession;
use App\Models\Institute\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\TestCase;

/**
 * Who may do what to a schedule, and what each panel can reach (phase-14-17 §4, §9).
 *
 * **`batches.assign` is deliberately not `batches.edit`.** The front desk seats students all day and
 * never opens a batch; the coordinator opens batches and rarely seats anybody. A single ability
 * covering both would hand each of them the other's job, so the split is tested from both sides.
 *
 * **A teacher's panel is scoped by the teacher they ARE**, never by an id in the URL — and another
 * teacher's class is a 404 rather than a 403, because a 403 confirms the row exists.
 */
final class SchedulingAuthorizationTest extends TestCase
{
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | The ability split
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function seating_a_student_needs_assign_and_not_edit(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->enrollingBatch(actor: $actor);
        $student = $this->registeredStudent($actor);

        $seater = $this->createUserWithPermissions([
            'batches.view_any', 'batches.view', 'batches.assign',
        ]);

        $this->assertFalse($seater->can('batches.edit'));

        $this->actingAs($seater)
            ->post(route('admin.batches.enrollments.store', $batch), [
                'student_id' => $student->getKey(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('student_batch_enrollments', [
            'batch_id' => $batch->getKey(),
            'student_id' => $student->getKey(),
            'status' => 'active',
        ]);

        // And the same person cannot change what the batch IS.
        $this->actingAs($seater)
            ->get(route('admin.batches.edit', $batch))
            ->assertForbidden();
    }

    #[Test]
    public function a_coordinator_who_may_edit_a_batch_cannot_seat_anybody_in_it(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->enrollingBatch(actor: $actor);
        $student = $this->registeredStudent($actor);

        $coordinator = $this->createUserWithPermissions([
            'batches.view_any', 'batches.view', 'batches.edit', 'batches.create',
        ]);

        $this->actingAs($coordinator)->get(route('admin.batches.edit', $batch))->assertOk();

        $this->actingAs($coordinator)
            ->post(route('admin.batches.enrollments.store', $batch), [
                'student_id' => $student->getKey(),
            ])
            ->assertForbidden();
    }

    #[Test]
    public function the_salary_is_behind_its_own_ability_and_a_posted_one_is_discarded(): void
    {
        $actor = $this->createSuperAdmin();
        $teacher = $this->teacher(actor: $actor);

        $editor = $this->createUserWithPermissions([
            'teachers.view_any', 'teachers.view', 'teachers.edit',
        ]);

        $this->assertFalse($editor->can('teachers.view_financial'));

        $this->actingAs($editor)
            ->put(route('admin.teachers.update', $teacher), [
                'name' => $teacher->name,
                'salary' => '999999.00',
            ])
            ->assertRedirect();

        // Not merely hidden in the form: the value never reaches the service.
        $this->assertNull($teacher->refresh()->salary,
            'a hidden field is still a field somebody can post');
    }

    #[Test]
    public function the_rooms_can_be_managed_without_the_batches_that_fill_them(): void
    {
        $actor = $this->createSuperAdmin();
        $this->enrollingBatch(actor: $actor);

        $caretaker = $this->createUserWithPermissions([
            'classrooms.view_any', 'classrooms.view', 'classrooms.create', 'classrooms.edit',
        ]);

        $this->actingAs($caretaker)->get(route('admin.classrooms.index'))->assertOk();

        $this->actingAs($caretaker)
            ->post(route('admin.classrooms.store'), [
                'code' => 'NEW-1',
                'name' => 'New room',
                'type' => 'classroom',
                'capacity' => 20,
            ])
            ->assertRedirect();

        $this->actingAs($caretaker)->get(route('admin.batches.index'))->assertForbidden();
    }

    #[Test]
    public function cancelling_a_class_needs_change_status_and_not_create(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->batch(actor: $actor);
        $this->slot($batch, actor: $actor);

        $session = ClassSession::query()->where('batch_id', $batch->getKey())->orderBy('session_date')->firstOrFail();

        $builder = $this->createUserWithPermissions([
            'timetable.view_any', 'timetable.view', 'timetable.create',
        ]);

        $this->actingAs($builder)
            ->post(route('admin.class-sessions.cancel', $session), [
                'cancellation_reason' => 'holiday',
                'cancellation_detail' => 'Public holiday.',
            ])
            ->assertForbidden();

        $this->assertSame('scheduled', $session->refresh()->status->value,
            'being allowed to build a timetable is not being allowed to call a class off');
    }

    /*
    |--------------------------------------------------------------------------
    | Branch isolation (§9)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_branch_user_does_not_see_another_branch_in_the_list(): void
    {
        $actor = $this->createSuperAdmin();

        $here = Branch::query()->create(['name' => 'Lahore', 'code' => 'LHR', 'is_active' => true]);
        $there = Branch::query()->create(['name' => 'Karachi', 'code' => 'KHI', 'is_active' => true]);

        $mine = $this->batch(null, ['branch_id' => $here->getKey(), 'code' => 'MINE-1'], $actor);
        $theirs = $this->batch(null, ['branch_id' => $there->getKey(), 'code' => 'THEIRS-1'], $actor);

        $user = $this->createUserWithPermissions(['batches.view_any', 'batches.view']);
        $user->forceFill(['branch_id' => $here->getKey()])->save();

        $response = $this->actingAs($user)->get(route('admin.batches.index'));

        $response->assertOk();
        $response->assertSee($mine->code);
        $response->assertDontSee($theirs->code);
    }

    #[Test]
    public function another_branchs_batch_is_not_viewable_even_by_id(): void
    {
        $actor = $this->createSuperAdmin();

        $here = Branch::query()->create(['name' => 'Lahore', 'code' => 'LHR', 'is_active' => true]);
        $there = Branch::query()->create(['name' => 'Karachi', 'code' => 'KHI', 'is_active' => true]);

        $theirs = $this->batch(null, ['branch_id' => $there->getKey()], $actor);

        $user = $this->createUserWithPermissions(['batches.view_any', 'batches.view']);
        $user->forceFill(['branch_id' => $here->getKey()])->save();

        $this->actingAs($user)->get(route('admin.batches.show', $theirs))->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | The panels
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_teacher_sees_their_own_batches_and_nobody_elses(): void
    {
        $actor = $this->createSuperAdmin();

        $mine = $this->teacher(['email' => 'mine@example.test'], $actor);
        $theirs = $this->teacher(['email' => 'theirs@example.test'], $actor);

        $myBatch = $this->batch(null, ['teacher_id' => $mine->getKey(), 'code' => 'MINE-1'], $actor);
        $theirBatch = $this->batch(null, ['teacher_id' => $theirs->getKey(), 'code' => 'THEIRS-1'], $actor);

        $user = $this->teacherLoginFor($mine);

        $response = $this->actingAs($user)->get(route('teacher.batches.index'));

        $response->assertOk();
        $response->assertSee($myBatch->code);
        $response->assertDontSee($theirBatch->code);

        // Somebody else's batch is a 404, not a 403: a 403 confirms it exists.
        $this->actingAs($user)->get(route('teacher.batches.show', $theirBatch))->assertNotFound();
    }

    #[Test]
    public function a_student_cannot_open_another_students_enrolment(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->enrollingBatch(actor: $actor);

        $mine = $this->registeredStudent($actor);
        $theirs = $this->registeredStudent($actor);

        $myEnrollment = $this->enrollmentService()->enroll($mine, $batch->refresh(), null, [], $actor);
        $theirEnrollment = $this->enrollmentService()->enroll($theirs, $batch->refresh(), null, [], $actor);

        $user = $this->studentService()->createLogin($mine, $actor);
        $this->assertNotNull($user, 'the fixture needs a student login');
        $user->forceFill(['must_change_password' => false])->save();

        $this->actingAs($user)->get(route('student.batches.show', $myEnrollment))->assertOk();
        $this->actingAs($user)->get(route('student.batches.show', $theirEnrollment))->assertNotFound();
    }

    #[Test]
    public function a_user_on_the_teacher_panel_with_no_teacher_record_is_a_404(): void
    {
        $user = $this->createUserWithPermissions(['teacher_portal.batches', 'teacher_portal.timetable']);
        $user->assignRole('Teacher');

        // An account that can reach the panel but owns no rows is a misconfiguration; a cheerful
        // empty state would hide it.
        $this->actingAs($user)->get(route('teacher.batches.index'))->assertNotFound();
    }

    private function teacherLoginFor(Teacher $teacher): User
    {
        $user = $this->teacherService()->createLogin($teacher, $this->createSuperAdmin());

        $this->assertNotNull($user, 'the fixture needs a teacher login');

        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }
}
