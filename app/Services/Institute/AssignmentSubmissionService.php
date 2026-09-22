<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Files\FileRules;
use App\DataObjects\Files\FileTarget;
use App\DataObjects\Files\StoredFile;
use App\DataObjects\Files\StreamOptions;
use App\DataObjects\Institute\BulkGradeResult;
use App\DataObjects\Institute\GradeOutcome;
use App\Enums\SubmissionStatus;
use App\Models\Institute\Assignment;
use App\Models\Institute\AssignmentSubmission;
use App\Models\Institute\AssignmentSubmissionFile;
use App\Models\Institute\Student;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Files\SecureFileService;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Handing work in, and marking it (phase-19-23 §6.8, requirement §80).
 *
 * **INV-19-5 — a resubmission supersedes, it never overwrites.** `resubmit()` inserts a *new* row and
 * marks the old one `superseded` inside one transaction. The old attempt keeps its files, its marks and
 * its timestamps, and `uq_as_live` makes "one live submission per student" a fact the database holds
 * rather than a rule this service remembers.
 *
 * **INV-19-7 — lateness is decided once, at insert.** `is_late` and `minutes_late` are computed against
 * the deadline that applied when the work arrived, and nothing recomputes them afterwards. A teacher
 * extending a deadline is setting a new deadline for whoever has not submitted; it is not a statement
 * that nobody was ever late. The model refuses to move either column.
 *
 * **INV-19-6 — the ceiling is asserted three times.** The Form Request checks it, this service checks
 * it, and `chk_asub_marks` compares two columns of the same row. Three layers because a mark above the
 * total is the one error that silently corrupts every average and percentage downstream of it, and the
 * cheapest place to catch it is all of them.
 *
 * **Nothing here deletes a submission** except a student withdrawing their own draft. The policy
 * refuses `delete` for every role and the model refuses it even for a Super Admin.
 */
final class AssignmentSubmissionService
{
    use WritesAuditTrail;

    private const MODULE = 'assignment_submissions';

    public function __construct(
        private readonly SecureFileService $files,
        private readonly AssignmentGradeCalculator $calculator,
        private readonly AssignmentService $assignments,
    ) {}

    /**
     * The student's draft, created or returned. Asserts an **active enrollment in that batch** — the
     * row that proves they were on the roster, which is what makes a submission evidence rather than
     * an assertion.
     */
    public function draft(Assignment $assignment, Student $student, ?User $actor = null): AssignmentSubmission
    {
        return DB::transaction(function () use ($assignment, $student): AssignmentSubmission {
            $existing = $this->liveFor($assignment, $student);

            if ($existing instanceof AssignmentSubmission) {
                if (! $existing->isDraft()) {
                    throw CourseRuleException::refuse(
                        'submission',
                        'You have already handed this in. Start a new attempt if the assignment allows it.',
                    );
                }

                return $existing;
            }

            if (! $assignment->status->acceptsSubmissions()) {
                throw CourseRuleException::refuse('assignment', 'This assignment is not accepting work.');
            }

            $enrollment = $this->requireEnrollment($assignment, $student);

            $submission = new AssignmentSubmission;
            $submission->forceFill([
                'assignment_id' => (int) $assignment->getKey(),
                'student_id' => (int) $student->getKey(),
                'student_batch_enrollment_id' => (int) $enrollment->getKey(),
                'batch_id' => (int) $assignment->getAttribute('batch_id'),
                'attempt_no' => 1,
                'status' => SubmissionStatus::Draft->value,
                // Snapshotted now so `chk_asub_marks` always has something to compare against, and
                // re-snapshotted at submit in case the assignment was amended in between.
                'total_marks' => (string) $assignment->getAttribute('total_marks'),
                'penalty_marks' => '0.00',
            ]);
            $submission->save();

            return $submission;
        });
    }

