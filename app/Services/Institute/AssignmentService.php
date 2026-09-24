<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Files\FileRules;
use App\DataObjects\Files\FileTarget;
use App\DataObjects\Files\StoredFile;
use App\DataObjects\Files\StreamOptions;
use App\DataObjects\Institute\AssignmentStats;
use App\DataObjects\Support\AudienceInput;
use App\Enums\AssignmentStatus;
use App\Enums\BatchStatus;
use App\Enums\SubmissionStatus;
use App\Enums\SubmissionType;
use App\Models\Institute\Assignment;
use App\Models\Institute\Batch;
use App\Models\Institute\CourseTopic;
use App\Models\Institute\CourseTopicAssignment;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Files\SecureFileService;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Services\Support\NotificationService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Setting work, and everything that follows from having set it (phase-19-23 §6.7, requirement §80).
 *
 * **An assignment is always for exactly one batch.** A course-wide assignment is several rows, which is
 * what makes a per-batch deadline, a per-batch roster and a per-batch cache possible at all — and
 * `duplicateToBatches()` exists so setting the same work for three batches is one action rather than
 * three trips through the form.
 *
 * **`total_marks`, `deadline_at` and `submission_type` freeze once anything has been graded.** The model
 * refuses the change outright (where `Gate::before` cannot wave a Super Admin past it); `amend()` here
 * is the way through, and it takes a reason, logs old and new, and recomputes what depends on it.
 * INV-19-7 keeps lateness decisions already made intact: a later deadline is a new deadline for whoever
 * has not submitted yet, not a statement that nobody was ever late.
 *
 * **Caches are recounted, never incremented** — a COUNT under a row lock is re-derivable and an
 * increment is a second truth that drifts under concurrency.
 */
final class AssignmentService
{
    use WritesAuditTrail;

    private const MODULE = 'assignments';

    public function __construct(
        private readonly SecureFileService $files,
        private readonly BatchEnrollmentService $enrollments,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Set work for one batch.
     *
     * **The row is written first and the brief attached afterwards**, which looks backwards and is not.
     * §6.3 binds the brief's path to `institute/assignments/{assignment_id}/brief/…`, and that id does
     * not exist until the row does — `FileTarget` refuses a zero id rather than inventing a directory,
     * which is exactly what it is for. The upload therefore happens between two short transactions
     * instead of inside one long one, and a failure to attach removes the bytes it just wrote.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?UploadedFile $brief = null, ?User $actor = null): Assignment
    {
        $batch = $this->batch((int) ($attributes['batch_id'] ?? 0));
        $attributes = $this->applyBlueprint($attributes, $batch);

        $this->assertTopicBelongsToCourse($attributes['course_topic_id'] ?? null, (int) $batch->getAttribute('course_id'));

        $deadline = $this->requireDeadline($attributes);
        $totalMarks = $this->requireTotalMarks($attributes);
        $passing = $this->passingMarks($attributes, $totalMarks);

        $assignment = DB::transaction(function () use ($attributes, $batch, $deadline, $totalMarks, $passing): Assignment {
            $assignment = new Assignment;

            $assignment->forceFill([
                'branch_id' => $batch->getAttribute('branch_id'),
                'course_id' => (int) $batch->getAttribute('course_id'),
                'batch_id' => (int) $batch->getKey(),
                'course_topic_assignment_id' => $attributes['course_topic_assignment_id'] ?? null,
                'course_topic_id' => $attributes['course_topic_id'] ?? null,
                'teacher_id' => $attributes['teacher_id'] ?? $batch->getAttribute('teacher_id'),
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'instructions' => $attributes['instructions'] ?? null,
                'total_marks' => $totalMarks,
                'passing_marks' => $passing,
                'submission_type' => $this->submissionType($attributes)->value,
                'allowed_extensions' => $this->allowedExtensions($attributes),
                'max_file_size_mb' => $attributes['max_file_size_mb'] ?? null,
                'max_files' => $this->boundedSetting($attributes, 'max_files', 'institute.assignment_max_files_default', 3),
                'assigned_on' => $attributes['assigned_on'] ?? Carbon::now()->toDateString(),
                'deadline_at' => $deadline,
                'late_submission_allowed' => (bool) ($attributes['late_submission_allowed']
                    ?? settings_repo()->get('institute.assignment_late_submission_default') ?? true),
                'late_cutoff_at' => $attributes['late_cutoff_at'] ?? null,
                'late_penalty_percentage' => (string) ($attributes['late_penalty_percentage']
                    ?? settings_repo()->get('institute.assignment_late_penalty_default_percentage') ?? '0.0000'),
                'allow_resubmission' => (bool) ($attributes['allow_resubmission'] ?? true),
                'max_attempts' => $this->boundedSetting($attributes, 'max_attempts', 'institute.assignment_max_attempts_default', 3),
                'marks_visible_to_students' => (bool) ($attributes['marks_visible_to_students']
                    ?? settings_repo()->get('institute.assignment_release_marks_immediately') ?? true),
                'status' => AssignmentStatus::Draft->value,
                'notes' => $attributes['notes'] ?? null,
            ]);

            $assignment->save();

            // The roster as it stands on the deadline's date — who is expected to hand something in.
            $this->recountExpected($assignment);
            $assignment->saveQuietly();

            $this->audit($assignment, 'Assignment created', [
                'attributes' => $assignment->only(['batch_id', 'title', 'total_marks', 'deadline_at', 'submission_type']),
            ], self::MODULE);

            return $assignment->refresh();
        });

        return $brief instanceof UploadedFile
            ? $this->attachBrief($assignment, $brief)
            : $assignment;
    }

