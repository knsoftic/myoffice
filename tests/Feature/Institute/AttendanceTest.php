<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Enums\ClassCancellationReason;
use App\Enums\StudentAttendanceStatus;
use App\Models\Institute\StudentAttendance;
use App\Services\Institute\Exceptions\AttendanceRuleException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\TestCase;

/**
 * The register (§75, phase-14-17 §6.9; FT-39 to FT-43).
 *
 * **The arithmetic is the point.** A percentage that is right on a happy path and wrong when a class
 * is cancelled, a student joins late, or somebody takes approved leave is a percentage nobody can
 * defend to the student it belongs to.
 */
final class AttendanceTest extends TestCase
{
    use BuildsRegisters;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | FT-39 — the roster membership (INV-I9)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_student_who_was_not_enrolled_on_that_date_cannot_be_marked(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);

        $early = $this->classOn($batch, Carbon::today()->subWeeks(3)->toDateString());

        // Enrolled last week — after that class was held.
        $late = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subWeek()->toDateString()], actor: $actor);

        $this->assertFalse(
            $this->attendanceService()->roster($early)->contains('student_id', $late->student_id),
            'a student who joined in week six is not expected at week two',
        );

        $this->expectException(AttendanceRuleException::class);
        $this->expectExceptionMessageMatches('/was not enrolled/');

        $this->markOne($early, $late, StudentAttendanceStatus::Present, actor: $actor);
    }

    #[Test]
    public function a_cancelled_class_cannot_be_marked_at_all(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $session = $this->classOn($batch, Carbon::today()->subDays(3)->toDateString());
        $this->sessionService()->cancel($session, ClassCancellationReason::Holiday, 'Public holiday.', $actor);

        $this->expectExceptionMessageMatches('/cannot be recorded/');

        $this->markOne($session->refresh(), $enrollment, StudentAttendanceStatus::Absent, actor: $actor);
    }

    /*
    |--------------------------------------------------------------------------
    | FT-40 — one row per student, however many times it is submitted
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function submitting_the_same_register_twice_keeps_one_row_per_student(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $session = $this->classOn($batch, Carbon::today()->subDays(2)->toDateString());

        $first = $this->attendanceService()->mark($session, [
            (int) $enrollment->student_id => ['status' => 'present'],
        ], [], $actor);

        $second = $this->attendanceService()->mark($session->refresh(), [
            (int) $enrollment->student_id => ['status' => 'absent'],
        ], [], $actor);

        $this->assertSame(1, $first->created);
        $this->assertSame(0, $second->created);
        $this->assertSame(1, $second->updated);

        $this->assertSame(1, StudentAttendance::query()
            ->where('class_session_id', $session->getKey())
            ->count(), 'uq_sa_session_student is what makes a double submit an update');

        // The later values win, and the session counters agree with the rows.
        $session->refresh();
        $this->assertSame(0, (int) $session->present_count);
        $this->assertSame(1, (int) $session->absent_count);
    }

    #[Test]
    public function marking_a_register_moves_the_class_to_held_and_stamps_who_took_it(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $session = $this->classOn($batch, Carbon::today()->subDay()->toDateString(), 'scheduled');

        $this->markOne($session, $enrollment, StudentAttendanceStatus::Present, actor: $actor);

        $session->refresh();

        $this->assertSame('held', $session->status->value, 'a class with a register is a class that happened');
        $this->assertNotNull($session->attendance_marked_at);
        $this->assertSame($actor->getKey(), $session->attendance_marked_by);
    }

    /*
    |--------------------------------------------------------------------------
    | FT-41 — the lock window and the audit (INV-I10)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_correction_inside_the_window_needs_no_reason_and_is_not_an_amendment(): void
    {
        $actor = $this->createSuperAdmin();
        $row = $this->oneMarkedRow($actor);

        $this->attendanceService()->amend($row, StudentAttendanceStatus::Absent, null, $actor);

        $row->refresh();

        $this->assertSame('absent', $row->status->value);
        $this->assertNull($row->amended_at, 'inside the window the register is still being taken');
    }

    #[Test]
    public function after_the_window_a_correction_needs_the_permission_and_a_reason(): void
    {
        $actor = $this->createSuperAdmin();
        $row = $this->oneMarkedRow($actor);

        // Push it outside the window.
        DB::table('student_attendances')->where('id', $row->getKey())
            ->update(['marked_at' => Carbon::now()->subDays(30)]);

        $row = StudentAttendance::query()->findOrFail($row->getKey());

        $this->assertFalse($row->isWithinLockWindow());

        // Without the edit permission.
        $marker = $this->createUserWithPermissions([
            'student_attendance.view_any', 'student_attendance.view', 'student_attendance.create',
        ]);

        try {
            $this->attendanceService()->amend($row, StudentAttendanceStatus::Leave, 'Because.', $marker);
            $this->fail('revising a reported register is a different right from taking one');
        } catch (AttendanceRuleException $e) {
            $this->assertStringContainsString('edit permission', $e->getMessage());
        }

        // With it, but no reason.
        try {
            $this->attendanceService()->amend($row, StudentAttendanceStatus::Leave, '   ', $actor);
            $this->fail('a correction after the window takes a reason');
        } catch (AttendanceRuleException $e) {
            $this->assertStringContainsString('takes a reason', $e->getMessage());
        }

        // With both.
        $this->attendanceService()->amend($row, StudentAttendanceStatus::Leave, 'Taken before they arrived.', $actor);

        $row->refresh();

        $this->assertSame('leave', $row->status->value);
        $this->assertNotNull($row->amended_at);
        $this->assertSame($actor->getKey(), $row->amended_by);
        $this->assertSame('Taken before they arrived.', $row->amendment_reason);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => StudentAttendance::class,
            'subject_id' => $row->getKey(),
            // The log name goes into `description`; `event` is the model event that caused the row.
            'description' => 'attendance.amended',
        ]);
    }

    #[Test]
    public function attendance_is_never_deleted(): void
    {
        $actor = $this->createSuperAdmin();
        $row = $this->oneMarkedRow($actor);

        // The policy refuses it for everybody the policy actually runs for.
        $editor = $this->createUserWithPermissions([
            'student_attendance.view_any', 'student_attendance.view',
            'student_attendance.edit', 'student_attendance.delete',
        ]);

        $this->assertTrue($editor->can('student_attendance.delete'), 'the ability exists on the module');
        $this->assertFalse($editor->can('delete', $row), 'and the policy refuses it anyway');

        // And `Gate::before` lets a Super Admin past every policy, so the policy alone cannot hold
        // INV-I10. The model is the layer that does — for every caller, with no exception.
        $this->assertTrue($actor->can('delete', $row), 'Gate::before allows a Super Admin the ability');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/may not be deleted/');

        $row->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | FT-42 — the grace window
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_present_mark_past_the_grace_window_is_stored_as_late(): void
    {
        $actor = $this->createSuperAdmin();
        $this->setting('institute.attendance_grace_minutes', 15);

        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $session = $this->classOn($batch, Carbon::today()->subDay()->toDateString());

        // The class starts at 09:00; they arrive at 09:40.
        $result = $this->attendanceService()->mark($session, [
            (int) $enrollment->student_id => ['status' => 'present', 'check_in_time' => '09:40:00'],
        ], [], $actor);

        $row = StudentAttendance::query()
            ->where('class_session_id', $session->getKey())
            ->firstOrFail();

        $this->assertSame('late', $row->status->value);
        $this->assertSame(40, (int) $row->minutes_late);
        $this->assertNotEmpty($result->promoted, 'the screen must say so rather than let it be found in a report');

        // Ten minutes late is inside the grace and stays present.
        $other = $this->classOn($batch, Carbon::today()->subDays(2)->toDateString());

        $this->attendanceService()->mark($other, [
            (int) $enrollment->student_id => ['status' => 'present', 'check_in_time' => '09:10:00'],
        ], [], $actor);

        $this->assertSame(StudentAttendanceStatus::Present, StudentAttendance::query()
            ->where('class_session_id', $other->getKey())
            ->value('status'), 'ten minutes inside a fifteen-minute grace is not late');
    }

    #[Test]
    public function unmarked_students_are_filled_as_absent_only_when_the_institute_asks(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $this->setting('institute.attendance_auto_absent_on_close', false);

        $session = $this->classOn($batch, Carbon::today()->subDay()->toDateString(), 'scheduled');
        $this->sessionService()->markHeld($session, $actor);

        $this->assertSame(0, StudentAttendance::query()->where('class_session_id', $session->getKey())->count(),
            'off by default: an absence nobody decided is still an absence on somebody\'s record');

        $this->setting('institute.attendance_auto_absent_on_close', true);

        $second = $this->classOn($batch, Carbon::today()->subDays(2)->toDateString(), 'scheduled');
        $this->sessionService()->markHeld($second, $actor);

        $row = StudentAttendance::query()->where('class_session_id', $second->getKey())->first();

        $this->assertNotNull($row);
        $this->assertSame('absent', $row->status->value);
        $this->assertSame('system', $row->marked_via->value, 'so the difference stays visible');
        $this->assertFalse($row->marked_via->isHumanJudgement());
    }

    /*
    |--------------------------------------------------------------------------
    | FT-43 — the percentage (INV-I11)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function two_of_three_stores_sixty_six_point_six_seven(): void
    {
        $actor = $this->createSuperAdmin();
        $this->setting('institute.attendance_leave_counts_in_denominator', false);

        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        foreach (['present', 'late', 'absent'] as $index => $status) {
            $session = $this->classOn($batch, Carbon::today()->subDays($index + 1)->toDateString());
            $this->markOne($session, $enrollment, StudentAttendanceStatus::from($status), actor: $actor);
        }

        $enrollment->refresh();

        // Half-up at two, in the decimal(8,4) column every percentage in the system uses.
        $this->assertSame('66.6700', (string) $enrollment->attendance_percentage);
        $this->assertSame(3, (int) $enrollment->sessions_expected_count);
        $this->assertSame(1, (int) $enrollment->present_count);
        $this->assertSame(1, (int) $enrollment->late_count);
        $this->assertSame(1, (int) $enrollment->absent_count);
    }

    #[Test]
    public function cancelled_and_rescheduled_classes_are_in_neither_side(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $held = $this->classOn($batch, Carbon::today()->subDays(5)->toDateString());
        $this->markOne($held, $enrollment, StudentAttendanceStatus::Present, actor: $actor);

        $this->attendanceService()->recountEnrollment($enrollment->refresh());
        $this->assertSame('100.0000', (string) $enrollment->refresh()->attendance_percentage);

        // Cancelling a class that already has a register is refused outright — it happened, and the
        // register says so.
        $marked = $this->classOn($batch, Carbon::today()->subDays(4)->toDateString());
        $this->markOne($marked, $enrollment, StudentAttendanceStatus::Absent, actor: $actor);

        try {
            $this->sessionService()->cancel($marked->refresh(), ClassCancellationReason::Holiday, 'Strike.', $actor);
            $this->fail('a class with a register cannot be called off');
        } catch (\App\Services\Institute\Exceptions\CourseRuleException $e) {
            $this->assertStringContainsString('people were in the room', $e->getMessage());
        }

        // The status can still move by a query-builder update — which is exactly what
        // `BatchService::cancelFutureSessions()` does when a batch goes on hold. The recount's join
        // filters on the session status, so such a row leaves both sides of the fraction.
        DB::table('class_sessions')->where('id', $marked->getKey())->update([
            'status' => 'cancelled',
            'cancellation_reason' => 'batch_on_hold',
            'cancellation_detail' => 'The batch was paused.',
        ]);

        $this->attendanceService()->recountEnrollment($enrollment->refresh());

        $this->assertSame('100.0000', (string) $enrollment->refresh()->attendance_percentage,
            'a student is not marked down for the institute\'s own decisions');
        $this->assertSame(1, (int) $enrollment->refresh()->sessions_expected_count);
    }

    #[Test]
    public function leave_enters_the_denominator_only_when_the_setting_says_so(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $this->markOne($this->classOn($batch, Carbon::today()->subDays(3)->toDateString()), $enrollment, StudentAttendanceStatus::Present, actor: $actor);
        $this->markOne($this->classOn($batch, Carbon::today()->subDays(2)->toDateString()), $enrollment, StudentAttendanceStatus::Leave, actor: $actor);

        $this->setting('institute.attendance_leave_counts_in_denominator', false);
        $this->attendanceService()->recountEnrollment($enrollment->refresh());
        $this->assertSame('100.0000', (string) $enrollment->refresh()->attendance_percentage, 'excused');

        $this->setting('institute.attendance_leave_counts_in_denominator', true);
        $this->attendanceService()->recountEnrollment($enrollment->refresh());
        $this->assertSame('50.0000', (string) $enrollment->refresh()->attendance_percentage, 'counted');
    }

    #[Test]
    public function the_recount_command_reproduces_every_stored_value(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        foreach (['present', 'absent', 'present'] as $index => $status) {
            $session = $this->classOn($batch, Carbon::today()->subDays($index + 1)->toDateString());
            $this->markOne($session, $enrollment, StudentAttendanceStatus::from($status), actor: $actor);
        }

        $stored = (string) $enrollment->refresh()->attendance_percentage;

        // Corrupt the cache, then let the nightly command re-derive it.
        DB::table('student_batch_enrollments')->where('id', $enrollment->getKey())
            ->update(['attendance_percentage' => '1.0000', 'present_count' => 99]);

        $this->artisan('attendance:recount', ['--batch' => $batch->getKey()])
            ->expectsOutputToContain('had drifted')
            ->assertSuccessful();

        $this->assertSame($stored, (string) $enrollment->refresh()->attendance_percentage,
            'every stored percentage is re-derivable from the register rows (INV-I11)');
        $this->assertSame(2, (int) $enrollment->refresh()->present_count);
    }

    #[Test]
    public function the_unmarked_sweep_lists_classes_and_marks_nothing(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $this->classOn($batch, Carbon::today()->subDays(2)->toDateString());

        $before = StudentAttendance::query()->count();

        $this->artisan('attendance:flag-unmarked')
            ->expectsOutputToContain('without a register')
            ->assertSuccessful();

        $this->assertSame($before, StudentAttendance::query()->count(),
            'who was in the room is a fact only a person has — a command must not invent it');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function oneMarkedRow(\App\Models\User $actor): StudentAttendance
    {
        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $session = $this->classOn($batch, Carbon::today()->subDay()->toDateString());
        $this->markOne($session, $enrollment, StudentAttendanceStatus::Present, actor: $actor);

        return StudentAttendance::query()
            ->where('class_session_id', $session->getKey())
            ->firstOrFail();
    }
}