    /**
     * Hand it in. One transaction, and **lateness is decided here and nowhere else**.
     *
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files
     */
    public function submit(AssignmentSubmission $draft, array $data, array $files = [], ?User $actor = null): AssignmentSubmission
    {
        $actor ??= Auth::user();
        $at = Carbon::now();

        $assignment = $draft->assignment ?? Assignment::query()->findOrFail($draft->getAttribute('assignment_id'));

        $this->assertAcceptsWork($assignment, $at);

        $text = $this->cleanText($data['submission_text'] ?? null);
        $this->assertPayload($assignment, $text, count($files));

        // The bytes land before the transaction: a filesystem write is not transactional, and holding
        // a row lock across a ten-megabyte upload is how a class handing in at once deadlocks.
        $stored = $files === []
            ? []
            : $this->files->storeMany(
                $files,
                FileTarget::submission(
                    (int) $assignment->getKey(),
                    (int) $draft->getAttribute('student_id'),
                    (int) $draft->getAttribute('attempt_no'),
                ),
                $this->rulesFor($assignment),
            );

        try {
            return DB::transaction(function () use ($draft, $assignment, $text, $stored, $at, $actor): AssignmentSubmission {
                $locked = $this->lock($draft);

                if (! $locked->isDraft()) {
                    throw CourseRuleException::refuse('submission', 'This attempt has already been handed in.');
                }

                $late = $assignment->isLateAt($at);

                $locked->forceFill([
                    'submission_text' => $text,
                    'status' => SubmissionStatus::Submitted->value,
                    'submitted_at' => $at,
                    // INV-19-7: decided now, never recomputed.
                    'is_late' => $late,
                    'minutes_late' => $late ? $assignment->minutesLateAt($at) : null,
                    'total_marks' => (string) $assignment->getAttribute('total_marks'),
                    'files_count' => count($stored),
                ])->saveQuietly();

                foreach ($stored as $file) {
                    $this->attachFile($locked, $file, $actor);
                }

                $this->assignments->recountCaches($assignment);

                $this->audit($locked, 'Submission handed in', [
                    'attributes' => [
                        'assignment_id' => $assignment->getKey(),
                        'attempt_no' => $locked->getAttribute('attempt_no'),
                        'is_late' => $late,
                        'minutes_late' => $locked->getAttribute('minutes_late'),
                        'files' => count($stored),
                    ],
                ], self::MODULE);

                return $locked->refresh();
            });
        } catch (Throwable $e) {
            // The rows did not land, so the bytes must not either.
            foreach ($stored as $file) {
                $this->files->delete($file);
            }

            throw $e;
        }
    }

    /**
     * A new attempt. The previous one is superseded in the same transaction, so `uq_as_live` never sees
     * two live rows — and the old attempt keeps everything it had.
     *
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files
     */
    public function resubmit(AssignmentSubmission $live, array $data, array $files = [], ?User $actor = null): AssignmentSubmission
    {
        $actor ??= Auth::user();
        $at = Carbon::now();

        $assignment = $live->assignment ?? Assignment::query()->findOrFail($live->getAttribute('assignment_id'));

        if (! (bool) $assignment->getAttribute('allow_resubmission')) {
            throw CourseRuleException::refuse('submission', 'This assignment does not allow a second attempt.');
        }

        $next = (int) $live->getAttribute('attempt_no') + 1;
        $max = (int) $assignment->getAttribute('max_attempts');

        if ($next > $max) {
            throw CourseRuleException::refuse('submission', sprintf(
                'You have used all %d attempts on this assignment.',
                $max,
            ));
        }

        $this->assertAcceptsWork($assignment, $at);

        $text = $this->cleanText($data['submission_text'] ?? null);
        $this->assertPayload($assignment, $text, count($files));

        $stored = $files === []
            ? []
            : $this->files->storeMany(
                $files,
                FileTarget::submission((int) $assignment->getKey(), (int) $live->getAttribute('student_id'), $next),
                $this->rulesFor($assignment),
            );

        try {
            return DB::transaction(function () use ($live, $assignment, $text, $stored, $at, $next, $actor): AssignmentSubmission {
                $previous = $this->lock($live);

                if (! $previous->isLive()) {
                    throw CourseRuleException::refuse('submission', 'That attempt has already been replaced.');
                }

                $late = $assignment->isLateAt($at);

                $replacement = new AssignmentSubmission;
                $replacement->forceFill([
                    'assignment_id' => (int) $assignment->getKey(),
                    'student_id' => (int) $previous->getAttribute('student_id'),
                    'student_batch_enrollment_id' => (int) $previous->getAttribute('student_batch_enrollment_id'),
                    'batch_id' => (int) $previous->getAttribute('batch_id'),
                    'attempt_no' => $next,
                    'submission_text' => $text,
                    'status' => SubmissionStatus::Submitted->value,
                    'submitted_at' => $at,
                    // The new attempt re-decides lateness against the same deadline — being on time
                    // the first time does not make a late second attempt on time.
                    'is_late' => $late,
                    'minutes_late' => $late ? $assignment->minutesLateAt($at) : null,
                    'total_marks' => (string) $assignment->getAttribute('total_marks'),
                    'penalty_marks' => '0.00',
                    'files_count' => count($stored),
                ]);

                // The predecessor is superseded FIRST: `current_guard` is generated from `status`, so
                // until this lands there are momentarily two rows whose guard is 1, and `uq_as_live`
                // would refuse the insert.
                $previous->forceFill(['status' => SubmissionStatus::Superseded->value])->saveQuietly();

                $replacement->save();

                $previous->forceFill(['superseded_by_id' => (int) $replacement->getKey()])->saveQuietly();

                foreach ($stored as $file) {
                    $this->attachFile($replacement, $file, $actor);
                }

                $this->assignments->recountCaches($assignment);

                $this->audit($replacement, 'Submission replaced', [
                    'old' => ['attempt_no' => $previous->getAttribute('attempt_no'), 'submission_id' => $previous->getKey()],
                    'attributes' => ['attempt_no' => $next, 'is_late' => $late],
                ], self::MODULE);

                return $replacement->refresh();
            });
        } catch (Throwable $e) {
            foreach ($stored as $file) {
                $this->files->delete($file);
            }

            throw $e;
        }
    }

