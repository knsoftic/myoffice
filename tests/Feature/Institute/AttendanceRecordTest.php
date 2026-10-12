<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Models\Institute\Batch;
use App\Models\Institute\ClassSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\TestCase;

/**
 * "Record attendance" on the attendance screen (`AttendanceRecordController`): a batch and a date open that
 * day's register, and a day with no class gets an extra one-off class first — never a second one.
 */
final class AttendanceRecordTest extends TestCase
{
    use BuildsRegisters;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function a_day_with_one_class_opens_its_register(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $date = $this->freeDate($batch);
        $session = $this->classOn($batch, $date, 'scheduled');

        $this->actingAs($actor)
            ->post(route('admin.student-attendance.record'), ['record_batch_id' => $batch->getKey(), 'record_date' => $date])
            ->assertRedirect(route('admin.student-attendance.mark', $session));

        $this->assertSame(1, $this->classesOn($batch, $date));
    }

    #[Test]
    public function a_day_with_no_class_gets_one_extra_class_and_only_one(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $date = $this->freeDate($batch);

        $this->actingAs($actor)
            ->post(route('admin.student-attendance.record'), [
                'record_batch_id' => $batch->getKey(),
                'record_date' => $date,
                'record_start_time' => '18:00',
                'record_end_time' => '19:00',
                'record_title' => 'Make-up class',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $session = ClassSession::query()->where('batch_id', $batch->getKey())->whereDate('session_date', $date)->firstOrFail();

        $this->assertSame('18:00:00', (string) $session->start_time);
        $this->assertSame('19:00:00', (string) $session->end_time);
        $this->assertSame('Make-up class', $session->title);
        $this->assertNull($session->timetable_entry_id, 'A one-off class, not a timetable slot.');

        $this->actingAs($actor)
            ->post(route('admin.student-attendance.record'), ['record_batch_id' => $batch->getKey(), 'record_date' => $date])
            ->assertRedirect(route('admin.student-attendance.mark', $session));

        $this->assertSame(1, $this->classesOn($batch, $date), 'Recording the same day twice reuses the class.');
    }

    #[Test]
    public function a_day_with_several_classes_lists_them_to_pick_one(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $date = $this->freeDate($batch);
        $this->classOn($batch, $date, 'scheduled');
        $this->afternoonClassOn($batch, $date);

        $this->actingAs($actor)
            ->post(route('admin.student-attendance.record'), ['record_batch_id' => $batch->getKey(), 'record_date' => $date])
            ->assertRedirect(route('admin.student-attendance.index', ['date' => $date, 'batch_id' => $batch->getKey()]));
    }

    #[Test]
    public function adding_a_class_needs_permission_to_schedule_one(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);
        $date = $this->freeDate($batch);

        $marker = $this->createUserWithPermissions(['student_attendance.view_any', 'student_attendance.create']);

        $this->actingAs($marker)
            ->from(route('admin.student-attendance.index'))
            ->post(route('admin.student-attendance.record'), [
                'record_batch_id' => $batch->getKey(),
                'record_date' => $date,
                'record_start_time' => '18:00',
                'record_end_time' => '19:00',
            ])
            ->assertRedirect(route('admin.student-attendance.index'))
            ->assertSessionHasErrors('record_date');

        $this->assertSame(0, $this->classesOn($batch, $date));
    }

    #[Test]
    public function a_future_date_or_backwards_times_are_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);

        $this->actingAs($actor)
            ->post(route('admin.student-attendance.record'), [
                'record_batch_id' => $batch->getKey(),
                'record_date' => Carbon::tomorrow()->toDateString(),
            ])
            ->assertSessionHasErrors('record_date');

        $this->actingAs($actor)
            ->post(route('admin.student-attendance.record'), [
                'record_batch_id' => $batch->getKey(),
                'record_date' => $this->freeDate($batch),
                'record_start_time' => '19:00',
                'record_end_time' => '18:00',
            ])
            ->assertSessionHasErrors('record_end_time');
    }

    #[Test]
    public function the_form_needs_the_create_permission(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->runningBatch(actor: $actor);

        $viewer = $this->createUserWithPermissions(['student_attendance.view_any']);

        $this->actingAs($viewer)
            ->get(route('admin.student-attendance.index'))
            ->assertOk()
            ->assertDontSee('id="record-attendance-form"', false);

        $this->actingAs($viewer)
            ->post(route('admin.student-attendance.record'), ['record_batch_id' => $batch->getKey(), 'record_date' => $this->freeDate($batch)])
            ->assertForbidden();

        $this->actingAs($this->createUserWithPermissions(['student_attendance.view_any', 'student_attendance.create']))
            ->get(route('admin.student-attendance.index'))
            ->assertOk()
            ->assertSee('id="record-attendance-form"', false)
            ->assertSee('Record attendance');
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    private function classesOn(Batch $batch, string $date): int
    {
        return ClassSession::query()
            ->where('batch_id', $batch->getKey())
            ->whereDate('session_date', $date)
            ->whereIn('status', ['scheduled', 'held'])
            ->count();
    }

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
     * A past date, inside the batch, with no class yet — `runningBatch()` generates a timetable.
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
