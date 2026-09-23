<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Institute\SlotCandidate;
use App\Enums\BatchStatus;
use App\Enums\DeliveryMode;
use App\Enums\ExamAttendanceStatus;
use App\Enums\ExamStatus;
use App\Enums\ExamType;
use App\Models\Institute\Batch;
use App\Models\Institute\CourseTopic;
use App\Models\Institute\Exam;
use App\Models\Institute\GradeScale;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Scheduling and running an exam (phase-19-23 §6.10, requirement §81).
 *
 * **Double-booking is one generic call, never three subject-specific ones.**
 * `ScheduleClashDetector::check(SlotCandidate)` asks about the teacher, the room and the batch across
 * `class_sessions`, `demo_classes` and other exams in a single pass (phase-14-17 §6.7, D47). Three
 * hand-written checks here would be three places to forget one, and the one forgotten is always the
 * room.
 *
 * **An exam with results is neither deleted nor cancelled.** §2.11 keeps it from being deleted;
 * `cancel()` refuses too, because an exam people actually sat and were marked on did not stop
 * happening. The honest correction there is `ExamResultService::unpublish()` and an amendment.
 *
 * **Caches are recounted, never incremented** — a COUNT under a row lock is re-derivable, and an
 * increment is a second truth that drifts under concurrency.
 */
final class ExamService
{
    use WritesAuditTrail;

    private const MODULE = 'exams';

    public function __construct(
        private readonly ScheduleClashDetector $clashes,
        private readonly BatchEnrollmentService $enrollments,
        private readonly GradeScaleService $scales,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $actor = null): Exam
    {
        $actor ??= Auth::user();

        $batch = $this->batch((int) ($attributes['batch_id'] ?? 0));
        $this->assertBatchIsUsable($batch);

        $type = $this->type($attributes);
        $date = $this->requireDate($attributes);
        [$total, $passing] = $this->marks($attributes);

        $this->assertTopicBelongsToCourse($attributes['course_topic_id'] ?? null, (int) $batch->getAttribute('course_id'));

        return DB::transaction(function () use ($attributes, $batch, $type, $date, $total, $passing): Exam {
            $exam = new Exam;

            $exam->forceFill([
                'branch_id' => $batch->getAttribute('branch_id'),
                'course_id' => (int) $batch->getAttribute('course_id'),
                'batch_id' => (int) $batch->getKey(),
                'exam_type' => $type->value,
                'name' => $attributes['name'],
                'course_topic_id' => $attributes['course_topic_id'] ?? null,
                'teacher_id' => $attributes['teacher_id'] ?? $batch->getAttribute('teacher_id'),
                'classroom_id' => $attributes['classroom_id'] ?? null,
                'delivery_mode' => $this->deliveryMode($attributes)->value,
                'meeting_url' => $attributes['meeting_url'] ?? null,
                'scheduled_date' => $date->toDateString(),
                'start_time' => $attributes['start_time'] ?? null,
                'end_time' => $attributes['end_time'] ?? null,
                'duration_minutes' => $attributes['duration_minutes'] ?? $type->defaultDurationMinutes(),
                'total_marks' => $total,
                'passing_marks' => $passing,
                'weight_percentage' => $attributes['weight_percentage'] ?? null,
                'grade_scale_id' => $attributes['grade_scale_id'] ?? null,
                'instructions' => $attributes['instructions'] ?? null,
                'status' => ExamStatus::Draft->value,
                'notes' => $attributes['notes'] ?? null,
            ]);

            $this->assertDeliveryIsCoherent($exam);
            $this->assertNoClash($exam);

            $exam->save();

            $this->recountExpected($exam);
            $exam->saveQuietly();

            $this->audit($exam, 'Exam created', [
                'attributes' => $exam->only(['batch_id', 'name', 'exam_type', 'scheduled_date', 'total_marks']),
            ], self::MODULE);

            return $exam->refresh();
        });
    }