    /**
     * A student taking back work they have not handed in. **Drafts only** — the one deletion this phase
     * permits, because a draft is not evidence of anything.
     */
    public function withdraw(AssignmentSubmission $draft, ?User $actor = null): void
    {
        DB::transaction(function () use ($draft): void {
            $locked = $this->lock($draft);

            if (! $locked->isDraft()) {
                throw CourseRuleException::refuse(
                    'submission',
                    'Work that has been handed in is not withdrawn. Ask your teacher to return it instead.',
                );
            }

            $assignment = $locked->assignment;
            $files = $locked->files()->get();

            foreach ($files as $file) {
                $this->files->delete($file->storedFile());
                $file->delete();
            }

            $this->audit($locked, 'Draft withdrawn', [
                'old' => ['assignment_id' => $locked->getAttribute('assignment_id'), 'files' => $files->count()],
            ], self::MODULE);

            // The model refuses delete() for every role; a draft is the documented exception and goes
            // through the quiet force path.
            $locked->forceDeleteQuietly();

            if ($assignment instanceof Assignment) {
                $this->assignments->recountCaches($assignment);
            }
        });
    }

    /**
     * Mark it. INV-19-6's middle layer, and the only place `penalty_marks`, `percentage` and
     * `is_passed` are written — all three from `AssignmentGradeCalculator`, so the grading grid, the
     * bulk grader and the amend path cannot compute them three different ways.
     *
     * @param  array<string, mixed>  $data
     */
    public function grade(AssignmentSubmission $submission, array $data, ?User $actor = null, ?UploadedFile $feedbackFile = null): AssignmentSubmission
    {
        $actor ??= Auth::user();

        $stored = $feedbackFile instanceof UploadedFile
            ? $this->files->store(
                $feedbackFile,
                FileTarget::feedback((int) $submission->getAttribute('assignment_id'), (int) $submission->getKey(), $submission),
                FileRules::feedback(),
                'feedback_file',
            )
            : null;

        return DB::transaction(function () use ($submission, $data, $actor, $stored): AssignmentSubmission {
            $locked = $this->lock($submission);
            $assignment = $locked->assignment ?? Assignment::query()->findOrFail($locked->getAttribute('assignment_id'));

            $this->assertGradable($locked);

            $outcome = $this->outcomeFor($locked, $assignment, $data);

            $locked->forceFill(array_merge($outcome->toColumns(), [
                'feedback' => $this->cleanText($data['feedback'] ?? null),
                'status' => SubmissionStatus::Graded->value,
                'graded_by' => $actor?->getKey(),
                'graded_at' => Carbon::now(),
                'returned_at' => null,
                // Released now when the assignment shows marks as they are entered; otherwise the
                // teacher marks privately and releases the batch in one go.
                'marks_released_at' => (bool) $assignment->getAttribute('marks_visible_to_students')
                    ? Carbon::now()
                    : $locked->getAttribute('marks_released_at'),
            ], $stored === null ? [] : [
                'feedback_file_path' => $stored->path,
                'feedback_file_original_name' => $stored->originalName,
            ]))->saveQuietly();

            $this->assignments->recountCaches($assignment);

            $this->audit($locked, 'Submission graded', [
                'attributes' => [
                    'obtained_marks' => $outcome->obtainedMarks,
                    'penalty_marks' => $outcome->penaltyMarks,
                    'final_marks' => $outcome->finalMarks,
                    'percentage' => $outcome->percentage,
                    'is_passed' => $outcome->isPassed,
                ],
            ], self::MODULE);

            return $locked->refresh();
        });
    }

