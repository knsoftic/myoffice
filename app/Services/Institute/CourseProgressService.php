<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Enums\EnrollmentStatus;
use App\Enums\ProgressSource;
use App\Enums\ProgressStatus;
use App\Models\Institute\Batch;
use App\Models\Institute\BatchTopicCoverage;
use App\Models\Institute\ClassSession;
use App\Models\Institute\Course;
use App\Models\Institute\CourseTopic;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\Institute\StudentCourseProgress;
use App\Models\Institute\StudentModuleProgress;
use App\Models\Institute\StudentTopicProgress;
use App\Models\User;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * §83 at three levels (phase-14-17 §6.10).
 *
 * **The batch is the normal driver and the student is the override.** A teacher marks a topic covered
 * for the class and it fans out to every active enrolment — except rows a person set by hand, which
 * `ProgressSource::Manual` protects. A teacher who recorded that one student has not grasped
 * something the rest of the class finished must not have that judgement silently overwritten the next
 * time the class-level mark runs.
 *
 * **`recompute()` is the only writer of a percentage (INV-I11).** Every number on every progress row
 * is a cache derived from the topic rows, through bcmath, and `progress:recompute` reproduces them.
 * Nothing else may write one — not a controller, not a listener, not a screen.
 *
 * **A skipped or deactivated topic leaves BOTH sides of the fraction.** So dropping an uncovered
 * topic *raises* the percentage rather than stalling it, which is exactly what a coordinator expects
 * when they take work out of a syllabus. Getting this backwards is the classic progress-bar bug: the
 * number freezes and nobody can say why.
 */