    /**
     * Everything the model has not frozen. Once results are published, `total_marks`, `passing_marks`,
     * `grade_scale_id` and `scheduled_date` refuse to move — the model says so, not this.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Exam $exam, array $attributes, ?User $actor = null): Exam
    {
        return DB::transaction(function () use ($exam, $attributes): Exam {
            $locked = $this->lock($exam);

            // Absent means unchanged; present-and-null means cleared (D121).
            $keep = static fn (string $key, mixed $current): mixed => array_key_exists($key, $attributes)
                ? $attributes[$key]
                : $current;

            $before = $locked->only([
                'name', 'exam_type', 'course_topic_id', 'teacher_id', 'classroom_id',
                'delivery_mode', 'meeting_url', 'start_time', 'end_time', 'duration_minutes',
                'total_marks', 'passing_marks', 'weight_percentage', 'grade_scale_id',
            ]);

            $total = (string) $keep('total_marks', $locked->getAttribute('total_marks'));
            $passing = (string) $keep('passing_marks', $locked->getAttribute('passing_marks'));

            if (Money::compare($passing, $total) > 0) {
                throw CourseRuleException::refuse('passing_marks', 'The pass mark cannot be above what the paper is out of.');
            }

            $this->assertTopicBelongsToCourse(
                $keep('course_topic_id', $locked->getAttribute('course_topic_id')),
                (int) $locked->getAttribute('course_id'),
            );

            $locked->forceFill([
                'name' => $keep('name', $locked->getAttribute('name')),
                'exam_type' => $keep('exam_type', $locked->exam_type->value),
                'course_topic_id' => $keep('course_topic_id', $locked->getAttribute('course_topic_id')),
                'teacher_id' => $keep('teacher_id', $locked->getAttribute('teacher_id')),
                'classroom_id' => $keep('classroom_id', $locked->getAttribute('classroom_id')),
                'delivery_mode' => $keep('delivery_mode', $locked->delivery_mode->value),
                'meeting_url' => $keep('meeting_url', $locked->getAttribute('meeting_url')),
                'start_time' => $keep('start_time', $locked->getAttribute('start_time')),
                'end_time' => $keep('end_time', $locked->getAttribute('end_time')),
                'duration_minutes' => $keep('duration_minutes', $locked->getAttribute('duration_minutes')),
                'total_marks' => Money::round($total, 2),
                'passing_marks' => Money::round($passing, 2),
                'weight_percentage' => $keep('weight_percentage', $locked->getAttribute('weight_percentage')),
                'grade_scale_id' => $keep('grade_scale_id', $locked->getAttribute('grade_scale_id')),
                'instructions' => $keep('instructions', $locked->getAttribute('instructions')),
                'notes' => $keep('notes', $locked->getAttribute('notes')),
            ]);

            $this->assertDeliveryIsCoherent($locked);
            $this->assertNoClash($locked);

            $locked->save();

            $this->audit($locked, 'Exam updated', [
                'old' => $before,
                'attributes' => $locked->only(array_keys($before)),
            ], self::MODULE);

            return $locked->refresh();
        });
    }

    /** §2.28.4. Puts it on the calendar and tells the batch how many are expected. */
    public function schedule(Exam $exam, ?User $actor = null): Exam
    {
        return DB::transaction(function () use ($exam): Exam {
            $locked = $this->lock($exam);
            $from = $locked->status;

            if ($locked->status === ExamStatus::Cancelled) {
                throw CourseRuleException::refuse('status', 'A cancelled exam is not rescheduled — set up a new one.');
            }

            $this->assertNoClash($locked);

            $locked->forceFill(['status' => ExamStatus::Scheduled->value])->saveQuietly();

            $this->recountExpected($locked);
            $locked->saveQuietly();

            $this->audit($locked, 'Exam scheduled', [
                'old' => ['status' => $from?->value],
                'attributes' => ['status' => ExamStatus::Scheduled->value, 'expected' => $locked->getAttribute('expected_count')],
            ], self::MODULE);

            return $locked->refresh();
        });
    }

    public function markOngoing(Exam $exam, ?User $actor = null): Exam
    {
        return $this->moveTo($exam, ExamStatus::Ongoing);
    }