    /**
     * Attach a brief to an assignment that already exists. Separate from `create()` because the path
     * needs the id, and separate from `replaceBrief()` because there is nothing to replace — a failed
     * attach must not leave the bytes behind, and there is no old file to keep.
     */
    public function attachBrief(Assignment $assignment, UploadedFile $brief): Assignment
    {
        $stored = $this->files->store(
            $brief,
            FileTarget::assignmentBrief((int) $assignment->getKey(), $assignment),
            FileRules::assignmentBrief(),
        );

        try {
            return DB::transaction(function () use ($assignment, $stored): Assignment {
                $locked = $this->lock($assignment);
                $locked->forceFill($this->briefColumns($stored))->saveQuietly();

                $this->audit($locked, 'Assignment brief attached', [
                    'attributes' => ['attachment_path' => $stored->path, 'size_bytes' => $stored->sizeBytes],
                ], self::MODULE);

                return $locked->refresh();
            });
        } catch (Throwable $e) {
            // No row points at these bytes, and nothing else will ever find them.
            $this->files->delete($stored);

            throw $e;
        }
    }

    /**
     * Everything except the brief and the three frozen columns.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Assignment $assignment, array $attributes, ?User $actor = null): Assignment
    {
        return DB::transaction(function () use ($assignment, $attributes): Assignment {
            $locked = $this->lock($assignment);
            $before = $locked->only(['title', 'description', 'instructions', 'passing_marks', 'late_submission_allowed', 'late_cutoff_at', 'late_penalty_percentage', 'allow_resubmission', 'max_attempts', 'max_files', 'marks_visible_to_students']);

            $totalMarks = (string) $locked->getAttribute('total_marks');

            // **Absent means unchanged; present-and-null means cleared.** `?? null` would make every
            // key the caller did not send into an instruction to wipe the column — which is how a
            // partial update silently removed a pass line and reset the attempt limit. And the
            // *settings* are a prefill for a NEW assignment only: consulting them here would let an
            // administrator's default quietly overwrite what a teacher chose for this one.
            $keep = static fn (string $key, mixed $current): mixed => array_key_exists($key, $attributes)
                ? $attributes[$key]
                : $current;

            $locked->forceFill([
                'title' => $keep('title', $locked->getAttribute('title')),
                'description' => $keep('description', $locked->getAttribute('description')),
                'instructions' => $keep('instructions', $locked->getAttribute('instructions')),
                'passing_marks' => array_key_exists('passing_marks', $attributes)
                    ? $this->passingMarks($attributes, $totalMarks)
                    : $locked->getAttribute('passing_marks'),
                'allowed_extensions' => array_key_exists('allowed_extensions', $attributes)
                    ? $this->allowedExtensions($attributes)
                    : $locked->getAttribute('allowed_extensions'),
                'max_file_size_mb' => $keep('max_file_size_mb', $locked->getAttribute('max_file_size_mb')),
                'max_files' => $this->bounded($keep('max_files', $locked->getAttribute('max_files'))),
                'late_submission_allowed' => (bool) $keep('late_submission_allowed', $locked->getAttribute('late_submission_allowed')),
                'late_cutoff_at' => $keep('late_cutoff_at', $locked->getAttribute('late_cutoff_at')),
                'late_penalty_percentage' => (string) $keep('late_penalty_percentage', $locked->getAttribute('late_penalty_percentage')),
                'allow_resubmission' => (bool) $keep('allow_resubmission', $locked->getAttribute('allow_resubmission')),
                'max_attempts' => $this->bounded($keep('max_attempts', $locked->getAttribute('max_attempts'))),
                'marks_visible_to_students' => (bool) $keep('marks_visible_to_students', $locked->getAttribute('marks_visible_to_students')),
                'notes' => $keep('notes', $locked->getAttribute('notes')),
            ]);

            $this->assertCutoff($locked);
            $locked->save();

            $this->audit($locked, 'Assignment updated', [
                'old' => $before,
                'attributes' => $locked->only(array_keys($before)),
            ], self::MODULE);

            return $locked->refresh();
        });
    }

    /**
     * Change one of the three frozen columns after marking has started. **Requires a reason**, logs old
     * and new, and recomputes every cache that depends on the change.
     *
     * INV-19-7 is why this does not touch `is_late` on existing rows: those decisions were made against
     * the deadline that applied when the work was handed in, and reopening them would rewrite history.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function amend(Assignment $assignment, array $attributes, string $reason, ?User $actor = null): Assignment
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::refuse('reason', 'Say why the marks, deadline or submission type is changing.');
        }

        return DB::transaction(function () use ($assignment, $attributes, $reason): Assignment {
            $locked = $this->lock($assignment);
            $before = $locked->only(['total_marks', 'deadline_at', 'submission_type']);

            $changes = [];

            if (isset($attributes['total_marks'])) {
                $changes['total_marks'] = $this->requireTotalMarks($attributes);
            }

            if (isset($attributes['deadline_at'])) {
                $changes['deadline_at'] = $this->requireDeadline($attributes);
            }

            if (isset($attributes['submission_type'])) {
                $changes['submission_type'] = $this->submissionType($attributes)->value;
            }

            if ($changes === []) {
                return $locked;
            }

            // The model's freeze is bypassed deliberately and only here — this is the one path that is
            // allowed to move these three, and it is the one that records why.
            $locked->forceFill($changes)->saveQuietly();
            $this->assertCutoff($locked);
            $locked->saveQuietly();

            $this->recountCaches($locked);

            $this->audit($locked, 'Assignment amended', [
                'old' => $before,
                'attributes' => $changes,
            ], self::MODULE, $reason);

            return $locked->refresh();
        });
    }

    /** Swap the brief. The old bytes go only after the caller's transaction commits. */
    public function replaceBrief(Assignment $assignment, UploadedFile $brief, ?User $actor = null): Assignment
    {
        $old = $this->briefFile($assignment);

        $stored = $this->files->replace(
            $old,
            $brief,
            FileTarget::assignmentBrief((int) $assignment->getKey(), $assignment),
            FileRules::assignmentBrief(),
        );

        return DB::transaction(function () use ($assignment, $old, $stored): Assignment {
            $locked = $this->lock($assignment);
            $locked->forceFill($this->briefColumns($stored));
            $locked->save();

            $this->audit($locked, 'Assignment brief replaced', [
                'old' => ['attachment_path' => $old->path],
                'attributes' => ['attachment_path' => $stored->path],
            ], self::MODULE);

            return $locked->refresh();
        });
    }