final class CourseProgressService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Opening a student's progress
    |--------------------------------------------------------------------------
    */

    /**
     * One course row, one module row per active module, one topic row per active topic.
     *
     * Idempotent on `uq_scp_enrollment`: enrolling somebody twice, or re-running after an outline
     * change, tops up the missing rows rather than starting again — a fresh start would discard the
     * hand-set marks this service exists to protect.
     */
    public function openFor(StudentBatchEnrollment $enrollment, ?User $actor = null): StudentCourseProgress
    {
        return $this->db->transaction(function () use ($enrollment, $actor): StudentCourseProgress {
            // `withTrashed()` is the whole point of this lookup. §2.26 gives the table a `deleted_at`
            // to honour CLAUDE.md §3, but `uq_scp(student_batch_enrollment_id)` does not include it —
            // so a soft-deleted row is invisible to a default query and still occupies the guard, and
            // a plain `first()` here would answer null and then insert into a 1062. The seat would be
            // unable to have progress ever again. A trashed row is restored rather than replaced,
            // because its topic rows are still attached to it (D19).
            $progress = StudentCourseProgress::withTrashed()
                ->where('student_batch_enrollment_id', $enrollment->getKey())
                ->first();

            if ($progress !== null && $progress->trashed()) {
                $progress->restore();
                $progress->forceFill(['updated_by' => $actor?->getKey()])->save();
            }

            if ($progress === null) {
                $progress = new StudentCourseProgress;
                $progress->forceFill([
                    'student_batch_enrollment_id' => $enrollment->getKey(),
                    'student_id' => $enrollment->student_id,
                    'course_id' => $enrollment->course_id,
                    'batch_id' => $enrollment->batch_id,
                    'status' => ProgressStatus::Pending->value,
                    'started_on' => Carbon::today()->toDateString(),
                    'created_by' => $actor?->getKey(),
                    'updated_by' => $actor?->getKey(),
                ])->save();
            }

            $this->openRowsFor($progress, $actor);

            return $this->recompute($progress->refresh(), $actor);
        }, 3);
    }

    /**
     * Top up the module and topic rows from the active outline, without touching what exists.
     */
    private function openRowsFor(StudentCourseProgress $progress, ?User $actor): void
    {
        $modules = DB::table('course_modules')
            ->whereNull('deleted_at')
            ->where('course_id', $progress->course_id)
            ->where('is_active', true)
            ->pluck('id');

        $existingModules = StudentModuleProgress::query()
            ->where('student_course_progress_id', $progress->getKey())
            ->pluck('course_module_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        foreach ($modules as $moduleId) {
            if (in_array((int) $moduleId, $existingModules, true)) {
                continue;
            }

            $row = new StudentModuleProgress;
            $row->forceFill([
                'student_course_progress_id' => $progress->getKey(),
                'course_module_id' => (int) $moduleId,
                'status' => ProgressStatus::Pending->value,
            ])->save();
        }

        $topics = DB::table('course_topics')
            ->whereNull('deleted_at')
            ->where('course_id', $progress->course_id)
            ->where('is_active', true)
            ->get(['id', 'course_module_id']);

        $existingTopics = StudentTopicProgress::query()
            ->where('student_course_progress_id', $progress->getKey())
            ->pluck('course_topic_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        foreach ($topics as $topic) {
            if (in_array((int) $topic->id, $existingTopics, true)) {
                continue;
            }

            $row = new StudentTopicProgress;
            $row->forceFill([
                'student_course_progress_id' => $progress->getKey(),
                'course_topic_id' => (int) $topic->id,
                'course_module_id' => (int) $topic->course_module_id,
                'status' => ProgressStatus::Pending->value,
                'source' => ProgressSource::BatchCoverage->value,
            ])->save();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Marking
    |--------------------------------------------------------------------------
    */

    /**
     * "The class covered this topic." Upserts the coverage row, fans out, recomputes.
     *
     * @param  array<string, mixed>  $data
     */
    public function markTopicForBatch(Batch $batch, CourseTopic $topic, array $data = [], ?User $actor = null): BatchTopicCoverage
    {
        $this->assertTopicBelongsToBatch($batch, $topic);

        return $this->db->transaction(function () use ($batch, $topic, $data, $actor): BatchTopicCoverage {
            $percentage = $this->percentageFrom($data);
            $status = isset($data['status'])
                ? (ProgressStatus::tryFrom((string) $data['status']) ?? ProgressStatus::forPercentage($percentage))
                : ProgressStatus::forPercentage($percentage);

            $coverage = BatchTopicCoverage::query()
                ->where('batch_id', $batch->getKey())
                ->where('course_topic_id', $topic->getKey())
                ->first() ?? new BatchTopicCoverage;

            $coverage->forceFill([
                'batch_id' => $batch->getKey(),
                'course_topic_id' => $topic->getKey(),
                'course_module_id' => $topic->course_module_id,
                'class_session_id' => $data['class_session_id'] ?? $coverage->class_session_id,
                'status' => $status->value,
                'completion_percentage' => $percentage,
                'covered_on' => $data['covered_on'] ?? Carbon::today()->toDateString(),
                'teacher_id' => $data['teacher_id'] ?? $batch->teacher_id,
                'notes' => $data['notes'] ?? $coverage->notes,
                'updated_by' => $actor?->getKey(),
            ]);

            if (! $coverage->exists) {
                $coverage->created_by = $actor?->getKey();
            }

            $coverage->save();

            $this->fanOut($batch, $topic, $status, $percentage, $actor);
            $this->recountBatchSyllabus($batch);

            return $coverage->refresh();
        }, 3);
    }

    /**
     * The class-level mark reaching every active enrolment — except the hand-set rows.
     */
    private function fanOut(
        Batch $batch,
        CourseTopic $topic,
        ProgressStatus $status,
        string $percentage,
        ?User $actor,
    ): void {
        $enrollments = StudentBatchEnrollment::query()
            ->where('batch_id', $batch->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->get();

        foreach ($enrollments as $enrollment) {
            $progress = StudentCourseProgress::query()
                ->where('student_batch_enrollment_id', $enrollment->getKey())
                ->first() ?? $this->openFor($enrollment, $actor);

            $row = StudentTopicProgress::query()
                ->where('student_course_progress_id', $progress->getKey())
                ->where('course_topic_id', $topic->getKey())
                ->first();

            if ($row === null) {
                $row = new StudentTopicProgress;
                $row->forceFill([
                    'student_course_progress_id' => $progress->getKey(),
                    'course_topic_id' => $topic->getKey(),
                    'course_module_id' => $topic->course_module_id,
                    'source' => ProgressSource::BatchCoverage->value,
                ]);
            } elseif ($row->isProtectedFromBatchMark()) {
                // Somebody decided this one for this student. The class-level mark does not know
                // better, and overwriting it is the bug this column exists to prevent.
                continue;
            }

            $row->forceFill([
                'status' => $status->value,
                'completion_percentage' => $percentage,
                'source' => ProgressSource::BatchCoverage->value,
                'completed_on' => $status->isDone() ? Carbon::today()->toDateString() : null,
            ])->save();

            $this->recompute($progress, $actor);
        }
    }

    /**
     * One student, one topic, by hand — and from now on the class-level mark leaves it alone.
     *
     * @param  array<string, mixed>  $data
     */
    public function markTopicForStudent(
        StudentBatchEnrollment $enrollment,
        CourseTopic $topic,
        array $data = [],
        ?User $actor = null,
    ): StudentTopicProgress {
        return $this->db->transaction(function () use ($enrollment, $topic, $data, $actor): StudentTopicProgress {
            $progress = StudentCourseProgress::query()
                ->where('student_batch_enrollment_id', $enrollment->getKey())
                ->first() ?? $this->openFor($enrollment, $actor);

            $percentage = $this->percentageFrom($data);
            $status = isset($data['status'])
                ? (ProgressStatus::tryFrom((string) $data['status']) ?? ProgressStatus::forPercentage($percentage))
                : ProgressStatus::forPercentage($percentage);

            $row = StudentTopicProgress::query()
                ->where('student_course_progress_id', $progress->getKey())
                ->where('course_topic_id', $topic->getKey())
                ->first() ?? new StudentTopicProgress;

            $row->forceFill([
                'student_course_progress_id' => $progress->getKey(),
                'course_topic_id' => $topic->getKey(),
                'course_module_id' => $topic->course_module_id,
                'status' => $status->value,
                'completion_percentage' => $percentage,
                // From here on the fan-out will not touch it.
                'source' => ProgressSource::Manual->value,
                'marked_by' => $actor?->getKey(),
                'marked_at' => Carbon::now(),
                'completed_on' => $status->isDone() ? Carbon::today()->toDateString() : null,
                'remarks' => $data['remarks'] ?? null,
            ])->save();

            $this->recompute($progress, $actor);

            return $row->refresh();
        }, 3);
    }

    /**
     * A class that was held carrying a topic covers it for the batch.
     */
    public function markFromSession(ClassSession $session, ?User $actor = null): ?BatchTopicCoverage
    {
        if ($session->course_topic_id === null || ! $session->batch instanceof Batch) {
            return null;
        }

        $topic = CourseTopic::query()->find($session->course_topic_id);

        if ($topic === null) {
            return null;
        }

        return $this->markTopicForBatch($session->batch, $topic, [
            'completion_percentage' => '100.0000',
            'class_session_id' => $session->getKey(),
            'covered_on' => $session->session_date->toDateString(),
            'teacher_id' => $session->teacher_id,
        ], $actor);
    }

    /**
     * Drop a topic from this batch: its weight leaves every denominator, so the percentage rises.
     */
    public function skipTopic(Batch $batch, CourseTopic $topic, string $reason, ?User $actor = null): BatchTopicCoverage
    {
        if (trim($reason) === '') {
            throw CourseRuleException::reasonRequired('reason',
                'Skipping a topic takes it out of every student\'s syllabus and raises their percentage. '
                .'Say why, so the number can be explained later.');
        }

        $coverage = $this->markTopicForBatch($batch, $topic, [
            'status' => ProgressStatus::Skipped->value,
            'completion_percentage' => '0.0000',
        ], $actor);

        activity('student_progress')
            ->performedOn($coverage)
            ->causedBy($actor)
            ->withProperties(['batch' => $batch->code, 'topic' => $topic->title, 'reason' => $reason])
            ->log('progress.topic_skipped');

        return $coverage;
    }

    /*
    |--------------------------------------------------------------------------
    | The formulas, defined once (INV-I11)
    |--------------------------------------------------------------------------
    */

    /**
     * The only writer of a progress percentage.
     *
     *   topic contribution = completion_percentage / 100 * weight
     *   module percentage  = sum(contributions in module) * 100 / sum(weights of counting topics)
     *   course percentage  = weight_completed * 100 / weight_total
     *
     * `weight` is the topic's own under `topic_weight` weighting, and 1 under `topic_count` — which
     * is the whole of what that setting changes.
     */
    public function recompute(StudentCourseProgress $progress, ?User $actor = null): StudentCourseProgress
    {
        $byWeight = (string) setting('institute.progress_weighting', 'topic_weight') === 'topic_weight';

        $rows = DB::table('student_topic_progress as stp')
            ->join('course_topics as ct', 'ct.id', '=', 'stp.course_topic_id')
            ->whereNull('ct.deleted_at')
            ->where('stp.student_course_progress_id', $progress->getKey())
            // A deactivated topic leaves both sides, exactly as a skipped one does: the outline no
            // longer asks for it, so it neither counts against the student nor for them.
            ->where('ct.is_active', true)
            ->get(['stp.course_module_id', 'stp.status', 'stp.completion_percentage', 'ct.weight']);

        // `[percentage, weight]` pairs, because that is exactly what Money::weightedAverage() takes.
        // Accumulating the contributions by hand would round each one at money scale and quietly drop
        // two decimals of every progress figure — which is the mistake that helper exists to prevent.
        $pairs = [];
        $modulePairs = [];
        $moduleCounts = [];
        $weightUnits = 0;
        $topicsTotal = 0;
        $topicsCompleted = 0;

        foreach ($rows as $row) {
            $status = ProgressStatus::from((string) $row->status);

            if (! $status->countsInDenominator()) {
                continue;
            }

            $weight = $byWeight ? max(1, (int) $row->weight) : 1;
            $pair = [(string) $row->completion_percentage, (string) $weight];

            $pairs[] = $pair;
            $weightUnits += $weight;
            $topicsTotal++;

            $moduleId = (int) $row->course_module_id;
            $modulePairs[$moduleId][] = $pair;
            $moduleCounts[$moduleId] ??= ['topics' => 0, 'completed' => 0];
            $moduleCounts[$moduleId]['topics']++;

            if ($status->isDone()) {
                $topicsCompleted++;
                $moduleCounts[$moduleId]['completed']++;
            }
        }

        $modulesCompleted = $this->writeModuleRows($progress, $modulePairs, $moduleCounts);

        // A course with no outline reports 0.00 and never divides by zero. Half-up at TWO, into the
        // four-decimal column every percentage in the system uses (INV-I11, §6.10).
        $percentage = Money::clamp(Money::weightedAverage($pairs, 2) ?? '0.00', '0', '100', 2);

        $status = ProgressStatus::forPercentage($percentage);

        $modulesTotal = StudentModuleProgress::query()
            ->where('student_course_progress_id', $progress->getKey())
            ->count();

        $progress->forceFill([
            'status' => $status->value,
            'completion_percentage' => $percentage,
            'modules_total' => $modulesTotal,
            'modules_completed' => $modulesCompleted,
            'topics_total' => $topicsTotal,
            'topics_completed' => $topicsCompleted,
            // The integer columns the contract declares: the weights are whole numbers, and the
            // completed share is read back from the percentage so the two can never disagree — and
            // so `chk_scp_counts` can never be the thing that discovers they did.
            'weight_total' => $weightUnits,
            'weight_completed' => min($weightUnits, (int) floor((float) $percentage * $weightUnits / 100)),
            'completed_on' => $status->isDone() ? Carbon::today()->toDateString() : null,
            'last_activity_at' => Carbon::now(),
            'updated_by' => $actor?->getKey(),
        ])->save();

        return $progress->refresh();
    }

    /**
     * @param  array<int, list<array{0: string, 1: string}>>  $modulePairs
     * @param  array<int, array{topics: int, completed: int}>  $counts
     * @return int how many modules are complete
     */
    private function writeModuleRows(StudentCourseProgress $progress, array $modulePairs, array $counts): int
    {
        $completed = 0;

        $rows = StudentModuleProgress::query()
            ->where('student_course_progress_id', $progress->getKey())
            ->get();

        foreach ($rows as $row) {
            $pairs = $modulePairs[(int) $row->course_module_id] ?? null;

            if ($pairs === null) {
                // A module with no counting topics stands for itself with weight 1 (§6.10) — it is
                // hand-markable, so whatever status it already has is kept.
                if ($row->status->isDone()) {
                    $completed++;
                }

                $row->forceFill(['topics_total' => 0, 'topics_completed' => 0])->save();

                continue;
            }

            $percentage = Money::clamp(Money::weightedAverage($pairs, 2) ?? '0.00', '0', '100', 2);
            $status = ProgressStatus::forPercentage($percentage);

            if ($status->isDone()) {
                $completed++;
            }

            $countsFor = $counts[(int) $row->course_module_id] ?? ['topics' => 0, 'completed' => 0];

            $row->forceFill([
                'status' => $status->value,
                'completion_percentage' => $percentage,
                'topics_total' => $countsFor['topics'],
                'topics_completed' => $countsFor['completed'],
                'completed_on' => $status->isDone() ? Carbon::today()->toDateString() : null,
            ])->save();
        }

        return $completed;
    }

    /**
     * `batches.syllabus_completion_percentage` — the same formula over the coverage rows.
     */
    public function recountBatchSyllabus(Batch $batch): string
    {
        $byWeight = (string) setting('institute.progress_weighting', 'topic_weight') === 'topic_weight';

        $rows = DB::table('batch_topic_coverage as btc')
            ->join('course_topics as ct', 'ct.id', '=', 'btc.course_topic_id')
            ->whereNull('ct.deleted_at')
            ->where('btc.batch_id', $batch->getKey())
            ->where('ct.is_active', true)
            ->where('btc.status', '!=', ProgressStatus::Skipped->value)
            ->get(['btc.course_topic_id', 'btc.completion_percentage', 'ct.weight']);

        // The denominator is the whole active outline of the course, not only the topics somebody has
        // touched: a syllabus that is 100% complete after one covered topic would be a lie.
        $outline = DB::table('course_topics')
            ->whereNull('deleted_at')
            ->where('course_id', $batch->course_id)
            ->where('is_active', true)
            ->get(['id', 'weight']);

        $skipped = DB::table('batch_topic_coverage')
            ->where('batch_id', $batch->getKey())
            ->where('status', ProgressStatus::Skipped->value)
            ->pluck('course_topic_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        // Every active topic of the course that has not been skipped, whether or not anybody has
        // touched it: a syllabus that reported 100% after one covered topic would be a lie.
        $covered = [];

        foreach ($rows as $row) {
            $covered[(int) $row->course_topic_id] = (string) $row->completion_percentage;
        }

        $pairs = [];

        foreach ($outline as $topic) {
            if (in_array((int) $topic->id, $skipped, true)) {
                continue;
            }

            $pairs[] = [
                $covered[(int) $topic->id] ?? '0.0000',
                (string) ($byWeight ? max(1, (int) $topic->weight) : 1),
            ];
        }

        $percentage = Money::clamp(Money::weightedAverage($pairs, 2) ?? '0.00', '0', '100', 2);

        DB::table('batches')->where('id', $batch->getKey())->update([
            'syllabus_completion_percentage' => $percentage,
            'updated_at' => Carbon::now(),
        ]);

        $batch->setAttribute('syllabus_completion_percentage', $percentage);

        return $percentage;
    }

    /**
     * After an outline change: every enrolment of the course, in chunks.
     */
    public function recomputeForCourse(Course $course, ?User $actor = null): int
    {
        $touched = 0;

        StudentCourseProgress::query()
            ->where('course_id', $course->getKey())
            ->chunkById(200, function ($rows) use (&$touched, $actor): void {
                foreach ($rows as $progress) {
                    $this->openRowsFor($progress, $actor);
                    $this->recompute($progress, $actor);
                    $touched++;
                }
            });

        return $touched;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function assertTopicBelongsToBatch(Batch $batch, CourseTopic $topic): void
    {
        if ((int) $topic->course_id === (int) $batch->course_id) {
            return;
        }

        throw CourseRuleException::refuse('course_topic_id', sprintf(
            '"%s" belongs to a different course from %s. Marking it here would put a topic on a '
            .'syllabus nobody is studying.',
            $topic->title,
            $batch->label(),
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function percentageFrom(array $data): string
    {
        if (isset($data['completion_percentage'])) {
            $value = Money::of((string) $data['completion_percentage']);

            return Money::clamp($value, '0', '100', 4);
        }

        $status = isset($data['status']) ? ProgressStatus::tryFrom((string) $data['status']) : null;

        return match ($status) {
            ProgressStatus::Completed => '100.0000',
            ProgressStatus::Skipped, ProgressStatus::Pending => '0.0000',
            default => '100.0000',
        };
    }
}
