<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\DataObjects\Institute\SlotCandidate;
use App\DataObjects\Institute\SlotConflict;
use App\Enums\DeliveryMode;
use App\Enums\Weekday;
use App\Services\Institute\Exceptions\ScheduleClashException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\TestCase;

/**
 * The single clash authority (phase-14-17 §6.7, D47).
 *
 * **Overlap is a range condition MariaDB cannot hold as a constraint**, so these are the tests that
 * stand in for one. They check the three dimensions, the two exemptions, the half-open comparison
 * and the gap setting — and that a clash is reported in full rather than one conflict at a time.
 */
final class ScheduleClashTest extends TestCase
{
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | The three dimensions
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function one_teacher_cannot_be_in_two_places_at_once(): void
    {
        $actor = $this->createSuperAdmin();
        $teacher = $this->teacher(actor: $actor);
        $course = $this->publishedCourse(actor: $actor);

        $first = $this->batch($course, ['teacher_id' => $teacher->getKey()], $actor);
        $this->slot($first, actor: $actor);

        $second = $this->batch($course, [], $actor);

        $this->expectException(ScheduleClashException::class);

        $this->slot($second, ['teacher_id' => $teacher->getKey()], $actor);
    }

    #[Test]
    public function one_room_cannot_hold_two_classes_at_once(): void
    {
        $actor = $this->createSuperAdmin();
        $room = $this->classroom(actor: $actor);
        $course = $this->publishedCourse(actor: $actor);

        $first = $this->batch($course, ['classroom_id' => $room->getKey()], $actor);
        $this->slot($first, ['classroom_id' => $room->getKey()], $actor);

        $second = $this->batch($course, [], $actor);

        $this->expectException(ScheduleClashException::class);

        $this->slot($second, ['classroom_id' => $room->getKey()], $actor);
    }

    #[Test]
    public function a_batch_cannot_have_two_classes_at_once_and_that_one_is_never_overridable(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->batch(actor: $actor);

        $this->slot($batch, actor: $actor);

        $report = $this->detector()->check(new SlotCandidate(
            batchId: $batch->getKey(),
            startsAt: Carbon::parse($this->nextMonday()->toDateString().' 10:00'),
            endsAt: Carbon::parse($this->nextMonday()->toDateString().' 12:00'),
            dayOfWeek: Weekday::Monday,
            effectiveFrom: $this->nextMonday(),
        ));

        $this->assertFalse($report->clean);
        $this->assertTrue($report->hasBlocking(), 'students cannot be in two rooms, so this is never waved through');
        $this->assertSame(SlotConflict::BATCH, $report->conflicts[0]->dimension);
    }

