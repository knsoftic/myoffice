<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Enums\BatchStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Institute\Batch;
use App\Services\Institute\Exceptions\BatchCapacityExceeded;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\TestCase;

/**
 * Seats in batches (phase-14-17 §6.6, INV-I6, INV-I7, D48).
 *
 * **`current_students` is a cache with no authority.** These tests deliberately corrupt it and then
 * check that the enrolment decision is unaffected — because the moment a decision is made from the
 * cache, a drifted number becomes a seat given to somebody who has none.
 */
final class BatchEnrollmentTest extends TestCase
{
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Capacity
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_seat_is_given_and_the_cache_is_recounted_not_incremented(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->enrollingBatch(null, ['student_capacity' => 3], $actor);

        $enrollment = $this->seat($batch, actor: $actor);

        $this->assertSame(EnrollmentStatus::Active, $enrollment->status);
        $this->assertSame('1', $enrollment->roll_number);
        $this->assertSame(1, (int) $batch->refresh()->current_students);
    }

    #[Test]
    public function the_decision_reads_a_recount_even_when_the_cache_says_otherwise(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->enrollingBatch(null, ['student_capacity' => 2], $actor);

        // A cache claiming the batch is full. A service that believed it would refuse a real seat.
        DB::table('batches')->where('id', $batch->getKey())->update(['current_students' => 99]);

        $enrollment = $this->seat($batch, actor: $actor);

        $this->assertTrue($enrollment->exists);
        $this->assertSame(1, (int) $batch->refresh()->current_students, 'and the cache is repaired on the way past');
    }

    #[Test]
    public function the_seat_after_the_last_one_is_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->enrollingBatch(null, ['student_capacity' => 1], $actor);

        $this->seat($batch, actor: $actor);

        $this->expectException(BatchCapacityExceeded::class);

