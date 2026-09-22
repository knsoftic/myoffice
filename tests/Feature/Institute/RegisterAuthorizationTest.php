<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Enums\StudentAttendanceStatus;
use App\Models\Branch;
use App\Models\Institute\ClassSession;
use App\Models\Institute\StudentAttendance;
use App\Models\Institute\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\TestCase;

/**
 * Who may take a register and mark a syllabus (phase-14-17 §4, §9; FT-46, FT-48, FT-49, FT-52).
 *
 * **Three boundaries carry this phase**, and each is tested from both sides: taking a register is not
 * revising one; marking a topic for a class is not marking it for one student; and a teacher or a
 * student sees only their own rows — as a 404, never a 403, because a 403 confirms the row exists.
 */
final class RegisterAuthorizationTest extends TestCase
{
    use BuildsRegisters;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Taking versus revising (INV-I10)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_marker_can_take_a_register_without_being_able_to_revise_a_reported_one(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $session = $this->classOn($batch, Carbon::today()->subDay()->toDateString());

        $marker = $this->createUserWithPermissions([
            'student_attendance.view_any', 'student_attendance.view', 'student_attendance.create',
        ]);

        $this->assertFalse($marker->can('student_attendance.edit'));

        $this->actingAs($marker)
            ->post(route('admin.student-attendance.store', $session), [
                'marks' => [(int) $enrollment->student_id => ['status' => 'present']],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('student_attendances', [
            'class_session_id' => $session->getKey(),
            'student_id' => $enrollment->student_id,
            'status' => 'present',
        ]);

        // The amendment endpoint is a different right.
        $row = StudentAttendance::query()->where('class_session_id', $session->getKey())->firstOrFail();

        $this->actingAs($marker)
            ->put(route('admin.student-attendance.update', $row), ['status' => 'absent'])
            ->assertForbidden();
    }

    #[Test]
    public function the_reports_are_their_own_right(): void
    {
        $actor = $this->createSuperAdmin();
        $this->runningBatch(actor: $actor);

        $marker = $this->createUserWithPermissions([
            'student_attendance.view_any', 'student_attendance.view', 'student_attendance.create',
        ]);

        $this->actingAs($marker)->get(route('admin.student-attendance.index'))->assertOk();
        $this->actingAs($marker)->get(route('admin.student-attendance.reports.daily'))->assertForbidden();
        $this->actingAs($marker)->get(route('admin.student-attendance.reports.percentage'))->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | The class-level mark versus the individual one
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function marking_for_a_class_and_marking_for_one_student_are_separate_rights(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1], $actor);
        $batch = $this->enrollingBatch($course, [], $actor);
        $enrollment = $this->seat($batch, actor: $actor);
        $topic = $this->topicsOf($course)[0];

        $teacher = $this->createUserWithPermissions([
            'student_progress.view_any', 'student_progress.view', 'student_progress.create',
        ]);

        $this->assertFalse($teacher->can('student_progress.edit'));

        $this->actingAs($teacher)
            ->post(route('admin.student-progress.batch.topic', [$batch, $topic]), ['status' => 'completed'])
            ->assertRedirect();

        $this->assertDatabaseHas('batch_topic_coverage', [
            'batch_id' => $batch->getKey(),
            'course_topic_id' => $topic->getKey(),
            'status' => 'completed',
        ]);

        // Overriding it for one student is `edit`.
        $this->actingAs($teacher)
            ->post(route('admin.student-progress.student.topic', [$enrollment, $topic]), ['status' => 'in_progress'])
            ->assertForbidden();
    }

    #[Test]
    public function skipping_a_topic_is_a_status_change_and_not_a_mark(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1], $actor);
        $batch = $this->enrollingBatch($course, [], $actor);
        $this->seat($batch, actor: $actor);
        $topic = $this->topicsOf($course)[0];

        $marker = $this->createUserWithPermissions([
            'student_progress.view_any', 'student_progress.view', 'student_progress.create',
        ]);