    /*
    |--------------------------------------------------------------------------
    | Where the comparison lands
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function back_to_back_classes_do_not_clash(): void
    {
        $actor = $this->createSuperAdmin();
        $teacher = $this->teacher(actor: $actor);
        $course = $this->publishedCourse(actor: $actor);

        $first = $this->batch($course, ['teacher_id' => $teacher->getKey()], $actor);
        $this->slot($first, actor: $actor);

        $second = $this->batch($course, [], $actor);

        // 09:00–11:00 then 11:00–13:00: the window is half-open, so these do not overlap.
        $entry = $this->slot($second, [
            'teacher_id' => $teacher->getKey(),
            'start_time' => '11:00:00',
            'end_time' => '13:00:00',
        ], $actor);

        $this->assertTrue($entry->exists);
    }

    #[Test]
    public function a_gap_setting_pushes_two_classes_apart_for_a_teacher_but_never_for_a_batch(): void
    {
        $actor = $this->createSuperAdmin();
        $this->setting('institute.timetable_slot_gap_minutes', 30);

        $teacher = $this->teacher(actor: $actor);
        $course = $this->publishedCourse(actor: $actor);

        $first = $this->batch($course, ['teacher_id' => $teacher->getKey()], $actor);
        $this->slot($first, actor: $actor);

        $monday = $this->nextMonday();

        $teacherReport = $this->detector()->check(new SlotCandidate(
            teacherId: $teacher->getKey(),
            startsAt: Carbon::parse($monday->toDateString().' 11:00'),
            endsAt: Carbon::parse($monday->toDateString().' 12:00'),
            dayOfWeek: Weekday::Monday,
            effectiveFrom: $monday,
        ));

        $this->assertFalse($teacherReport->clean, 'a person needs the time to walk between rooms');

        $batchReport = $this->detector()->check(new SlotCandidate(
            batchId: $first->getKey(),
            startsAt: Carbon::parse($monday->toDateString().' 11:00'),
            endsAt: Carbon::parse($monday->toDateString().' 12:00'),
            dayOfWeek: Weekday::Monday,
            effectiveFrom: $monday,
        ));

        $this->assertTrue($batchReport->clean, 'a batch does not have to walk anywhere');
    }

    #[Test]
    public function two_rules_whose_date_windows_do_not_meet_are_not_a_clash(): void
    {
        $actor = $this->createSuperAdmin();
        $teacher = $this->teacher(actor: $actor);
        $course = $this->publishedCourse(actor: $actor);

        $monday = $this->nextMonday();

        $first = $this->batch($course, ['teacher_id' => $teacher->getKey()], $actor);
        $this->slot($first, [
            'effective_from' => $monday->toDateString(),
            'effective_to' => $monday->copy()->addWeeks(2)->toDateString(),
        ], $actor);

        $second = $this->batch($course, [
            'start_date' => $monday->copy()->addWeeks(4)->toDateString(),
        ], $actor);

        $entry = $this->slot($second, [
            'teacher_id' => $teacher->getKey(),
            'effective_from' => $monday->copy()->addWeeks(4)->toDateString(),
        ], $actor);

        $this->assertTrue($entry->exists, 'the same hour on the same weekday, months apart, is not one hour');
    }

    /*
    |--------------------------------------------------------------------------
    | The two exemptions
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_virtual_room_holds_as_many_classes_at_once_as_you_like(): void
    {
        $actor = $this->createSuperAdmin();
        $zoom = $this->classroom(['type' => 'virtual', 'capacity' => 500], $actor);
        $course = $this->publishedCourse(actor: $actor);

        $first = $this->batch($course, ['delivery_mode' => 'online'], $actor);
        $this->slot($first, ['classroom_id' => $zoom->getKey(), 'delivery_mode' => 'online'], $actor);

        $second = $this->batch($course, ['delivery_mode' => 'online'], $actor);

        $entry = $this->slot($second, ['classroom_id' => $zoom->getKey(), 'delivery_mode' => 'online'], $actor);

        $this->assertTrue($entry->exists, 'a meeting link is not a place two groups can collide in');
    }

    #[Test]
    public function a_virtual_room_is_refused_for_a_class_that_has_to_be_somewhere(): void
    {
        $actor = $this->createSuperAdmin();
        $zoom = $this->classroom(['type' => 'virtual'], $actor);
        $batch = $this->batch(null, [], $actor);

        // This is what keeps the room index and the detector exempting the same rows: a virtual room
        // implies an online class, so both can turn on the delivery mode alone.
        $this->expectExceptionMessage('needs somewhere it can physically be');

        $this->slot($batch, ['classroom_id' => $zoom->getKey()], $actor);
    }

    #[Test]
    public function an_online_class_occupies_no_room_whatever_room_it_names(): void
    {
        $actor = $this->createSuperAdmin();
        $room = $this->classroom(actor: $actor);
        $course = $this->publishedCourse(actor: $actor);

        $first = $this->batch($course, [], $actor);
        $this->slot($first, ['classroom_id' => $room->getKey()], $actor);

        $monday = $this->nextMonday();

        $report = $this->detector()->check(new SlotCandidate(
            classroomId: $room->getKey(),
            startsAt: Carbon::parse($monday->toDateString().' 09:00'),
            endsAt: Carbon::parse($monday->toDateString().' 11:00'),
            dayOfWeek: Weekday::Monday,
            effectiveFrom: $monday,
            deliveryMode: DeliveryMode::Online,
        ));

        $this->assertTrue($report->clean);
    }

    /*
    |--------------------------------------------------------------------------
    | What the report says
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_conflict_is_reported_at_once_not_one_at_a_time(): void
    {
        $actor = $this->createSuperAdmin();
        $teacher = $this->teacher(actor: $actor);
        $room = $this->classroom(actor: $actor);

        $batch = $this->batch(null, ['teacher_id' => $teacher->getKey(), 'classroom_id' => $room->getKey()], $actor);
        $this->slot($batch, ['classroom_id' => $room->getKey()], $actor);

        $monday = $this->nextMonday();

        $report = $this->detector()->check(new SlotCandidate(
            teacherId: $teacher->getKey(),
            classroomId: $room->getKey(),
            batchId: $batch->getKey(),
            startsAt: Carbon::parse($monday->toDateString().' 09:00'),
            endsAt: Carbon::parse($monday->toDateString().' 11:00'),
            dayOfWeek: Weekday::Monday,
            effectiveFrom: $monday,
        ));

        // Every dimension at once — the dated classes the rule produced are occupants of their own,
        // so the count is "as many as the horizon is long" rather than exactly three.
        $dimensions = array_values(array_unique(
            array_map(static fn (SlotConflict $c): string => $c->dimension, $report->conflicts),
        ));
        sort($dimensions);

        $this->assertSame(['batch', 'classroom', 'teacher'], $dimensions,
            'a coordinator who fixes the teacher and is then told about the room has done the work twice');

        $this->assertNotEmpty($report->blocking(), 'the batch dimension is never waved through');
        $this->assertNotEmpty($report->overridable(), 'the teacher and room ones can be, with a reason');
    }

    #[Test]
    public function an_edit_does_not_clash_with_itself(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->batch(actor: $actor);
        $entry = $this->slot($batch, actor: $actor);

        // Saving it unchanged: without `ignoreType`/`ignoreId`, the row would be its own conflict.
        $saved = $this->timetableService()->update($entry, [
            'day_of_week' => $entry->day_of_week->value,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
        ], $actor);

        $this->assertTrue($saved->exists);
    }

    #[Test]
    public function ending_a_rule_frees_the_hour_it_held(): void
    {
        $actor = $this->createSuperAdmin();
        $teacher = $this->teacher(actor: $actor);
        $course = $this->publishedCourse(actor: $actor);

        $first = $this->batch($course, [
            'teacher_id' => $teacher->getKey(),
            'start_date' => Carbon::today()->toDateString(),
        ], $actor);
        $entry = $this->slot($first, ['effective_from' => Carbon::today()->toDateString()], $actor);

        // Ended today: `end()` keeps classes up to and including the date given, because a class
        // already announced is not undone by a rule change. Everything after it is cancelled.
        $this->timetableService()->end($entry, Carbon::today(), 'The batch moved to mornings.', $actor);

        $second = $this->batch($course, [], $actor);

        $replacement = $this->slot($second, ['teacher_id' => $teacher->getKey()], $actor);

        $this->assertTrue($replacement->exists);
        $this->assertDatabaseHas('class_sessions', [
            'timetable_entry_id' => $entry->getKey(),
            'status' => 'cancelled',
        ]);
    }
}