    /** It happened. Marks may now be entered. */
    public function markConducted(Exam $exam, ?User $actor = null): Exam
    {
        return $this->moveTo($exam, ExamStatus::Conducted);
    }

    /**
     * §2.28.4 sets this automatically on the first saved row rather than offering it as a button —
     * `ExamResultService::saveSheet()` calls it. It is public because that service is the caller, not
     * because a screen should be.
     */
    public function markMarking(Exam $exam, ?User $actor = null): Exam
    {
        return $this->moveTo($exam, ExamStatus::Marking);
    }

    /**
     * Call it off, with a reason.
     *
     * **Only from `draft`, `scheduled` or `ongoing`** (§2.28.4), and only while no result exists. Once
     * an exam has been *conducted* it happened, and an event that happened is not un-happened by a
     * status change — a batch that sat the paper would be told it never took place. The correction
     * after that point is `ExamResultService::unpublish()` and an amendment.
     */
    public function cancel(Exam $exam, string $reason, ?User $actor = null): Exam
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::refuse('reason', 'Say why the exam is being called off — the batch will ask.');
        }

        return DB::transaction(function () use ($exam, $reason): Exam {
            $locked = $this->lock($exam);
            $from = $locked->status;

            if (! in_array($from, [ExamStatus::Draft, ExamStatus::Scheduled, ExamStatus::Ongoing], true)) {
                throw CourseRuleException::refuse('status', sprintf(
                    'This exam is already %s, so it cannot be called off — it happened. Unpublish the '
                    .'results and amend them instead.',
                    mb_strtolower($from->label()),
                ));
            }

            if ($locked->results()->exists()) {
                throw CourseRuleException::refuse(
                    'status',
                    'Marks have been entered against this exam, so it cannot be called off. Unpublish '
                    .'the results and amend them instead.',
                );
            }

            $locked->forceFill([
                'status' => ExamStatus::Cancelled->value,
                'cancellation_reason' => mb_substr($reason, 0, 255),
            ])->saveQuietly();

            $this->audit($locked, 'Exam cancelled', [
                'old' => ['status' => $from?->value],
                'attributes' => ['status' => ExamStatus::Cancelled->value],
            ], self::MODULE, $reason);

            return $locked->refresh();
        });
    }

    /**
     * Move it. Clash-checked again, and **refused after publication** — a published exam's date is one
     * of the four columns a result card was printed against.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function reschedule(Exam $exam, array $attributes, string $reason, ?User $actor = null): Exam
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::refuse('reason', 'Say why the exam is moving.');
        }

        return DB::transaction(function () use ($exam, $attributes, $reason): Exam {
            $locked = $this->lock($exam);

            if ($locked->isPublished()) {
                throw CourseRuleException::refuse(
                    'scheduled_date',
                    'This exam’s results have been published. Moving its date would change what a '
                    .'printed result card says happened.',
                );
            }

            $before = $locked->only(['scheduled_date', 'start_time', 'end_time', 'classroom_id']);

            $locked->forceFill([
                'scheduled_date' => $this->requireDate($attributes)->toDateString(),
                'start_time' => $attributes['start_time'] ?? $locked->getAttribute('start_time'),
                'end_time' => $attributes['end_time'] ?? $locked->getAttribute('end_time'),
                'classroom_id' => $attributes['classroom_id'] ?? $locked->getAttribute('classroom_id'),
            ]);

            $this->assertNoClash($locked);
            $locked->save();

            // The roster on the new date is a different roster.
            $this->recountExpected($locked);
            $locked->saveQuietly();

            $this->audit($locked, 'Exam rescheduled', [
                'old' => $before,
                'attributes' => $locked->only(array_keys($before)),
            ], self::MODULE, $reason);

            return $locked->refresh();
        });
    }

    /**
     * The nine caches, by aggregate under the caller's row lock — never incremented.
     *
     * **`exempt` is left out of the averages and out of the pass rate**, because an excused student was
     * not assessed: counting them would turn a medical note into a statistic against the batch.
     */
    public function recountCaches(Exam $exam): void
    {
        $results = $exam->results();

        $counted = (clone $results)->where('attendance_status', '!=', ExamAttendanceStatus::Exempt->value);

        $marks = (clone $results)
            ->whereNotNull('obtained_marks')
            ->pluck('obtained_marks')
            ->map(static fn (mixed $v): string => (string) $v)
            ->all();

        $percentages = (clone $results)
            ->whereNotNull('percentage')
            ->pluck('percentage')
            ->map(static fn (mixed $v): string => (string) $v)
            ->all();

        $exam->forceFill([
            'results_entered_count' => (clone $results)->count(),
            'appeared_count' => (clone $results)->where('attendance_status', ExamAttendanceStatus::Appeared->value)->count(),
            'absent_count' => (clone $results)->where('attendance_status', ExamAttendanceStatus::Absent->value)->count(),
            'passed_count' => (clone $counted)->where('is_passed', true)->count(),
            'failed_count' => (clone $counted)->where('is_passed', false)->count(),
            'highest_marks' => $marks === [] ? null : Money::max(...$marks),
            'lowest_marks' => $marks === [] ? null : Money::min(...$marks),
            'average_marks' => $marks === [] ? null : Money::round(Money::div(Money::sum($marks), (string) count($marks)), 2),
            'average_percentage' => $percentages === []
                ? null
                : Money::round(Money::div(Money::sum($percentages), (string) count($percentages)), 2),
        ]);

        $this->recountExpected($exam);
        $exam->saveQuietly();
    }

    /** Which scale grades this exam — its own, the setting's, or the default. Throws if there is none. */
    public function scaleFor(Exam $exam): GradeScale
    {
        return $this->scales->resolveFor($exam);
    }

    // -------------------------------------------------------------------------------------------

    /**
     * The roster on the exam's **own date**, not today's: a student who joins next week was never
     * expected to sit an exam that happened yesterday.
     */
    private function recountExpected(Exam $exam): void
    {
        $batch = $exam->batch ?? Batch::query()->find($exam->getAttribute('batch_id'));

        if (! $batch instanceof Batch) {
            return;
        }

        $exam->forceFill([
            'expected_count' => $this->enrollments->roster(
                $batch,
                Carbon::parse((string) $exam->getAttribute('scheduled_date')),
            )->count(),
        ]);
    }

    /**
     * One generic call against every other thing that books a teacher, a room or a batch (D47).
     *
     * `ignoreType`/`ignoreId` keep an exam from clashing with itself when it is being edited — without
     * them, saving an exam without moving it would report the exam's own booking as a conflict.
     */
    private function assertNoClash(Exam $exam): void
    {
        $start = $exam->startsAt();

        if ($start === null) {
            // No time means no slot to compete for — an untimed exam clashes with nothing.
            return;
        }

        $end = $exam->getAttribute('end_time') === null
            ? $start->copy()->addMinutes(max(1, (int) ($exam->getAttribute('duration_minutes') ?? 60)))
            : Carbon::parse(Carbon::parse((string) $exam->getAttribute('scheduled_date'))->format('Y-m-d')
                .' '.$exam->getAttribute('end_time'));

        $report = $this->clashes->check(new SlotCandidate(
            teacherId: $exam->getAttribute('teacher_id') === null ? null : (int) $exam->getAttribute('teacher_id'),
            classroomId: $exam->getAttribute('classroom_id') === null ? null : (int) $exam->getAttribute('classroom_id'),
            batchId: (int) $exam->getAttribute('batch_id'),
            startsAt: $start,
            endsAt: $end,
            ignoreType: 'exam',
            ignoreId: $exam->exists ? (int) $exam->getKey() : null,
            deliveryMode: $exam->delivery_mode,
        ));

        if ($report->clean) {
            return;
        }

        throw CourseRuleException::refuse('scheduled_date', $report->summary());
    }

    /** §2.11: an online exam needs somewhere to sit it, and a physical one needs a room or no URL. */
    private function assertDeliveryIsCoherent(Exam $exam): void
    {
        $mode = $exam->delivery_mode;

        if ($mode->needsMeetingUrl() && trim((string) $exam->getAttribute('meeting_url')) === '') {
            throw CourseRuleException::refuse('meeting_url', 'An online exam needs a joining link.');
        }

        if (! $mode->needsMeetingUrl() && trim((string) $exam->getAttribute('meeting_url')) !== '') {
            throw CourseRuleException::refuse('meeting_url', 'A '.$mode->label().' exam is not sat over a link.');
        }
    }

    private function moveTo(Exam $exam, ExamStatus $to): Exam
    {
        return DB::transaction(function () use ($exam, $to): Exam {
            $locked = $this->lock($exam);
            $from = $locked->status;

            if ($from === $to) {
                return $locked;
            }

            if ($from === ExamStatus::Cancelled) {
                throw CourseRuleException::refuse('status', 'A cancelled exam does not move on. Set up a new one.');
            }

            $locked->forceFill(['status' => $to->value])->saveQuietly();

            $this->audit($locked, 'Exam status changed', [
                'old' => ['status' => $from?->value],
                'attributes' => ['status' => $to->value],
            ], self::MODULE);

            return $locked->refresh();
        });
    }

    private function batch(int $batchId): Batch
    {
        $batch = Batch::query()->find($batchId);

        if (! $batch instanceof Batch) {
            throw CourseRuleException::refuse('batch_id', 'Choose the batch sitting this exam.');
        }

        return $batch;
    }

    private function assertBatchIsUsable(Batch $batch): void
    {
        $status = $batch->status;

        if ($status instanceof BatchStatus && $status === BatchStatus::Cancelled) {
            throw CourseRuleException::refuse('batch_id', 'That batch is cancelled, so nobody would sit it.');
        }
    }

    private function assertTopicBelongsToCourse(mixed $topicId, int $courseId): void
    {
        if ($topicId === null) {
            return;
        }

        $topic = CourseTopic::query()->find($topicId);

        if (! $topic instanceof CourseTopic) {
            throw CourseRuleException::refuse('course_topic_id', 'That topic no longer exists.');
        }

        if ((int) ($topic->course_id ?? $topic->module?->course_id ?? 0) !== $courseId) {
            throw CourseRuleException::refuse('course_topic_id', 'That topic belongs to a different course.');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function type(array $attributes): ExamType
    {
        $type = ExamType::tryFrom((string) ($attributes['exam_type'] ?? ''));

        if (! $type instanceof ExamType) {
            throw CourseRuleException::refuse('exam_type', 'Choose what kind of exam this is.');
        }

        return $type;
    }

    /** @param array<string, mixed> $attributes */
    private function deliveryMode(array $attributes): DeliveryMode
    {
        return DeliveryMode::tryFrom((string) ($attributes['delivery_mode'] ?? '')) ?? DeliveryMode::Physical;
    }

    /** @param array<string, mixed> $attributes */
    private function requireDate(array $attributes): Carbon
    {
        $raw = $attributes['scheduled_date'] ?? null;

        if ($raw === null || $raw === '') {
            throw CourseRuleException::refuse('scheduled_date', 'An exam needs a date.');
        }

        return Carbon::parse($raw);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: string, 1: string}
     */
    private function marks(array $attributes): array
    {
        $total = Money::round((string) ($attributes['total_marks'] ?? '0'), 2);

        if (Money::compare($total, '0.00') <= 0) {
            throw CourseRuleException::refuse('total_marks', 'An exam has to be worth something.');
        }

        $passing = Money::round((string) ($attributes['passing_marks'] ?? '0'), 2);

        if (Money::compare($passing, '0.00') < 0 || Money::compare($passing, $total) > 0) {
            throw CourseRuleException::refuse('passing_marks', 'The pass mark has to sit between zero and the total.');
        }

        return [$total, $passing];
    }

    private function lock(Exam $exam): Exam
    {
        /** @var Exam $locked */
        $locked = Exam::query()->withTrashed()->whereKey($exam->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }
}
