<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Enums\StudentAttendanceStatus;
use App\Models\Branch;
use App\Models\Institute\Batch;
use App\Models\Institute\ClassSession;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\TestCase;

/**
 * The register as JSON (`AttendanceApiController`): the same `student_attendances` rows the marking screen
 * keeps, read and written through the same service — so a second submission updates, a student off the
 * roster is refused, and the summary is the enrolment counters every report reads.
 */
final class AttendanceApiTest extends TestCase
{
    use BuildsRegisters;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function the_register_lists_each_class_of_the_day_with_its_roster_and_marks(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $marked = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);
        $unmarked = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $date = $this->freeDate($batch);
        $session = $this->classOn($batch, $date);
        $this->markOne($session, $marked, StudentAttendanceStatus::Present, actor: $actor);

        $response = $this->actingAs($actor)
            ->getJson(route('admin.student-attendance.api.register', ['batch_id' => $batch->getKey(), 'date' => $date]))
            ->assertOk()
            ->assertJsonPath('batch.id', $batch->getKey())
            ->assertJsonPath('date', $date)
            ->assertJsonCount(1, 'sessions')
            ->assertJsonPath('sessions.0.id', $session->getKey())
            ->assertJsonPath('sessions.0.can_be_marked', true)
            ->assertJsonPath('sessions.0.counts.present', 1)
            ->assertJsonPath('sessions.0.counts.unmarked', 1);

        $students = collect($response->json('sessions.0.students'))->keyBy('student_id');