    /**
     * §2.28.2. Refuses a deadline already past, a zero total, and a cancelled batch — all three because
     * publishing any of them would create work nobody can submit to.
     */
    public function publish(Assignment $assignment, ?User $actor = null): Assignment
    {
        $published = DB::transaction(function () use ($assignment): Assignment {
            $locked = $this->lock($assignment);

            if ($locked->deadline_at->isPast()) {
                throw CourseRuleException::refuse('deadline_at', 'The deadline has already passed. Move it before publishing.');
            }

            if (Money::compare((string) $locked->getAttribute('total_marks'), '0.00') <= 0) {
                throw CourseRuleException::refuse('total_marks', 'An assignment worth nothing cannot be graded.');
            }

            $batchStatus = $locked->batch?->status;

            if ($batchStatus instanceof BatchStatus && $batchStatus === BatchStatus::Cancelled) {
                throw CourseRuleException::refuse('batch_id', 'This batch is cancelled, so nobody could submit.');
            }

            $from = $locked->status;

            $locked->forceFill([
                'status' => AssignmentStatus::Published->value,
                'published_at' => $locked->getAttribute('published_at') ?? Carbon::now(),
                'closed_at' => null,
            ])->saveQuietly();

            $this->recountExpected($locked);
            $locked->saveQuietly();

            $this->audit($locked, 'Assignment published', [
                'old' => ['status' => $from?->value],
                'attributes' => ['status' => AssignmentStatus::Published->value],
            ], self::MODULE);

            return $locked->refresh();
        });

        // The batch's students, resolved to logins. `student_portal.assignments` is the event's
        // `requiredPermission`, so anybody whose portal cannot show assignments is dropped rather
        // than given a row that links nowhere.
        $userIds = DB::table('student_batch_enrollments')
            ->join('students', 'students.id', '=', 'student_batch_enrollments.student_id')
            ->where('student_batch_enrollments.batch_id', $published->getAttribute('batch_id'))
            ->whereNull('student_batch_enrollments.deleted_at')
            ->whereNull('students.deleted_at')
            ->whereNotNull('students.user_id')
            ->pluck('students.user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($userIds !== []) {
            $this->notifications->dispatch('assignment.published', AudienceInput::of($userIds), [
                'title' => 'New assignment: '.$published->getAttribute('title'),
                'body' => $published->getAttribute('deadline_at') === null
                    ? 'No deadline set.'
                    : 'Due '.Carbon::parse($published->getAttribute('deadline_at'))->format('j M Y, H:i'),
                'url' => '/student/assignments/'.$published->getKey(),
                'assignment_id' => (int) $published->getKey(),
            ], $actor);
        }

        return $published;
    }

