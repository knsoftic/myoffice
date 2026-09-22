<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Concerns;

use App\Enums\StudentAttendanceStatus;
use App\Models\Institute\Batch;
use App\Models\Institute\ClassSession;
use App\Models\Institute\Course;
use App\Models\Institute\CourseModule;
use App\Models\Institute\CourseTopic;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\User;
use App\Services\Institute\AttendanceReportService;
use App\Services\Institute\AttendanceService;
use App\Services\Institute\CourseProgressService;
use Illuminate\Support\Carbon;

/**
 * Fixtures for the Phase 17 suite.
 *
 * Everything goes through the service that owns it, like the three concerns before it. A fixture that
 * inserted an attendance row directly would be testing a shape the application never produces — and
 * the first thing it would stop catching is the roster check of INV-I9.
 */
trait BuildsRegisters
{
    use BuildsSchedules;

    protected function attendanceService(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    protected function reportService(): AttendanceReportService
    {
        return app(AttendanceReportService::class);
    }

    protected function progressService(): CourseProgressService
    {
        return app(CourseProgressService::class);
    }

    /**
     * A course with a real three-level outline, so the progress arithmetic has something to chew.
     *
     * @param  list<int>  $weights  one weight per topic
     */
    protected function courseWithOutline(array $weights = [1, 1, 1], ?User $actor = null): Course
    {
        $actor ??= $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        // `publishedCourse()` ships one module with one weight-2 topic — the smallest tree Phase 14
        // needed. These tests are about exact arithmetic, so that topic is deactivated rather than
        // added to: a denominator with a stray weight in it makes every expected figure a guess.
        // Deactivating is also what the application does (INV-I13 forbids deleting a referenced
        // curriculum row), so the fixture stays a shape the system can actually produce.
        CourseTopic::query()->where('course_id', $course->getKey())->update(['is_active' => false]);
        CourseModule::query()->where('course_id', $course->getKey())->update(['is_active' => false]);

        $module = new CourseModule;
        $module->forceFill([
            'course_id' => $course->getKey(),
            'title' => 'Module one',
            'sort_order' => 1,
            'is_active' => true,
        ])->save();

        foreach ($weights as $index => $weight) {
            $topic = new CourseTopic;
            $topic->forceFill([
                'course_module_id' => $module->getKey(),
                'course_id' => $course->getKey(),
                'title' => 'Topic '.($index + 1),
                'sort_order' => $index + 1,
                'weight' => $weight,
                'is_active' => true,
            ])->save();
        }

        return $course->refresh();
    }

    /**
     * A published course with no ACTIVE outline.
     *
     * `publishedCourse()` always builds the smallest tree, so "no outline" is reached the way the
     * application reaches it — by deactivating what is there (INV-I13 forbids deleting a referenced
     * curriculum row). What the recompute sees either way is a course with nothing active in it.
     */
    protected function courseWithNoOutline(?User $actor = null): Course
    {
        $actor ??= $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        CourseTopic::query()->where('course_id', $course->getKey())->update(['is_active' => false]);
        CourseModule::query()->where('course_id', $course->getKey())->update(['is_active' => false]);

        return $course->refresh();
    }

    /**
     * A running batch with a timetable, a roster, and classes already generated.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function runningBatch(?Course $course = null, array $overrides = [], ?User $actor = null): Batch
    {
        $actor ??= $this->createSuperAdmin();
        $course ??= $this->courseWithOutline(actor: $actor);

        $batch = $this->enrollingBatch($course, array_merge([
            // Starting a month back, so there are held classes to mark.
            'start_date' => Carbon::today()->subMonth()->toDateString(),
        ], $overrides), $actor);

        return $batch->refresh();
    }

    /**
     * A class of this batch, on a date, in a given state.
     */
    protected function classOn(Batch $batch, string $date, string $status = 'held'): ClassSession
    {
        $session = new ClassSession;

        $session->forceFill([
            'batch_id' => $batch->getKey(),
            'course_id' => $batch->course_id,
            'teacher_id' => $batch->teacher_id,
            'branch_id' => $batch->branch_id,
            'session_date' => $date,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'status' => $status,
            'delivery_mode' => 'physical',
        ])->save();

        return $session->refresh();
    }

    /**
     * Mark one student in one class, through the service.
     */
    protected function markOne(
        ClassSession $session,
        StudentBatchEnrollment $enrollment,
        StudentAttendanceStatus $status,
        array $extra = [],
        ?User $actor = null,
    ): void {
        $this->attendanceService()->mark($session, [
            (int) $enrollment->student_id => array_merge(['status' => $status->value], $extra),
        ], [], $actor ?? $this->createSuperAdmin());
    }

    /**
     * The active topics of a course, in outline order.
     */
    protected function topicsOf(Course $course)
    {
        return CourseTopic::query()
            ->where('course_id', $course->getKey())
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }
}
