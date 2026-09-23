<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Results\Concerns;

use App\Models\Institute\Batch;
use App\Models\Institute\Exam;
use App\Models\Institute\GradeScale;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\User;
use App\Services\Institute\ExamResultService;
use App\Services\Institute\ExamService;
use App\Services\Institute\GradeScaleService;

/**
 * The Phase 20 fixtures, built through the real services (phase-19-23 §11).
 *
 * **Nothing here writes a row directly**, for the reason D108 cost a day: a fixture that assembles a
 * state the application cannot produce stops catching the bug it was written for and starts causing
 * different ones. Every helper goes through the service that owns the write, so a rule added to a
 * service later changes what these fixtures are allowed to build — which is the point.
 *
 * The one exception is `ungradedScale()`, which exists to build a *deliberately* impossible state.
 */
trait BuildsExams
{
    /**
     * Band edges at two decimals, contiguous, 0–100, one crossing from fail to pass.
     *
     * @var list<array<string, mixed>>
     */
    protected const FOUR_BANDS = [
        ['grade' => 'F', 'title' => 'Fail', 'min_percentage' => '0.00', 'max_percentage' => '39.99', 'grade_point' => '0.00', 'is_pass' => false, 'color' => 'rose'],
        ['grade' => 'C', 'title' => 'Pass', 'min_percentage' => '40.00', 'max_percentage' => '59.99', 'grade_point' => '2.00', 'is_pass' => true, 'color' => 'amber'],
        ['grade' => 'B', 'title' => 'Good', 'min_percentage' => '60.00', 'max_percentage' => '79.99', 'grade_point' => '3.00', 'is_pass' => true, 'color' => 'sky'],
        ['grade' => 'A', 'title' => 'Excellent', 'min_percentage' => '80.00', 'max_percentage' => '100.00', 'grade_point' => '4.00', 'is_pass' => true, 'color' => 'emerald'],
    ];

    protected function scaleService(): GradeScaleService
    {
        return app(GradeScaleService::class);
    }

    protected function examService(): ExamService
    {
        return app(ExamService::class);
    }

    protected function resultService(): ExamResultService
    {
        return app(ExamResultService::class);
    }

    /**
     * @param  list<array<string, mixed>>|null  $bands
     * @param  array<string, mixed>  $overrides
     */
    protected function gradeScale(?array $bands = null, array $overrides = [], ?User $actor = null): GradeScale
    {
        $actor ??= $this->createSuperAdmin();

        return $this->scaleService()->create(
            array_merge([
                'code' => 'TEST'.mb_substr(uniqid(), -6),
                'name' => 'Test scale',
                'pass_percentage' => '40.0000',
            ], $overrides),
            $bands ?? self::FOUR_BANDS,
            $actor,
        );
    }

    /** The scale `GradeScaleSeeder` puts on every install. */
    protected function seededScale(): GradeScale
    {
        return GradeScale::query()->where('code', 'DEFAULT')->firstOrFail();
    }

    /**
     * A draft exam on this batch. Times are given, so the clash check has a slot to reason about.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function draftExam(Batch $batch, array $overrides = [], ?User $actor = null): Exam
    {
        $actor ??= $this->createSuperAdmin();

        return $this->examService()->create(array_merge([
            'batch_id' => (int) $batch->getKey(),
            'exam_type' => 'midterm',
            'name' => 'Midterm '.mb_substr(uniqid(), -4),
            'scheduled_date' => now()->addDays(7)->toDateString(),
            'start_time' => '10:00',
            'end_time' => '12:00',
            'total_marks' => '100',
            'passing_marks' => '40',
        ], $overrides), $actor);
    }

    /**
     * An exam that has happened and whose sheet is open.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function conductedExam(Batch $batch, array $overrides = [], ?User $actor = null): Exam
    {
        $actor ??= $this->createSuperAdmin();

        $exam = $this->draftExam($batch, $overrides, $actor);
        $this->examService()->schedule($exam, $actor);

        return $this->examService()->markConducted($exam->refresh(), $actor);
    }

    /**
     * Seat `$count` students on the batch and return their enrolments.
     *
     * @return list<StudentBatchEnrollment>
     */
    protected function roster(Batch $batch, int $count = 3, ?User $actor = null): array
    {
        $actor ??= $this->createSuperAdmin();

        $seats = [];

        for ($i = 0; $i < $count; $i++) {
            $seats[] = $this->seat($batch, null, [], $actor);
        }

        return $seats;
    }

    /**
     * Turn `[enrolment => mark]` into the shape `saveSheet()` takes. A null mark means absent, which
     * is the distinction this whole phase turns on.
     *
     * @param  array<int, string|null>  $marks  keyed by position in `$seats`
     * @param  list<StudentBatchEnrollment>  $seats
     * @return list<array<string, mixed>>
     */
    protected function sheetRows(array $seats, array $marks): array
    {
        $rows = [];

        foreach ($seats as $index => $seat) {
            $mark = $marks[$index] ?? null;

            $rows[] = [
                'student_id' => (int) $seat->getAttribute('student_id'),
                'attendance_status' => $mark === null ? 'absent' : 'appeared',
                'obtained_marks' => $mark,
            ];
        }

        return $rows;
    }

    /**
     * Enter a sheet, have somebody else check it, and publish it — the full §2.28.4 path.
     *
     * The checker is a **second** super admin on purpose: `verify()` refuses anybody who entered a
     * mark on the sheet, so passing the same actor twice would fail and the fixture would be building
     * a state the application forbids.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function publishedExam(Exam $exam, array $rows, ?User $marker = null, ?User $checker = null): Exam
    {
        $marker ??= $this->createSuperAdmin();
        $checker ??= $this->createSuperAdmin();

        $this->resultService()->saveSheet($exam->refresh(), $rows, $marker);
        $this->resultService()->verify($exam->refresh(), $checker);

        return $this->resultService()->publish($exam->refresh(), $checker);
    }
}
