<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Enums\BatchStatus;
use App\Enums\ClassCancellationReason;
use App\Enums\ClassSessionStatus;
use App\Enums\TeacherStatus;
use App\Enums\Weekday;
use App\Models\Institute\ClassSession;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\TestCase;

/**
 * The weekly rule and the dated classes it produces (phase-14-17 §6.7, §2.30.9, D46).
 *
 * **Generation is idempotent by index**, so the nightly job, a manual run and a retry can overlap
 * without putting a class on somebody's timetable twice. These are the tests that hold that.
 */
final class TimetableTest extends TestCase
{
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | The rule
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_slot_copies_the_branch_and_the_course_from_its_batch(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->batch(actor: $actor);

        $entry = $this->slot($batch, actor: $actor);

        $this->assertSame((int) $batch->course_id, (int) $entry->course_id,
            'a slot whose course disagreed with its batch would make the course-wise view lie');
        $this->assertSame($batch->branch_id, $entry->branch_id);
        $this->assertTrue($entry->is_active);
    }

    #[Test]
    public function a_slot_inherits_the_batch_teacher_when_none_is_named(): void
    {
        $actor = $this->createSuperAdmin();
        $teacher = $this->teacher(actor: $actor);
        $batch = $this->batch(null, ['teacher_id' => $teacher->getKey()], $actor);

        $entry = $this->slot($batch, actor: $actor);

        $this->assertSame($teacher->getKey(), $entry->teacher_id);
    }

    #[Test]
    public function a_teacher_who_cannot_teach_is_refused_a_slot(): void
    {
        $actor = $this->createSuperAdmin();
        $teacher = $this->teacher(actor: $actor);
        $this->teacherService()->changeStatus($teacher, TeacherStatus::OnLeave, 'Away until March.', $actor);

        $batch = $this->batch(actor: $actor);

        $this->expectExceptionMessage('cannot be put on a timetable');

        $this->slot($batch, ['teacher_id' => $teacher->getKey()], $actor);
    }

    #[Test]
    public function a_slot_outside_the_teaching_day_is_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->batch(actor: $actor);

        $this->expectExceptionMessage('teaches between');