    /** Hand it back for rework. The marks, if any, stay on the row — it is a request, not an erasure. */
    public function returnForRework(AssignmentSubmission $submission, string $feedback, ?User $actor = null): AssignmentSubmission
    {
        $feedback = trim($feedback);

        if ($feedback === '') {
            throw CourseRuleException::refuse('feedback', 'Say what needs reworking — returning work with no note helps nobody.');
        }

        return DB::transaction(function () use ($submission, $feedback, $actor): AssignmentSubmission {
            $locked = $this->lock($submission);

            if (! $locked->isLive() || $locked->isDraft()) {
                throw CourseRuleException::refuse('submission', 'Only work that has been handed in can be returned.');
            }

            $from = $locked->status;

            $locked->forceFill([
                'status' => SubmissionStatus::Returned->value,
                'feedback' => $feedback,
                'returned_at' => Carbon::now(),
                'graded_by' => $actor?->getKey(),
            ])->saveQuietly();

            $assignment = $locked->assignment;

            if ($assignment instanceof Assignment) {
                $this->assignments->recountCaches($assignment);
            }

            $this->audit($locked, 'Submission returned for rework', [
                'old' => ['status' => $from?->value],
                'attributes' => ['status' => SubmissionStatus::Returned->value],
            ], self::MODULE);

            return $locked->refresh();
        });
    }

    /**
     * Release every graded mark on an assignment at once — for a teacher who marked the batch privately
     * and wants the class to see the results together.
     */
    public function releaseMarks(Assignment $assignment, ?User $actor = null): int
    {
        return DB::transaction(function () use ($assignment): int {
            $rows = $assignment->liveSubmissions()
                ->where('status', SubmissionStatus::Graded->value)
                ->whereNull('marks_released_at')
                ->get();

            $now = Carbon::now();

            foreach ($rows as $row) {
                $row->forceFill(['marks_released_at' => $now])->saveQuietly();
            }

            if ($rows->isNotEmpty()) {
                $this->audit($assignment, 'Marks released', [
                    'attributes' => ['released' => $rows->count()],
                ], self::MODULE);
            }

            return $rows->count();
        });
    }