        $this->seat($batch, actor: $actor);
    }

    #[Test]
    public function overbooking_takes_the_setting_the_flag_and_a_reason(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->enrollingBatch(null, ['student_capacity' => 1], $actor);

        $this->seat($batch, actor: $actor);

        // (1) the flag alone.
        try {
            $this->seat($batch, options: ['overbook' => true, 'overbook_reason' => 'The manager said so.'], actor: $actor);
            $this->fail('overbooking is off for the institute, so nobody can exceed a capacity');
        } catch (BatchCapacityExceeded $e) {
            $this->assertStringContainsString('switched off', $e->getMessage());
        }

        $this->setting('institute.batch_allow_overbooking', true);

        // (2) the setting, but no reason.
        try {
            $this->seat($batch, options: ['overbook' => true], actor: $actor);
            $this->fail('going over capacity takes a reason');
        } catch (BatchCapacityExceeded $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }

        // (3) all three.
        $enrollment = $this->seat($batch, options: [
            'overbook' => true,
            'overbook_reason' => 'Approved by the manager.',
        ], actor: $actor);

        $this->assertTrue($enrollment->is_overbooked);
        $this->assertSame('Approved by the manager.', $enrollment->overbook_reason);
    }

    #[Test]
    public function the_room_is_a_second_ceiling_for_a_physical_batch(): void
    {
        $actor = $this->createSuperAdmin();
        $room = $this->classroom(['capacity' => 1], $actor);

        $batch = $this->enrollingBatch(null, [
            'student_capacity' => 10,
            'classroom_id' => $room->getKey(),
        ], $actor);

        $this->seat($batch, actor: $actor);

        $this->expectExceptionMessage('Somebody would be standing');

        $this->seat($batch, actor: $actor);
    }

    #[Test]
    public function the_same_student_cannot_hold_two_seats_in_one_batch(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->enrollingBatch(actor: $actor);
        $student = $this->registeredStudent($actor);

        $this->enrollmentService()->enroll($student, $batch->refresh(), null, [], $actor);

        $this->expectExceptionMessage('already enrolled');

        $this->enrollmentService()->enroll($student, $batch->refresh(), null, [], $actor);
    }

    #[Test]
    public function a_dropped_seat_frees_the_guard_so_the_same_student_can_come_back(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->enrollingBatch(actor: $actor);
        $student = $this->registeredStudent($actor);

        $first = $this->enrollmentService()->enroll($student, $batch->refresh(), null, [], $actor);
        $this->enrollmentService()->drop($first, 'They changed their mind.', $actor);

        $second = $this->enrollmentService()->enroll($student, $batch->refresh(), null, [], $actor);

        $this->assertTrue($second->exists);
        $this->assertSame(EnrollmentStatus::Dropped, $first->refresh()->status);
        $this->assertSame(1, (int) $batch->refresh()->current_students, 'only the live seat counts');
    }

    /*
    |--------------------------------------------------------------------------
    | Who may be seated at all
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_batch_that_is_not_taking_students_refuses_a_seat(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->enrollingBatch(actor: $actor);

        $this->seat($batch, actor: $actor);
        $this->batchService()->changeStatus($batch->refresh(), BatchStatus::Running, null, $actor);

        $this->expectExceptionMessage('not taking students');

        $this->seat($batch->refresh(), actor: $actor);
    }

    #[Test]
    public function an_applicant_who_is_not_registered_yet_is_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->enrollingBatch(actor: $actor);

        // Straight off the application: `applied`, not `registered`.
        $student = $this->admission(actor: $actor)->student;

        $this->expectExceptionMessage('A student takes a seat once they are registered');

        $this->enrollmentService()->enroll($student, $batch->refresh(), null, [], $actor);
    }

    /*
    |--------------------------------------------------------------------------
    | Transfers
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_transfer_leaves_the_history_in_the_batch_where_it_happened(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        $from = $this->enrollingBatch($course, ['start_time' => '09:00:00', 'end_time' => '11:00:00'], $actor);
        $to = $this->enrollingBatch($course, ['start_time' => '14:00:00', 'end_time' => '16:00:00'], $actor);

        $enrollment = $this->seat($from, actor: $actor);

        $successor = $this->enrollmentService()->transfer($enrollment, $to->refresh(), 'Moved to the afternoon.', $actor);

        $enrollment->refresh();

        $this->assertSame(EnrollmentStatus::TransferredOut, $enrollment->status);
        $this->assertSame($successor->getKey(), $enrollment->transferred_to_id);
        $this->assertSame($enrollment->getKey(), $successor->transferred_from_id);
        $this->assertSame('Moved to the afternoon.', $enrollment->transfer_reason);

        $this->assertSame(0, (int) $from->refresh()->current_students);
        $this->assertSame(1, (int) $to->refresh()->current_students);
    }

    #[Test]
    public function a_transfer_takes_a_reason(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        $from = $this->enrollingBatch($course, [], $actor);
        $to = $this->enrollingBatch($course, ['start_time' => '14:00:00', 'end_time' => '16:00:00'], $actor);

        $enrollment = $this->seat($from, actor: $actor);

        $this->expectException(CourseRuleException::class);

        $this->enrollmentService()->transfer($enrollment, $to->refresh(), '   ', $actor);
    }

    #[Test]
    public function only_one_enrolment_may_claim_a_given_successor(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        $from = $this->enrollingBatch($course, [], $actor);
        $to = $this->enrollingBatch($course, ['start_time' => '14:00:00', 'end_time' => '16:00:00'], $actor);

        $enrollment = $this->seat($from, actor: $actor);
        $successor = $this->enrollmentService()->transfer($enrollment, $to->refresh(), 'Moved.', $actor);

        $other = $this->seat($from->refresh(), actor: $actor);

        // A forked transfer chain is two answers to one question, and `uq_sbe_transfer` refuses it.
        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('student_batch_enrollments')
            ->where('id', $other->getKey())
            ->update(['transferred_to_id' => $successor->getKey()]);
    }

    /*
    |--------------------------------------------------------------------------
    | The roster, as of a date
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_roster_for_a_past_date_excludes_somebody_who_joined_afterwards(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->enrollingBatch(actor: $actor);

        $early = $this->seat($batch, options: ['enrolled_on' => now()->subMonth()->toDateString()], actor: $actor);
        $late = $this->seat($batch->refresh(), options: ['enrolled_on' => now()->toDateString()], actor: $actor);

        $then = $this->enrollmentService()->roster($batch->refresh(), now()->subWeeks(2));

        $this->assertTrue($then->contains('id', $early->getKey()));
        $this->assertFalse($then->contains('id', $late->getKey()),
            'a student who joined in week six was never absent for week two');

        $this->assertCount(2, $this->enrollmentService()->roster($batch->refresh()));
    }

    /*
    |--------------------------------------------------------------------------
    | The cache, repaired rather than believed
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_recount_is_the_only_writer_of_the_student_cache(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->enrollingBatch(null, ['student_capacity' => 5], $actor);

        $this->seat($batch, actor: $actor);
        $this->seat($batch->refresh(), actor: $actor);

        DB::table('batches')->where('id', $batch->getKey())->update(['current_students' => 0]);

        $this->assertSame(2, $this->batchService()->recountStudents(Batch::query()->findOrFail($batch->getKey())));
        $this->assertSame(2, (int) $batch->refresh()->current_students);
    }
}