        $this->slot($batch, ['start_time' => '05:00:00', 'end_time' => '06:00:00'], $actor);
    }

    #[Test]
    public function a_slot_on_a_day_the_institute_is_shut_is_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $this->setting('institute.timetable_working_days', ['monday', 'tuesday']);

        $batch = $this->batch(actor: $actor);

        $this->expectExceptionMessage('does not teach on');

        $this->slot($batch, ['day_of_week' => Weekday::Sunday->value], $actor);
    }

    #[Test]
    public function seeding_from_a_batch_is_all_or_nothing(): void
    {
        $actor = $this->createSuperAdmin();
        $teacher = $this->teacher(actor: $actor);

        // A teacher already booked on the Wednesday, so the second of three days will clash.
        $blocking = $this->batch(null, ['teacher_id' => $teacher->getKey()], $actor);
        $this->slot($blocking, ['day_of_week' => Weekday::Wednesday->value], $actor);

        $batch = $this->batch(null, [
            'teacher_id' => $teacher->getKey(),
            'days' => ['monday', 'wednesday', 'friday'],
        ], $actor);

        try {
            $this->timetableService()->seedFromBatch($batch, $actor);
            $this->fail('the Wednesday clashes, so nothing should have been written');
        } catch (CourseRuleException) {
            // Expected.
        }

        $this->assertSame(0, $batch->timetableEntries()->count(),
            'a half-seeded timetable is worse than an empty one, because it looks finished');
    }

    /*
    |--------------------------------------------------------------------------
    | Generation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function generation_is_idempotent(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->batch(actor: $actor);

        $this->slot($batch, actor: $actor);

        $afterFirst = ClassSession::query()->where('batch_id', $batch->getKey())->count();
        $this->assertGreaterThan(0, $afterFirst);

        $made = $this->sessionService()->generate(
            $batch->refresh(),
            Carbon::today(),
            Carbon::today()->addWeeks(8),
            $actor,
        );

        $this->assertSame(0, $made, 'the second run creates nothing — that is what uq_cs_generated is for');
        $this->assertSame($afterFirst, ClassSession::query()->where('batch_id', $batch->getKey())->count());
    }

    #[Test]
    public function a_paused_batch_produces_no_classes(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->enrollingBatch(actor: $actor);

        $this->seat($batch, actor: $actor);
        $this->batchService()->changeStatus($batch->refresh(), BatchStatus::Running, null, $actor);
        $this->batchService()->changeStatus($batch->refresh(), BatchStatus::OnHold, 'The teacher is on leave.', $actor);

        $made = $this->sessionService()->generate(
            $batch->refresh(),
            Carbon::today(),
            Carbon::today()->addWeeks(12),
            $actor,
        );

        $this->assertSame(0, $made);
    }

    #[Test]
    public function pausing_a_batch_calls_off_the_classes_it_had_scheduled(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->enrollingBatch(actor: $actor);

        $this->seat($batch, actor: $actor);
        $this->batchService()->changeStatus($batch->refresh(), BatchStatus::Running, null, $actor);

        $this->assertGreaterThan(0, ClassSession::query()
            ->where('batch_id', $batch->getKey())
            ->where('status', ClassSessionStatus::Scheduled->value)
            ->count());

        $this->batchService()->changeStatus($batch->refresh(), BatchStatus::OnHold, 'Nobody to teach it.', $actor);

        $this->assertSame(0, ClassSession::query()
            ->where('batch_id', $batch->getKey())
            ->where('status', ClassSessionStatus::Scheduled->value)
            ->whereDate('session_date', '>=', Carbon::today()->toDateString())
            ->count(), 'nobody should turn up to a class that is not happening');
    }

    /*
    |--------------------------------------------------------------------------
    | §2.30.9 — the four moves
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function cancelling_a_class_takes_a_reason_and_a_detail_and_keeps_the_class_visible(): void
    {
        $actor = $this->createSuperAdmin();
        $session = $this->firstSessionOf($actor);

        try {
            $this->sessionService()->cancel($session, ClassCancellationReason::Holiday, '   ', $actor);
            $this->fail('"cancelled" with no detail is what makes people turn up anyway');
        } catch (CourseRuleException) {
            // Expected.
        }

        $this->sessionService()->cancel($session, ClassCancellationReason::Holiday, 'Public holiday.', $actor);

        $session->refresh();

        $this->assertSame(ClassSessionStatus::Cancelled, $session->status);
        $this->assertSame('Public holiday.', $session->cancellation_detail);
        $this->assertNull($session->active_guard, 'the hour is released');
        $this->assertDatabaseHas('class_sessions', ['id' => $session->getKey()], );
    }

    #[Test]
    public function rescheduling_creates_a_successor_and_links_both_ways(): void
    {
        $actor = $this->createSuperAdmin();
        $session = $this->firstSessionOf($actor);

        $successor = $this->sessionService()->reschedule($session, [
            'session_date' => Carbon::parse($session->session_date->toDateString())->addDays(2)->toDateString(),
            'start_time' => '14:00:00',
            'end_time' => '16:00:00',
        ], 'The room was flooded.', $actor);

        $session->refresh();

        $this->assertSame(ClassSessionStatus::Rescheduled, $session->status);
        $this->assertSame($successor->getKey(), $session->rescheduled_to_id);
        $this->assertSame($session->getKey(), $successor->rescheduled_from_id);
        $this->assertSame(ClassSessionStatus::Scheduled, $successor->status);
        $this->assertNull($successor->timetable_entry_id, 'the successor is not what the rule produces for that date');
    }

    #[Test]
    public function a_substitution_remembers_who_was_supposed_to_teach_it(): void
    {
        $actor = $this->createSuperAdmin();
        $session = $this->firstSessionOf($actor);
        $original = $session->teacher_id;

        $substitute = $this->teacher(actor: $actor);

        $this->sessionService()->substituteTeacher($session, $substitute, 'Sick leave.', $actor);

        $session->refresh();

        $this->assertSame($substitute->getKey(), $session->teacher_id);
        $this->assertSame($original, $session->original_teacher_id);

        // A second substitution must not erase the first answer.
        $third = $this->teacher(actor: $actor);
        $this->sessionService()->substituteTeacher($session, $third, 'Also sick.', $actor);

        $this->assertSame($original, $session->refresh()->original_teacher_id,
            '"how many classes did this teacher miss" has to stay answerable');
    }

    #[Test]
    public function marking_a_class_held_recounts_the_batch(): void
    {
        $actor = $this->createSuperAdmin();
        $session = $this->firstSessionOf($actor);

        $this->sessionService()->markHeld($session, $actor);

        $this->assertSame(ClassSessionStatus::Held, $session->refresh()->status);
        $this->assertSame(1, (int) $session->batch->refresh()->sessions_held_count);
    }

    #[Test]
    public function a_cancelled_class_takes_no_further_move(): void
    {
        $actor = $this->createSuperAdmin();
        $session = $this->firstSessionOf($actor);

        $this->sessionService()->cancel($session, ClassCancellationReason::Holiday, 'Public holiday.', $actor);

        $this->expectExceptionMessage('cannot go from');

        $this->sessionService()->markHeld($session->refresh(), $actor);
    }

    #[Test]
    public function editing_a_rule_rebuilds_the_future_and_leaves_what_was_taught_alone(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->batch(actor: $actor);
        $entry = $this->slot($batch, actor: $actor);

        $held = ClassSession::query()->where('timetable_entry_id', $entry->getKey())->orderBy('session_date')->firstOrFail();
        $this->sessionService()->markHeld($held, $actor);

        $this->timetableService()->update($entry, [
            'day_of_week' => $entry->day_of_week->value,
            'start_time' => '14:00:00',
            'end_time' => '16:00:00',
        ], $actor);

        $held->refresh();

        $this->assertSame('09:00:00', $held->start_time,
            'a class already taught keeps the hour it was actually taught at');

        $future = ClassSession::query()
            ->where('timetable_entry_id', $entry->getKey())
            ->where('status', ClassSessionStatus::Scheduled->value)
            ->get();

        $this->assertNotEmpty($future);
        $this->assertTrue($future->every(fn (ClassSession $s): bool => $s->start_time === '14:00:00'));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function firstSessionOf(\App\Models\User $actor): ClassSession
    {
        // With a teacher: a class that started with nobody on it has no "who was supposed to
        // take this" to remember, and that is a different test from the ones these are.
        $batch = $this->batch(null, ['teacher_id' => $this->teacher(actor: $actor)->getKey()], $actor);
        $this->slot($batch, actor: $actor);

        return ClassSession::query()
            ->where('batch_id', $batch->getKey())
            ->orderBy('session_date')
            ->firstOrFail();
    }
}