    /**
     * Change a mark a student has already seen. INV-20-5's discipline applied to assignments: the same
     * ceiling check, plus a mandatory reason and an activity row carrying old and new.
     *
     * @param  array<string, mixed>  $data
     */
    public function amend(AssignmentSubmission $submission, array $data, string $reason, ?User $actor = null): AssignmentSubmission
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::refuse('reason', 'A mark that has been seen does not change without a reason.');
        }

        $actor ??= Auth::user();

        return DB::transaction(function () use ($submission, $data, $reason, $actor): AssignmentSubmission {
            $locked = $this->lock($submission);
            $assignment = $locked->assignment ?? Assignment::query()->findOrFail($locked->getAttribute('assignment_id'));

            if (! $locked->status->isGraded()) {
                throw CourseRuleException::refuse('submission', 'Only a graded submission is amended. Grade it instead.');
            }

            $before = $locked->only(['obtained_marks', 'penalty_marks', 'final_marks', 'percentage', 'is_passed', 'feedback']);

            $outcome = $this->outcomeFor($locked, $assignment, $data);

            $locked->forceFill(array_merge($outcome->toColumns(), [
                'feedback' => $this->cleanText($data['feedback'] ?? null) ?? $locked->getAttribute('feedback'),
                'amended_at' => Carbon::now(),
                'amended_by' => $actor?->getKey(),
                'amendment_reason' => mb_substr($reason, 0, 255),
            ]))->saveQuietly();

            $this->assignments->recountCaches($assignment);

            $this->audit($locked, 'Mark amended after release', [
                'old' => $before,
                'attributes' => $outcome->toColumns(),
            ], self::MODULE, $reason);

            return $locked->refresh();
        });
    }

    /**
     * One `missed` row per roster student who handed nothing in. Idempotent: running it twice adds
     * nothing, because the query skips anyone who already has a live row and `uq_as_live` would refuse
     * a second one anyway.
     *
     * **An abandoned draft is also a miss, and that is not obvious.** A draft counts as a *live* row to
     * `uq_as_live`, so a student who opened the form and never submitted would otherwise be skipped
     * here — leaving them neither submitted nor missed, invisible to both counts and stuck in
     * `outstanding()` for ever on an assignment that stopped collecting weeks ago. They handed nothing
     * in; the row says so.
     */
    public function markMissed(Assignment $assignment): int
    {
        $roster = app(BatchEnrollmentService::class)->roster(
            $assignment->batch ?? $assignment->batch()->firstOrFail(),
            // `deadline_at` casts to CarbonImmutable; `roster()` takes the mutable one.
            Carbon::parse($assignment->getAttribute('deadline_at')),
        );

        if ($roster->isEmpty()) {
            return 0;
        }

        $written = 0;

        // Drafts first: they occupy the live slot, so they are converted rather than added to.
        foreach ($assignment->liveSubmissions()->where('status', SubmissionStatus::Draft->value)->get() as $abandoned) {
            $abandoned->forceFill([
                'status' => SubmissionStatus::Missed->value,
                'submitted_at' => $assignment->getAttribute('deadline_at'),
                'is_late' => true,
            ])->saveQuietly();

            $written++;
        }

        $alreadyHave = $assignment->liveSubmissions()->pluck('student_id')
            ->map(static fn (mixed $id): int => (int) $id)->all();

        foreach ($roster as $enrollment) {
            $studentId = (int) $enrollment->getAttribute('student_id');

            if (in_array($studentId, $alreadyHave, true)) {
                continue;
            }

            $row = new AssignmentSubmission;
            $row->forceFill([
                'assignment_id' => (int) $assignment->getKey(),
                'student_id' => $studentId,
                'student_batch_enrollment_id' => (int) $enrollment->getKey(),
                'batch_id' => (int) $assignment->getAttribute('batch_id'),
                'attempt_no' => 1,
                // Written by the sweeper, never by a student. `submitted_at` stays null, which
                // `chk_asub_submitted` permits only because `missed` is not `draft` — see below.
                'status' => SubmissionStatus::Missed->value,
                'submitted_at' => $assignment->deadline_at,
                'is_late' => true,
                'minutes_late' => null,
                'total_marks' => (string) $assignment->getAttribute('total_marks'),
                'penalty_marks' => '0.00',
            ]);

            try {
                $row->save();
                $written++;
            } catch (Throwable) {
                // Somebody submitted between the roster read and this insert. `uq_as_live` refused it,
                // which is the correct outcome and not an error worth failing the sweep over.
                continue;
            }
        }

        if ($written > 0) {
            $this->audit($assignment, 'Missed submissions recorded', [
                'attributes' => ['marked_missed' => $written, 'deadline_at' => $assignment->getAttribute('deadline_at')],
            ], self::MODULE);
        }

        return $written;
    }

    /**
     * The grading grid. **Every row is validated before any row is written** — the
     * `AttendanceService::import` discipline: a teacher who mistypes one mark out of thirty gets one
     * error and thirty unchanged rows, not one error and twenty-nine saved.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function bulkGrade(Assignment $assignment, array $rows, ?User $actor = null): BulkGradeResult
    {
        $actor ??= Auth::user();

        $errors = [];
        $validated = [];

        foreach ($rows as $index => $row) {
            $id = (int) ($row['submission_id'] ?? 0);
            $submission = AssignmentSubmission::query()->find($id);

            if (! $submission instanceof AssignmentSubmission
                || (int) $submission->getAttribute('assignment_id') !== (int) $assignment->getKey()) {
                $errors[$index] = 'That submission does not belong to this assignment.';

                continue;
            }

            try {
                $this->assertGradable($submission);
                $this->outcomeFor($submission, $assignment, $row);
                $validated[$index] = [$submission, $row];
            } catch (Throwable $e) {
                $errors[$index] = $e instanceof ValidationException
                    ? implode(' ', $e->validator->errors()->all())
                    : $e->getMessage();
            }
        }

        if ($errors !== []) {
            return new BulkGradeResult(graded: 0, errors: $errors);
        }

        $graded = DB::transaction(function () use ($validated, $actor, $assignment): int {
            $count = 0;

            foreach ($validated as [$submission, $row]) {
                $this->grade($submission, $row, $actor);
                $count++;
            }

            $this->assignments->recountCaches($assignment);

            return $count;
        });

        return new BulkGradeResult(graded: $graded, errors: []);
    }

    /**
     * §6.4 — the caller has run the permission chain. A **feedback** file additionally requires
     * `marks_released_at` on the student path, which is the one rule that cannot live in the policy
     * alone because the policy does not know which of the two files is being asked for.
     */
    public function streamFile(AssignmentSubmissionFile $file): StreamedResponse
    {
        return $this->files->stream($file->storedFile(), StreamOptions::attachment(), FileRules::submission());
    }

    public function streamFeedbackFile(AssignmentSubmission $submission, User $viewer): StreamedResponse
    {
        $path = (string) $submission->getAttribute('feedback_file_path');

        if ($path === '') {
            abort(404, 'There is no feedback file on this submission.');
        }

        $isOwner = (int) ($submission->student?->getAttribute('user_id') ?? 0) === (int) $viewer->getKey();

        if ($isOwner && ! $submission->marksVisibleToStudent()) {
            abort(404, 'Your feedback has not been released yet.');
        }

        return $this->files->stream(
            new StoredFile(
                disk: FileRules::DISK_PRIVATE,
                path: $path,
                originalName: (string) ($submission->getAttribute('feedback_file_original_name') ?? 'feedback'),
                extension: pathinfo($path, PATHINFO_EXTENSION),
                mimeType: 'application/octet-stream',
                sizeBytes: 0,
            ),
            StreamOptions::attachment(),
            FileRules::feedback(),
        );
    }

    // -------------------------------------------------------------------------------------------

    /**
     * INV-19-6's service layer. Refuses a mark above the row's **snapshotted** total — not the
     * assignment's current one, so amending an assignment's total never retroactively invalidates a
     * mark already given.
     *
     * @param  array<string, mixed>  $data
     */
    private function outcomeFor(
        AssignmentSubmission $submission,
        Assignment $assignment,
        array $data,
    ): GradeOutcome {
        $total = (string) $submission->getAttribute('total_marks');
        $raw = $data['obtained_marks'] ?? null;

        if ($raw === null || $raw === '') {
            throw CourseRuleException::refuse('obtained_marks', 'Enter a mark.');
        }

        $obtained = Money::round((string) $raw, 2);

        if (Money::compare($obtained, '0.00') < 0) {
            throw CourseRuleException::refuse('obtained_marks', 'A mark cannot be negative.');
        }

        if (Money::compare($obtained, $total) > 0) {
            throw CourseRuleException::refuse('obtained_marks', sprintf(
                'This assignment is out of %s, so %s is not a possible mark.',
                $total,
                $obtained,
            ));
        }

        return $this->calculator->calculate(
            obtainedMarks: $obtained,
            totalMarks: $total,
            latePenaltyPercentage: (string) $assignment->getAttribute('late_penalty_percentage'),
            isLate: (bool) $submission->getAttribute('is_late'),
            passingMarks: $assignment->getAttribute('passing_marks') === null
                ? null
                : (string) $assignment->getAttribute('passing_marks'),
        );
    }

    private function assertGradable(AssignmentSubmission $submission): void
    {
        if (! $submission->isLive()) {
            throw CourseRuleException::refuse('submission', 'That attempt has been replaced. Mark the current one.');
        }

        if ($submission->isDraft()) {
            throw CourseRuleException::refuse('submission', 'This has not been handed in yet.');
        }

        if ($submission->status === SubmissionStatus::Missed) {
            throw CourseRuleException::refuse('submission', 'Nothing was handed in, so there is nothing to mark.');
        }
    }

    /** §6.8: refused outright when late and lateness is not allowed, or past the hard cutoff. */
    private function assertAcceptsWork(Assignment $assignment, Carbon $at): void
    {
        if (! $assignment->status->acceptsSubmissions()) {
            throw CourseRuleException::refuse('assignment', 'This assignment is not accepting work.');
        }

        if (! $assignment->isLateAt($at)) {
            return;
        }

        if (! (bool) $assignment->getAttribute('late_submission_allowed')) {
            throw CourseRuleException::refuse('assignment', 'The deadline has passed and late work is not accepted.');
        }

        $cutoff = $assignment->getAttribute('late_cutoff_at');

        if ($cutoff !== null && $at->greaterThan(Carbon::parse($cutoff))) {
            throw CourseRuleException::refuse('assignment', 'The final cutoff for this assignment has passed.');
        }
    }

    /**
     * `submission_type` satisfied. The four booleans plus `requiresEither()` live on the enum so the
     * Form Request, the screen and this all ask the same object the same question.
     */
    private function assertPayload(Assignment $assignment, ?string $text, int $fileCount): void
    {
        $type = $assignment->submission_type;
        $hasText = $text !== null && $text !== '';

        if ($type->requiresFile() && $fileCount < 1) {
            throw CourseRuleException::refuse('files', 'This assignment needs a file.');
        }

        if ($type->requiresText() && ! $hasText) {
            throw CourseRuleException::refuse('submission_text', 'This assignment needs your written answer.');
        }

        if ($type->requiresEither() && $fileCount < 1 && ! $hasText) {
            throw CourseRuleException::refuse('submission_text', 'Attach a file or type your answer.');
        }

        if (! $type->allowsFile() && $fileCount > 0) {
            throw CourseRuleException::refuse('files', 'This assignment is answered in the box, not with a file.');
        }

        $max = (int) $assignment->getAttribute('max_files');

        if ($fileCount > $max) {
            throw CourseRuleException::refuse('files', sprintf('Attach at most %d %s.', $max, $max === 1 ? 'file' : 'files'));
        }
    }

    /** The assignment's own list narrows `FileRules::submission()`; it can never widen it. */
    private function rulesFor(Assignment $assignment): FileRules
    {
        $extensions = $assignment->getAttribute('allowed_extensions');
        $maxMb = $assignment->getAttribute('max_file_size_mb');

        return FileRules::submission(
            is_array($extensions) && $extensions !== [] ? array_map('strval', $extensions) : null,
            $maxMb === null ? null : (int) $maxMb,
        );
    }

    private function attachFile(AssignmentSubmission $submission, StoredFile $stored, ?User $actor): void
    {
        $row = new AssignmentSubmissionFile;
        $row->forceFill(array_merge($stored->toColumns(), [
            'assignment_submission_id' => (int) $submission->getKey(),
            'uploaded_by' => $actor?->getKey(),
            'download_count' => 0,
        ]));
        $row->save();
    }

    private function liveFor(Assignment $assignment, Student $student): ?AssignmentSubmission
    {
        return $assignment->liveSubmissions()
            ->where('student_id', $student->getKey())
            ->first();
    }

    /**
     * The roster proof. A student with no enrollment on this batch has no business submitting to its
     * assignment, and the row is what makes that provable a year later.
     */
    private function requireEnrollment(Assignment $assignment, Student $student): StudentBatchEnrollment
    {
        $enrollment = StudentBatchEnrollment::query()
            ->where('student_id', $student->getKey())
            ->where('batch_id', $assignment->getAttribute('batch_id'))
            ->orderByDesc('id')
            ->first();

        if (! $enrollment instanceof StudentBatchEnrollment) {
            throw CourseRuleException::refuse('student_id', 'You are not enrolled on this batch.');
        }

        $status = $enrollment->status;

        if (! $status->countsInAttendance()) {
            throw CourseRuleException::refuse('student_id', 'Your enrollment on this batch is not active.');
        }

        return $enrollment;
    }

    private function cleanText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function lock(AssignmentSubmission $submission): AssignmentSubmission
    {
        /** @var AssignmentSubmission $locked */
        $locked = AssignmentSubmission::query()->withTrashed()->whereKey($submission->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }
}