        $this->assertSame('present', $students[$marked->student_id]['attendance']['status']);
        $this->assertNull($students[$unmarked->student_id]['attendance']);
        $this->assertSame(['present', 'absent', 'leave', 'late'], array_column($response->json('statuses'), 'value'));
    }

    #[Test]
    public function a_day_with_no_class_answers_an_empty_list(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);

        $this->actingAs($actor)
            ->getJson(route('admin.student-attendance.api.register', ['batch_id' => $batch->getKey(), 'date' => $this->freeDate($batch)]))
            ->assertOk()
            ->assertJsonCount(0, 'sessions');

        $this->actingAs($actor)
            ->getJson(route('admin.student-attendance.api.register', ['batch_id' => $batch->getKey(), 'date' => '09-10-2026']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    #[Test]
    public function marking_again_updates_the_row_rather_than_adding_one(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);
        $session = $this->classOn($batch, $this->freeDate($batch));

        $this->actingAs($actor)
            ->postJson(route('admin.student-attendance.api.store'), [
                'session_id' => $session->getKey(),
                'marks' => [['student_id' => $enrollment->student_id, 'status' => 'absent', 'remarks' => 'No call.']],
            ])
            ->assertOk()
            ->assertJsonPath('result.created', 1)
            ->assertJsonPath('session.counts.absent', 1);

        $this->actingAs($actor)
            ->postJson(route('admin.student-attendance.api.store'), [
                'session_id' => $session->getKey(),
                'marks' => [['student_id' => $enrollment->student_id, 'status' => 'leave']],
            ])
            ->assertOk()
            ->assertJsonPath('result.created', 0)
            ->assertJsonPath('result.updated', 1);

        $rows = StudentAttendance::query()
            ->where('class_session_id', $session->getKey())
            ->where('student_id', $enrollment->student_id)
            ->get();

        $this->assertCount(1, $rows, 'uq_sa_session_student: one row per student per class.');
        $this->assertSame(StudentAttendanceStatus::Leave, $rows->first()->status);
    }

    #[Test]
    public function batch_and_date_name_the_class_only_when_there_is_exactly_one(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);
        $date = $this->freeDate($batch);
        $body = [
            'batch_id' => $batch->getKey(),
            'date' => $date,
            'marks' => [['student_id' => $enrollment->student_id, 'status' => 'present']],
        ];

        $this->actingAs($actor)
            ->postJson(route('admin.student-attendance.api.store'), $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('session_id');

        $session = $this->classOn($batch, $date);

        $this->actingAs($actor)
            ->postJson(route('admin.student-attendance.api.store'), $body)
            ->assertOk()
            ->assertJsonPath('session.id', $session->getKey());

        $this->afternoonClassOn($batch, $date);

        $this->actingAs($actor)
            ->postJson(route('admin.student-attendance.api.store'), $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('session_id');
    }

    #[Test]
    public function a_student_off_the_roster_or_an_unknown_status_is_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);
        $outsider = $this->registeredStudent($actor);
        $session = $this->classOn($batch, $this->freeDate($batch));

        $this->actingAs($actor)
            ->postJson(route('admin.student-attendance.api.store'), [
                'session_id' => $session->getKey(),
                'marks' => [['student_id' => $outsider->getKey(), 'status' => 'present']],
            ])
            ->assertUnprocessable();

        $this->actingAs($actor)
            ->postJson(route('admin.student-attendance.api.store'), [
                'session_id' => $session->getKey(),
                'marks' => [['student_id' => $enrollment->student_id, 'status' => 'excused']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('marks.0.status');

        $this->assertSame(0, StudentAttendance::query()->where('class_session_id', $session->getKey())->count());
    }

    #[Test]
    public function the_summary_is_the_enrolment_counters(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $before = $enrollment->fresh();

        foreach ([StudentAttendanceStatus::Present, StudentAttendanceStatus::Absent, StudentAttendanceStatus::Late] as $status) {
            $this->markOne($this->classOn($batch, $this->freeDate($batch)), $enrollment, $status, actor: $actor);
        }

        $after = $enrollment->fresh();

        $response = $this->actingAs($actor)
            ->getJson(route('admin.student-attendance.api.summary', ['student' => $enrollment->student_id]))
            ->assertOk()
            ->assertJsonPath('student.id', $enrollment->student_id)
            ->assertJsonPath('enrollments.0.enrollment_id', $enrollment->getKey())
            ->assertJsonPath('enrollments.0.present', (int) $after->present_count)
            ->assertJsonPath('enrollments.0.absent', (int) $after->absent_count)
            ->assertJsonPath('enrollments.0.late', (int) $after->late_count)
            ->assertJsonPath('enrollments.0.classes', (int) $after->sessions_expected_count);

        $this->assertSame((int) $before->present_count + 1, (int) $after->present_count);
        $this->assertSame((int) $before->absent_count + 1, (int) $after->absent_count);
        $this->assertSame((int) $before->late_count + 1, (int) $after->late_count);
        $this->assertSame(
            number_format((float) $after->attendance_percentage, 2, '.', ''),
            $response->json('enrollments.0.percentage'),
            'The percentage the service computed (INV-I11), not a second formula.'
        );
    }

    #[Test]
    public function each_endpoint_needs_its_permission(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);
        $date = $this->freeDate($batch);
        $session = $this->classOn($batch, $date);

        $register = route('admin.student-attendance.api.register', ['batch_id' => $batch->getKey(), 'date' => $date]);
        $store = route('admin.student-attendance.api.store');
        $summary = route('admin.student-attendance.api.summary', ['student' => $enrollment->student_id]);
        $body = ['session_id' => $session->getKey(), 'marks' => [['student_id' => $enrollment->student_id, 'status' => 'present']]];

        $nobody = $this->createUserWithPermissions([]);
        $this->actingAs($nobody)->getJson($register)->assertForbidden();
        $this->actingAs($nobody)->postJson($store, $body)->assertForbidden();
        $this->actingAs($nobody)->getJson($summary)->assertForbidden();

        $viewer = $this->createUserWithPermissions(['student_attendance.view_any']);
        $this->actingAs($viewer)->getJson($register)->assertOk();
        $this->actingAs($viewer)->postJson($store, $body)->assertForbidden();
        $this->actingAs($viewer)->getJson($summary)->assertForbidden();

        $this->assertSame(0, StudentAttendance::query()->where('class_session_id', $session->getKey())->count());

        $marker = $this->createUserWithPermissions(['student_attendance.view_any', 'student_attendance.create']);
        $this->actingAs($marker)->postJson($store, $body)->assertOk();

        $this->actingAs($this->createUserWithPermissions(['student_attendance.view_reports']))
            ->getJson($summary)
            ->assertOk();
    }

    #[Test]
    public function another_branchs_batch_class_and_student_answer_not_found(): void
    {
        $actor = $this->createSuperAdmin();

        $here = Branch::query()->create(['name' => 'Lahore', 'code' => 'LHR', 'is_active' => true]);
        $there = Branch::query()->create(['name' => 'Karachi', 'code' => 'KHI', 'is_active' => true]);

        $theirs = $this->runningBatch(null, ['branch_id' => $there->getKey(), 'code' => 'THEIRS-API'], $actor);
        $enrollment = $this->seat($theirs, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);
        Student::query()->whereKey($enrollment->student_id)->update(['branch_id' => $there->getKey()]);

        $date = $this->freeDate($theirs);
        $session = $this->classOn($theirs, $date);

        $user = $this->createUserWithPermissions([
            'student_attendance.view_any', 'student_attendance.create', 'student_attendance.view_reports',
        ]);
        $user->forceFill(['branch_id' => $here->getKey()])->save();

        $this->actingAs($user)
            ->getJson(route('admin.student-attendance.api.register', ['batch_id' => $theirs->getKey(), 'date' => $date]))
            ->assertNotFound();

        $this->actingAs($user)
            ->postJson(route('admin.student-attendance.api.store'), [
                'session_id' => $session->getKey(),
                'marks' => [['student_id' => $enrollment->student_id, 'status' => 'present']],
            ])
            ->assertNotFound();

        $this->actingAs($user)
            ->getJson(route('admin.student-attendance.api.summary', ['student' => $enrollment->student_id]))
            ->assertNotFound();

        $this->assertSame(0, StudentAttendance::query()->where('class_session_id', $session->getKey())->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * A second class of the batch on the same day, in a later slot — `uq_cs_batch_slot` allows one class per
     * batch per start time.
     */
    private function afternoonClassOn(Batch $batch, string $date): ClassSession
    {
        $session = new ClassSession;
        $session->forceFill([
            'batch_id' => $batch->getKey(),
            'course_id' => $batch->course_id,
            'teacher_id' => $batch->teacher_id,
            'branch_id' => $batch->branch_id,
            'session_date' => $date,
            'start_time' => '14:00:00',
            'end_time' => '16:00:00',
            'status' => 'scheduled',
            'delivery_mode' => 'physical',
        ])->save();

        return $session->refresh();
    }

    /**
     * A past date on which this batch has no class yet — `runningBatch()` generates its timetable, so a
     * test that adds its own class must not land on a day that already has one.
     */
    private function freeDate(Batch $batch): string
    {
        $day = Carbon::yesterday();

        for ($i = 0; $i < 60; $i++, $day->subDay()) {
            $taken = ClassSession::query()
                ->where('batch_id', $batch->getKey())
                ->whereDate('session_date', $day->toDateString())
                ->exists();

            if (! $taken && $day->greaterThanOrEqualTo(Carbon::parse((string) $batch->start_date))) {
                return $day->toDateString();
            }
        }

        $this->fail('No free date in the batch\'s first sixty days.');
    }
}