        // Dropping a topic raises every student's published percentage, so it is its own permission.
        $this->actingAs($marker)
            ->post(route('admin.student-progress.batch.skip', [$batch, $topic]), ['reason' => 'Not this term.'])
            ->assertForbidden();
    }

    #[Test]
    public function the_progress_module_declares_no_delete_at_all(): void
    {
        // §4.2: every row is derived and re-derivable, so deleting one would destroy nothing and
        // repair nothing. The ability does not exist to be granted.
        $this->assertNotContains('student_progress.delete', \App\Support\PermissionRegistry::permissionNames());
        $this->assertNotContains('student_progress.restore', \App\Support\PermissionRegistry::permissionNames());
    }

    /*
    |--------------------------------------------------------------------------
    | Branch isolation (FT-52)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_branch_user_does_not_see_another_branchs_register(): void
    {
        $actor = $this->createSuperAdmin();

        $here = Branch::query()->create(['name' => 'Lahore', 'code' => 'LHR', 'is_active' => true]);
        $there = Branch::query()->create(['name' => 'Karachi', 'code' => 'KHI', 'is_active' => true]);

        $mine = $this->runningBatch(null, ['branch_id' => $here->getKey(), 'code' => 'MINE-1'], $actor);
        $theirs = $this->runningBatch(null, ['branch_id' => $there->getKey(), 'code' => 'THEIRS-1'], $actor);

        $this->seat($mine, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);
        $this->seat($theirs, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $today = Carbon::today()->toDateString();
        $this->classOn($mine, $today);
        $this->classOn($theirs, $today);

        $user = $this->createUserWithPermissions([
            'student_attendance.view_any', 'student_attendance.view', 'student_attendance.view_reports',
        ]);
        $user->forceFill(['branch_id' => $here->getKey()])->save();

        $response = $this->actingAs($user)->get(route('admin.student-attendance.reports.daily', ['date' => $today]));

        $response->assertOk();
        $response->assertSee($mine->code);
        $response->assertDontSee($theirs->code);
    }

    /*
    |--------------------------------------------------------------------------
    | The panels (FT-48, FT-49)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_teacher_can_only_mark_their_own_classes(): void
    {
        $actor = $this->createSuperAdmin();

        $mineTeacher = $this->teacher(['email' => 'mine.register@example.test'], $actor);
        $otherTeacher = $this->teacher(['email' => 'other.register@example.test'], $actor);

        $mine = $this->runningBatch(null, ['teacher_id' => $mineTeacher->getKey(), 'code' => 'MINE-R'], $actor);
        $theirs = $this->runningBatch(null, ['teacher_id' => $otherTeacher->getKey(), 'code' => 'THEIRS-R'], $actor);

        $this->seat($mine, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);
        $this->seat($theirs, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $mySession = $this->classOn($mine, Carbon::today()->subDay()->toDateString());
        $theirSession = $this->classOn($theirs, Carbon::today()->subDay()->toDateString());

        $user = $this->teacherLoginFor($mineTeacher);

        $this->actingAs($user)->get(route('teacher.attendance.mark', $mySession))->assertOk();

        // Somebody else's class is a 404: a 403 would confirm it exists.
        $this->actingAs($user)->get(route('teacher.attendance.mark', $theirSession))->assertNotFound();

        $this->actingAs($user)
            ->post(route('teacher.attendance.store', $theirSession), [
                'marks' => [1 => ['status' => 'present']],
            ])
            ->assertNotFound();
    }

    #[Test]
    public function a_substitution_grants_that_one_class_and_nothing_else(): void
    {
        $actor = $this->createSuperAdmin();

        $owner = $this->teacher(['email' => 'owner.register@example.test'], $actor);
        $substitute = $this->teacher(['email' => 'sub.register@example.test'], $actor);

        $batch = $this->runningBatch(null, ['teacher_id' => $owner->getKey()], $actor);
        $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $covered = $this->classOn($batch, Carbon::today()->addDays(2)->toDateString(), 'scheduled');
        $other = $this->classOn($batch, Carbon::today()->addDays(9)->toDateString(), 'scheduled');

        $this->sessionService()->substituteTeacher($covered, $substitute, 'The teacher is away.', $actor);

        $user = $this->teacherLoginFor($substitute);

        $this->actingAs($user)->get(route('teacher.attendance.mark', $covered->refresh()))->assertOk();
        $this->actingAs($user)->get(route('teacher.attendance.mark', $other))->assertNotFound();
    }

    #[Test]
    public function a_student_sees_their_own_attendance_and_no_classmate(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);

        $mine = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);
        $theirs = $this->seat($batch->refresh(), options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $session = $this->classOn($batch, Carbon::today()->subDay()->toDateString());

        $this->attendanceService()->mark($session, [
            (int) $mine->student_id => ['status' => 'present'],
            (int) $theirs->student_id => ['status' => 'absent'],
        ], [], $actor);

        $user = $this->studentService()->createLogin(
            $mine->student->forceFill(['email' => 'own.register@example.test']),
            $actor,
        );

        $this->assertNotNull($user);
        $user->forceFill(['must_change_password' => false])->save();

        $response = $this->actingAs($user)->get(route('student.attendance.index'));

        $response->assertOk();
        $response->assertDontSee($theirs->student->name, false);

        $progress = $this->actingAs($user)->get(route('student.progress.index'));
        $progress->assertOk();
        $progress->assertDontSee($theirs->student->name, false);
    }

    #[Test]
    public function a_student_cannot_reach_a_staff_or_teacher_register(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);
        $session = $this->classOn($batch, Carbon::today()->subDay()->toDateString());

        $user = $this->studentService()->createLogin(
            $enrollment->student->forceFill(['email' => 'boundary.register@example.test']),
            $actor,
        );

        $this->assertNotNull($user);
        $user->forceFill(['must_change_password' => false])->save();

        $this->actingAs($user)->get(route('admin.student-attendance.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.student-attendance.mark', $session))->assertForbidden();
        $this->actingAs($user)->get(route('admin.student-progress.index'))->assertForbidden();
        $this->actingAs($user)->get(route('teacher.attendance.index'))->assertForbidden();
    }

    private function teacherLoginFor(Teacher $teacher): User
    {
        $user = $this->teacherService()->createLogin($teacher, $this->createSuperAdmin());

        $this->assertNotNull($user, 'the fixture needs a teacher login');

        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }
}
