<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Concerns;

use App\Enums\BatchStatus;
use App\Enums\Weekday;
use App\Models\Institute\Batch;
use App\Models\Institute\Classroom;
use App\Models\Institute\Course;
use App\Models\Institute\Student;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\Institute\Teacher;
use App\Models\Institute\TimetableEntry;
use App\Models\User;
use App\Services\Institute\BatchEnrollmentService;
use App\Services\Institute\BatchService;
use App\Services\Institute\ClassroomService;
use App\Services\Institute\ClassSessionService;
use App\Services\Institute\ScheduleClashDetector;
use App\Services\Institute\TeacherService;
use App\Services\Institute\TimetableService;
use Illuminate\Support\Carbon;

/**
 * Fixtures for the Phase 16 suite.
 *
 * Everything goes through the service that owns it, like the two concerns before it: a fixture that
 * inserted a row directly would be testing a shape the application never produces, and the first
 * thing it would stop catching is a service forgetting to issue a code or recount a cache.
 */
trait BuildsSchedules
{
    use BuildsAdmissions;

    private int $scheduleSequence = 0;

    protected function teacherService(): TeacherService
    {
        return app(TeacherService::class);
    }

    protected function classroomService(): ClassroomService
    {
        return app(ClassroomService::class);
    }

    protected function batchService(): BatchService
    {
        return app(BatchService::class);
    }

    protected function enrollmentService(): BatchEnrollmentService
    {
        return app(BatchEnrollmentService::class);
    }

    protected function timetableService(): TimetableService
    {
        return app(TimetableService::class);
    }

    protected function sessionService(): ClassSessionService
    {
        return app(ClassSessionService::class);
    }

    protected function detector(): ScheduleClashDetector
    {
        return app(ScheduleClashDetector::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function teacher(array $overrides = [], ?User $actor = null): Teacher
    {
        $this->scheduleSequence++;

        return $this->teacherService()->create(array_merge([
            'name' => 'Teacher '.$this->scheduleSequence,
            'phone' => '0344'.str_pad((string) $this->scheduleSequence, 7, '0', STR_PAD_LEFT),
            'create_login' => false,
        ], $overrides), $actor);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function classroom(array $overrides = [], ?User $actor = null): Classroom
    {
        $this->scheduleSequence++;

        return $this->classroomService()->create(array_merge([
            'code' => 'ROOM-'.$this->scheduleSequence,
            'name' => 'Room '.$this->scheduleSequence,
            'type' => 'classroom',
            'capacity' => 20,
        ], $overrides), $actor);
    }

    /**
     * A planned batch. `$overrides` takes the same keys the form posts.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function batch(?Course $course = null, array $overrides = [], ?User $actor = null): Batch
    {
        $this->scheduleSequence++;
        $actor ??= $this->createSuperAdmin();
        $course ??= $this->publishedCourse(actor: $actor);

        return $this->batchService()->create(array_merge([
            'code' => 'BATCH-'.$this->scheduleSequence,
            'name' => 'Batch '.$this->scheduleSequence,
            'course_id' => $course->getKey(),
            'start_date' => $this->nextMonday()->toDateString(),
            'days' => ['monday'],
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'student_capacity' => 10,
            'delivery_mode' => 'physical',
        ], $overrides), $actor);
    }

    /**
     * A batch that is taking students — the state enrolment needs, reached the way the app reaches
     * it rather than by writing the column.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function enrollingBatch(?Course $course = null, array $overrides = [], ?User $actor = null): Batch
    {
        $actor ??= $this->createSuperAdmin();

        // §2.30.7: a batch opens for admission once it has a teacher AND a timetable. The fixture
        // supplies a teacher rather than skipping the guard, so the state is reached the way the
        // application reaches it.
        $overrides['teacher_id'] ??= $this->teacher(actor: $actor)->getKey();

        $batch = $this->batch($course, $overrides, $actor);

        $this->timetableService()->seedFromBatch($batch, $actor);

        return $this->batchService()->changeStatus($batch->refresh(), BatchStatus::Enrolling, null, $actor);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function slot(Batch $batch, array $overrides = [], ?User $actor = null): TimetableEntry
    {
        return $this->timetableService()->create($batch, array_merge([
            'day_of_week' => Weekday::Monday->value,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'effective_from' => $this->nextMonday()->toDateString(),
        ], $overrides), $actor);
    }

    /**
     * A registered student — the state `enroll()` requires.
     */
    protected function registeredStudent(?User $actor = null): Student
    {
        $actor ??= $this->createSuperAdmin();

        $admission = $this->admission(actor: $actor);

        return $this->admissionService()->register($admission, $actor)->student->refresh();
    }

    protected function seat(Batch $batch, ?Student $student = null, array $options = [], ?User $actor = null): StudentBatchEnrollment
    {
        $actor ??= $this->createSuperAdmin();
        $student ??= $this->registeredStudent($actor);

        return $this->enrollmentService()->enroll($student, $batch->refresh(), null, $options, $actor);
    }

    /**
     * A fixed Monday in the future, so a test never depends on the day it runs.
     */
    protected function nextMonday(): Carbon
    {
        return Carbon::today()->next(Carbon::MONDAY);
    }
}