    /**
     * Stop collecting. **Closed still shows the assignment to students** — the brief, the deadline and
     * their own marks — which is the whole reason it is not the same as archived.
     */
    public function close(Assignment $assignment, ?User $actor = null): Assignment
    {
        return DB::transaction(function () use ($assignment): Assignment {
            $locked = $this->lock($assignment);
            $from = $locked->status;

            $locked->forceFill([
                'status' => AssignmentStatus::Closed->value,
                'closed_at' => Carbon::now(),
            ])->saveQuietly();

            app(AssignmentSubmissionService::class)->markMissed($locked);
            $this->recountCaches($locked);

            $this->audit($locked, 'Assignment closed', [
                'old' => ['status' => $from?->value],
                'attributes' => ['status' => AssignmentStatus::Closed->value],
            ], self::MODULE);

            return $locked->refresh();
        });
    }

    /**
     * Collect again. The `missed` rows written by the close go, because they were a statement about a
     * deadline that no longer applies — leaving them would mark a student absent from an assignment
     * they are now being invited to submit to.
     *
     * **A miss that has content is restored to a draft rather than deleted.** `markMissed()` converts
     * an abandoned draft in place, so one of these rows can be something a student actually started.
     * Today it never is — `submit()` is the only writer of `submission_text`, so a draft is always
     * empty and this branch does not fire. It is here because the moment a save-my-progress screen
     * lands, the alternative is an administrative action by somebody else quietly deleting a
     * student's work, and that is not a failure mode worth discovering in production.
     */
    public function reopen(Assignment $assignment, string $reason, ?User $actor = null): Assignment
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::refuse('reason', 'Say why this assignment is being reopened.');
        }

        return DB::transaction(function () use ($assignment, $reason): Assignment {
            $locked = $this->lock($assignment);
            $from = $locked->status;

            $cleared = $locked->submissions()
                ->where('status', SubmissionStatus::Missed->value)
                ->get();

            $restored = 0;
            $removed = 0;

            foreach ($cleared as $row) {
                $hasWork = $row->getAttribute('submission_text') !== null
                    || (int) $row->getAttribute('files_count') > 0;

                if ($hasWork) {
                    $row->forceFill([
                        'status' => SubmissionStatus::Draft->value,
                        'submitted_at' => null,
                        'is_late' => false,
                        'minutes_late' => null,
                    ])->saveQuietly();

                    $restored++;

                    continue;
                }

                // Nothing was ever written here, so removing it destroys no work. It goes through
                // forceDelete because the model refuses delete() for every role.
                $row->forceDeleteQuietly();
                $removed++;
            }

            $locked->forceFill([
                'status' => AssignmentStatus::Published->value,
                'closed_at' => null,
            ])->saveQuietly();

            $this->recountCaches($locked);

            $this->audit($locked, 'Assignment reopened', [
                'old' => ['status' => $from?->value],
                'attributes' => [
                    'status' => AssignmentStatus::Published->value,
                    'missed_rows_removed' => $removed,
                    'drafts_restored' => $restored,
                ],
            ], self::MODULE, $reason);

            return $locked->refresh();
        });
    }

    public function archive(Assignment $assignment, string $reason, ?User $actor = null): Assignment
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::refuse('reason', 'Say why this assignment is being archived.');
        }

        return DB::transaction(function () use ($assignment, $reason): Assignment {
            $locked = $this->lock($assignment);
            $from = $locked->status;

            $locked->forceFill(['status' => AssignmentStatus::Archived->value])->saveQuietly();

            $this->audit($locked, 'Assignment archived', [
                'old' => ['status' => $from?->value],
                'attributes' => ['status' => AssignmentStatus::Archived->value],
            ], self::MODULE, $reason);

            return $locked->refresh();
        });
    }

    /**
     * Set the same work for several batches.
     *
     * **The brief is referenced, not re-uploaded** (§6.7). One file, several rows pointing at it —
     * which is why nothing in this phase deletes the bytes when one assignment is removed: another row
     * may still be pointing at them. PH19-07 asserts exactly that.
     *
     * @param  list<int>  $batchIds
     * @param  array<int, string>  $deadlines  batch id => deadline, so each cohort can have its own
     * @return Collection<int, Assignment>
     */
    public function duplicateToBatches(Assignment $assignment, array $batchIds, array $deadlines = [], ?User $actor = null): Collection
    {
        return DB::transaction(function () use ($assignment, $batchIds, $deadlines): Collection {
            $source = $this->lock($assignment);
            $made = new Collection;

            foreach (array_unique($batchIds) as $batchId) {
                $batch = $this->batch((int) $batchId);

                if ((int) $batch->getAttribute('course_id') !== (int) $source->getAttribute('course_id')) {
                    throw CourseRuleException::refuse('batch_ids', sprintf(
                        'Batch %s runs a different course.',
                        (string) ($batch->getAttribute('code') ?? '#'.$batchId),
                    ));
                }

                $copy = $source->replicate([
                    'published_at', 'closed_at', 'expected_count', 'submitted_count', 'late_count',
                    'graded_count', 'missed_count', 'average_marks', 'highest_marks',
                ]);

                $copy->forceFill([
                    'batch_id' => (int) $batch->getKey(),
                    'branch_id' => $batch->getAttribute('branch_id'),
                    'teacher_id' => $batch->getAttribute('teacher_id') ?? $source->getAttribute('teacher_id'),
                    'status' => AssignmentStatus::Draft->value,
                    'deadline_at' => $deadlines[$batchId] ?? $source->getAttribute('deadline_at'),
                    'published_at' => null,
                    'closed_at' => null,
                    'expected_count' => 0,
                    'submitted_count' => 0,
                    'late_count' => 0,
                    'graded_count' => 0,
                    'missed_count' => 0,
                    'average_marks' => null,
                    'highest_marks' => null,
                ]);

                $copy->save();

                $this->recountExpected($copy);
                $copy->saveQuietly();

                $this->audit($copy, 'Assignment duplicated', [
                    'attributes' => [
                        'from_assignment_id' => $source->getKey(),
                        'batch_id' => $copy->getAttribute('batch_id'),
                        // Named so the shared-bytes rule is visible in the trail rather than only here.
                        'attachment_path' => $copy->getAttribute('attachment_path'),
                        'brief_is_shared' => $copy->getAttribute('attachment_path') !== null,
                    ],
                ], self::MODULE);

                $made->push($copy->refresh());
            }

            return $made;
        });
    }

    /** §6.4 — the caller has run the permission chain. */
    public function streamBrief(Assignment $assignment): StreamedResponse
    {
        return $this->files->stream(
            $this->briefFile($assignment),
            StreamOptions::inline(),
            FileRules::assignmentBrief(),
        );
    }

    /**
     * The six counts plus the two aggregates, by query — never incremented.
     *
     * Only **live** submissions count: a superseded attempt has already been counted as its successor,
     * and counting both would make a batch of twenty look like a batch of thirty.
     */
    public function recountCaches(Assignment $assignment): void
    {
        $live = $assignment->liveSubmissions();

        $submitted = (clone $live)->whereIn('status', $this->countableStatuses())->count();
        $graded = (clone $live)->where('status', SubmissionStatus::Graded->value)->count();
        $late = (clone $live)->where('is_late', true)->whereIn('status', $this->countableStatuses())->count();
        $missed = (clone $live)->where('status', SubmissionStatus::Missed->value)->count();

        $marks = (clone $live)
            ->where('status', SubmissionStatus::Graded->value)
            ->pluck('final_marks')
            ->filter(static fn (mixed $v): bool => $v !== null)
            ->map(static fn (mixed $v): string => (string) $v)
            ->all();

        $assignment->forceFill([
            'submitted_count' => $submitted,
            'graded_count' => $graded,
            'late_count' => $late,
            'missed_count' => $missed,
            // bcmath, per CLAUDE.md §4 — marks are money-shaped.
            'average_marks' => $marks === [] ? null : Money::round(Money::div(Money::sum($marks), (string) count($marks)), 2),
            'highest_marks' => $marks === [] ? null : Money::max(...$marks),
        ]);

        $this->recountExpected($assignment);
        $assignment->saveQuietly();
    }

    /** The one definition of an assignment's numbers — the teacher screen and Phase 23 both read it. */
    public function statistics(Assignment $assignment): AssignmentStats
    {
        $this->recountCaches($assignment);
        $assignment->refresh();

        return new AssignmentStats(
            expected: (int) $assignment->getAttribute('expected_count'),
            submitted: (int) $assignment->getAttribute('submitted_count'),
            late: (int) $assignment->getAttribute('late_count'),
            graded: (int) $assignment->getAttribute('graded_count'),
            missed: (int) $assignment->getAttribute('missed_count'),
            averageMarks: $assignment->getAttribute('average_marks') === null ? null : (string) $assignment->getAttribute('average_marks'),
            highestMarks: $assignment->getAttribute('highest_marks') === null ? null : (string) $assignment->getAttribute('highest_marks'),
            totalMarks: (string) $assignment->getAttribute('total_marks'),
        );
    }

    // -------------------------------------------------------------------------------------------

    /**
     * The roster on the deadline's date — not today's. A student who joins the batch next week was not
     * expected to hand in work that was due yesterday.
     */
    private function recountExpected(Assignment $assignment): void
    {
        $batch = $assignment->batch ?? Batch::query()->find($assignment->getAttribute('batch_id'));

        if (! $batch instanceof Batch) {
            return;
        }

        $on = $assignment->getAttribute('deadline_at');

        $assignment->forceFill([
            'expected_count' => $this->enrollments->roster($batch, $on ? Carbon::parse($on) : null)->count(),
        ]);
    }

    /** @return list<string> */
    private function countableStatuses(): array
    {
        return array_values(array_map(
            static fn (SubmissionStatus $s): string => $s->value,
            array_filter(SubmissionStatus::cases(), static fn (SubmissionStatus $s): bool => $s->countsAsSubmitted()),
        ));
    }

    /**
     * Prefill from Phase 14's blueprint when one is named (phase-14-17 [D-IN-6]). The blueprint says
     * what the syllabus intends; the caller may override any of it, because what a batch was actually
     * set is allowed to differ.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function applyBlueprint(array $attributes, Batch $batch): array
    {
        $id = $attributes['course_topic_assignment_id'] ?? null;

        if ($id === null) {
            return $attributes;
        }

        $blueprint = CourseTopicAssignment::query()->find($id);

        if (! $blueprint instanceof CourseTopicAssignment) {
            throw CourseRuleException::refuse('course_topic_assignment_id', 'That blueprint no longer exists.');
        }

        if ((int) $blueprint->getAttribute('course_id') !== (int) $batch->getAttribute('course_id')) {
            throw CourseRuleException::refuse(
                'course_topic_assignment_id',
                'That blueprint belongs to a different course.',
            );
        }

        return array_merge([
            'title' => $blueprint->getAttribute('title'),
            'description' => $blueprint->getAttribute('description'),
            'instructions' => $blueprint->getAttribute('instructions'),
            'total_marks' => $blueprint->getAttribute('estimated_marks'),
            'course_topic_id' => $blueprint->getAttribute('course_topic_id'),
        ], array_filter($attributes, static fn (mixed $v): bool => $v !== null && $v !== ''));
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

    /**
     * `chk_as_cutoff` refuses a cutoff before the deadline, and a 500 from the database is a worse way
     * to learn that than a message on the field.
     */
    private function assertCutoff(Assignment $assignment): void
    {
        $cutoff = $assignment->getAttribute('late_cutoff_at');

        if ($cutoff !== null && Carbon::parse($cutoff)->lessThan($assignment->deadline_at)) {
            throw CourseRuleException::refuse(
                'late_cutoff_at',
                'The late cutoff cannot be before the deadline — it would close submissions while the assignment still said it was open.',
            );
        }
    }

    private function batch(int $batchId): Batch
    {
        $batch = Batch::query()->find($batchId);

        if (! $batch instanceof Batch) {
            throw CourseRuleException::refuse('batch_id', 'Choose the batch this work is for.');
        }

        return $batch;
    }

    /** @param array<string, mixed> $attributes */
    private function requireDeadline(array $attributes): Carbon
    {
        $raw = $attributes['deadline_at'] ?? null;

        if ($raw === null || $raw === '') {
            throw CourseRuleException::refuse('deadline_at', 'An assignment needs a deadline.');
        }

        return Carbon::parse($raw);
    }

    /** @param array<string, mixed> $attributes */
    private function requireTotalMarks(array $attributes): string
    {
        $total = Money::round((string) ($attributes['total_marks'] ?? '0'), 2);

        if (Money::compare($total, '0.00') <= 0) {
            throw CourseRuleException::refuse('total_marks', 'An assignment has to be worth something.');
        }

        return $total;
    }

    /** @param array<string, mixed> $attributes */
    private function passingMarks(array $attributes, string $totalMarks): ?string
    {
        $raw = $attributes['passing_marks'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        $passing = Money::round((string) $raw, 2);

        if (Money::compare($passing, '0.00') < 0 || Money::compare($passing, $totalMarks) > 0) {
            throw CourseRuleException::refuse('passing_marks', 'The pass mark has to sit between zero and the total.');
        }

        return $passing;
    }

    /** @param array<string, mixed> $attributes */
    private function submissionType(array $attributes): SubmissionType
    {
        $type = SubmissionType::tryFrom((string) ($attributes['submission_type'] ?? SubmissionType::FileOrText->value));

        if (! $type instanceof SubmissionType) {
            throw CourseRuleException::refuse('submission_type', 'Choose what students may hand in.');
        }

        return $type;
    }

    /**
     * The assignment's own extension list narrows `FileRules::submission()` and can never widen it, so
     * an empty list means "whatever the institute allows" rather than "anything".
     *
     * @param  array<string, mixed>  $attributes
     * @return list<string>|null
     */
    private function allowedExtensions(array $attributes): ?array
    {
        $raw = $attributes['allowed_extensions'] ?? null;

        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        $list = is_array($raw) ? $raw : explode(',', (string) $raw);

        $clean = array_values(array_filter(array_map(
            static fn (mixed $e): string => strtolower(trim((string) $e, " \t.")),
            $list,
        ), static fn (string $e): bool => $e !== ''));

        return $clean === [] ? null : $clean;
    }

    /**
     * The prefill for a **new** assignment: what the caller gave, else the institute's default, else
     * the contract's. Never used on an update — see the note in `update()`.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function boundedSetting(array $attributes, string $key, string $settingKey, int $fallback): int
    {
        return $this->bounded($attributes[$key] ?? settings_repo()->get($settingKey) ?? $fallback);
    }

    /**
     * Clamped to the 1–10 that `chk_as_attempts` enforces, so a bad setting or a hand-typed number is
     * a corrected value rather than a 500 from the database.
     */
    private function bounded(mixed $value): int
    {
        return max(1, min(10, (int) $value));
    }

    /** @return array<string, mixed> */
    private function briefColumns(StoredFile $stored): array
    {
        return [
            'attachment_path' => $stored->path,
            'attachment_original_name' => $stored->originalName,
            'attachment_mime_type' => $stored->mimeType,
            'attachment_size_bytes' => $stored->sizeBytes,
        ];
    }

    private function briefFile(Assignment $assignment): StoredFile
    {
        $path = (string) $assignment->getAttribute('attachment_path');

        if ($path === '') {
            throw CourseRuleException::refuse('attachment_path', 'This assignment has no brief attached.');
        }

        return new StoredFile(
            disk: FileRules::DISK_PRIVATE,
            path: $path,
            originalName: (string) ($assignment->getAttribute('attachment_original_name') ?? 'brief'),
            extension: pathinfo($path, PATHINFO_EXTENSION),
            mimeType: (string) ($assignment->getAttribute('attachment_mime_type') ?? 'application/octet-stream'),
            sizeBytes: (int) ($assignment->getAttribute('attachment_size_bytes') ?? 0),
        );
    }

    private function lock(Assignment $assignment): Assignment
    {
        /** @var Assignment $locked */
        $locked = Assignment::query()->withTrashed()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }
}
