<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Institute\SheetResult;
use App\DataObjects\Support\AudienceInput;
use App\Enums\ExamAttendanceStatus;
use App\Enums\ExamStatus;
use App\Models\Institute\Exam;
use App\Models\Institute\ExamResult;
use App\Models\Institute\GradeScale;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Services\Support\NotificationService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Entering, verifying and publishing marks (phase-19-23 §6.10, INV-20-1..6).
 *
 * **INV-20-6: a sheet is all of it or none of it.** Every row is validated — on the roster, a valid
 * attendance status, marks present exactly when `appeared`, inside `0..total_marks` — *before a single
 * write happens*. One student who is not on the roster rejects the whole sheet, naming them. A marker
 * who mistypes one mark out of thirty gets one error and thirty unchanged rows, not one error and
 * twenty-nine saved.
 *
 * **INV-20-2: four columns are written only here, through `ResultCalculator`.** `percentage`, `grade`,
 * `grade_point` and `is_passed` are refused by the model unless `ExamResult::calculated()` is on the
 * stack — so a controller, a Form Request or a seeder cannot set them. A grade that can be typed is a
 * grade that can be typed wrong, and nothing downstream would ever notice.
 *
 * **INV-20-1: the total and the scale are snapshotted per row.** That is what lets `chk_er_marks`
 * compare two columns of the same row, and what makes a result card printed last term reproduce
 * exactly after somebody edits the exam.
 *
 * **INV-20-5: results are corrected, never deleted.** `amend()` is the only way a published mark
 * moves, and it insists on a reason.
 */
final class ExamResultService
{
    use WritesAuditTrail;

    private const MODULE = 'results';

    public function __construct(
        private readonly ResultCalculator $calculator,
        private readonly GradeScaleService $scales,
        private readonly BatchEnrollmentService $enrollments,
        private readonly ExamService $exams,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * The sheet a marker opens: the roster for the exam's own date, each student's existing row where
     * there is one, and the resolved scale. **Read-only — it writes nothing**, so opening a sheet to
     * look at it never changes the exam's status.
     *
     * @return array{exam: Exam, scale: GradeScale, rows: Collection<int, array<string, mixed>>}
     */
    public function openSheet(Exam $exam): array
    {
        $scale = $this->scales->resolveFor($exam);
        $roster = $this->roster($exam);
        $existing = $exam->results()->get()->keyBy('student_id');

        $rows = $roster->map(function (StudentBatchEnrollment $enrollment) use ($existing): array {
            $studentId = (int) $enrollment->getAttribute('student_id');
            $result = $existing->get($studentId);

            return [
                'student_id' => $studentId,
                'student' => $enrollment->student,
                'enrollment_id' => (int) $enrollment->getKey(),
                'result' => $result,
                'attendance_status' => $result?->attendance_status ?? ExamAttendanceStatus::Appeared,
                'obtained_marks' => $result?->getAttribute('obtained_marks'),
                'remarks' => $result?->getAttribute('remarks'),
            ];
        })->values();

        return ['exam' => $exam, 'scale' => $scale, 'rows' => $rows];
    }

    /**
     * Save a whole sheet in one transaction. See the class note — validation is complete before the
     * first write.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function saveSheet(Exam $exam, array $rows, ?User $actor = null): SheetResult
    {
        $actor ??= Auth::user();

        if (! $exam->acceptsResultEntry()) {
            throw CourseRuleException::refuse('status', sprintf(
                'Marks cannot be entered while this exam is %s. Mark it conducted first.',
                mb_strtolower($exam->status->label()),
            ));
        }

        $scale = $this->scales->resolveFor($exam);
        $roster = $this->roster($exam)->keyBy('student_id');
        $total = (string) $exam->getAttribute('total_marks');

        // ---------------------------------------------------------------- validate everything first
        $errors = [];
        $validated = [];

        foreach ($rows as $index => $row) {
            $studentId = (int) ($row['student_id'] ?? 0);
            $enrollment = $roster->get($studentId);

            if (! $enrollment instanceof StudentBatchEnrollment) {
                // INV-20-6: a stranger rejects the whole sheet, and the message names them.
                $errors[$index] = sprintf(
                    'Student #%d was not on this batch on %s, so they cannot have sat this exam.',
                    $studentId,
                    app_date($exam->getAttribute('scheduled_date')),
                );

                continue;
            }

            $attendance = ExamAttendanceStatus::tryFrom((string) ($row['attendance_status'] ?? ''));

            if (! $attendance instanceof ExamAttendanceStatus) {
                $errors[$index] = 'Choose whether they appeared, were absent, exempt or debarred.';

                continue;
            }

            $marks = $row['obtained_marks'] ?? null;
            $marks = $marks === '' ? null : $marks;

            if ($attendance->requiresMarks() && $marks === null) {
                $errors[$index] = 'They appeared, so they have a mark. Use “absent” if they did not sit it.';

                continue;
            }

            if (! $attendance->requiresMarks() && $marks !== null) {
                $errors[$index] = sprintf(
                    'A student marked %s has no mark — an absence is not a zero.',
                    mb_strtolower($attendance->label()),
                );

                continue;
            }

            if ($marks !== null) {
                if (! is_numeric((string) $marks)) {
                    $errors[$index] = 'A mark is a number.';

                    continue;
                }

                $marks = Money::round((string) $marks, 2);

                if (Money::compare($marks, '0.00') < 0) {
                    $errors[$index] = 'A mark cannot be negative.';

                    continue;
                }

                if (Money::compare($marks, $total) > 0) {
                    $errors[$index] = sprintf('This paper is out of %s, so %s is not a possible mark.', $total, $marks);

                    continue;
                }
            }

            $validated[$index] = [
                'enrollment' => $enrollment,
                'student_id' => $studentId,
                'attendance' => $attendance,
                'marks' => $marks === null ? null : (string) $marks,
                'remarks' => $this->cleanText($row['remarks'] ?? null),
            ];
        }

        if ($errors !== []) {
            return new SheetResult(saved: 0, updated: 0, errors: $errors);
        }

        // ---------------------------------------------------------------- then write all of it
        return DB::transaction(function () use ($exam, $validated, $scale, $total, $actor): SheetResult {
            $saved = 0;
            $updated = 0;
            $now = Carbon::now();

            foreach ($validated as $row) {
                $outcome = $this->calculator->calculate(
                    attendance: $row['attendance'],
                    obtainedMarks: $row['marks'],
                    totalMarks: $total,
                    bands: $scale->bands()->get(),
                    passingMarks: (string) $exam->getAttribute('passing_marks'),
                    scalePassPercentage: (string) $scale->getAttribute('pass_percentage'),
                );

                // `uq_er_exam_student` is the guard; this is the upsert it backs (INV-20-6).
                $result = ExamResult::query()
                    ->where('exam_id', $exam->getKey())
                    ->where('student_id', $row['student_id'])
                    ->first();

                $existed = $result instanceof ExamResult;

                if (! $existed) {
                    $result = new ExamResult;
                    $result->forceFill([
                        'exam_id' => (int) $exam->getKey(),
                        'student_id' => $row['student_id'],
                        'student_batch_enrollment_id' => (int) $row['enrollment']->getKey(),
                        'batch_id' => (int) $exam->getAttribute('batch_id'),
                        'course_id' => (int) $exam->getAttribute('course_id'),
                        // INV-20-1: snapshotted at entry, not read through a join later.
                        'total_marks' => $total,
                        'grade_scale_id' => (int) $scale->getKey(),
                    ]);
                }

                ExamResult::calculated(function () use ($result, $row, $outcome, $actor, $now): void {
                    $result->forceFill(array_merge($outcome->toColumns(), [
                        'remarks' => $row['remarks'],
                        'entered_by' => $actor?->getKey(),
                        'entered_at' => $now,
                    ]))->saveQuietly();
                });

                $existed ? $updated++ : $saved++;
            }

            // §2.28.4: `marking` is set on the first saved row rather than offered as a button.
            if ($exam->status === ExamStatus::Conducted) {
                $this->exams->markMarking($exam);
            }

            $this->exams->recountCaches($exam->refresh());

            $this->audit($exam, 'Result sheet saved', [
                'attributes' => ['saved' => $saved, 'updated' => $updated],
            ], self::MODULE);

            return new SheetResult(saved: $saved, updated: $updated, errors: []);
        });
    }

    /**
     * The §2.28.4 four-eyes step.
     *
     * **The verifier must not be the enterer**, and the check is per row rather than on the exam: a
     * sheet entered by two people needs a third who entered none of it. Checking only the exam's own
     * `entered_by` would miss exactly the case the rule exists for.
     */
    public function verify(Exam $exam, ?User $actor = null): Exam
    {
        $actor ??= Auth::user();

        return DB::transaction(function () use ($exam, $actor): Exam {
            $locked = $this->lock($exam);

            if ($locked->results()->doesntExist()) {
                throw CourseRuleException::refuse('status', 'There is nothing to verify — no marks have been entered.');
            }

            if ($this->verificationRequired()) {
                $enteredByActor = $locked->results()
                    ->where('entered_by', $actor?->getKey())
                    ->exists();

                if ($enteredByActor) {
                    throw CourseRuleException::refuse(
                        'verified_by',
                        'You entered some of these marks, so somebody else has to check them.',
                    );
                }
            }

            $now = Carbon::now();

            $locked->forceFill([
                'results_verified_at' => $now,
                'results_verified_by' => $actor?->getKey(),
            ])->saveQuietly();

            ExamResult::calculated(function () use ($locked, $actor, $now): void {
                $locked->results()->get()->each(
                    static fn (ExamResult $row) => $row->forceFill([
                        'verified_by' => $actor?->getKey(),
                        'verified_at' => $now,
                    ])->saveQuietly()
                );
            });

            $this->audit($locked, 'Results verified', [
                'attributes' => ['rows' => $locked->results()->count()],
            ], self::MODULE);

            return $locked->refresh();
        });
    }

    /**
     * Publish: every roster student must have a row, verification must have happened where the setting
     * demands it, and positions are computed before anything becomes visible.
     *
     * **A missing row blocks publication**, because a student whose mark was never entered would
     * otherwise see an exam with results published and nothing of their own — and conclude they scored
     * nothing.
     */
    public function publish(Exam $exam, ?User $actor = null): Exam
    {
        $actor ??= Auth::user();

        $published = DB::transaction(function () use ($exam, $actor): Exam {
            $locked = $this->lock($exam);

            $roster = $this->roster($locked);
            $entered = $locked->results()->pluck('student_id')->map(static fn (mixed $id): int => (int) $id)->all();
            $missing = $roster->reject(
                static fn (StudentBatchEnrollment $e): bool => in_array((int) $e->getAttribute('student_id'), $entered, true)
            );

            if ($missing->isNotEmpty()) {
                throw CourseRuleException::refuse('status', sprintf(
                    '%d student%s on the roster %s no mark yet. Enter the whole sheet before publishing.',
                    $missing->count(),
                    $missing->count() === 1 ? '' : 's',
                    $missing->count() === 1 ? 'has' : 'have',
                ));
            }

            if ($this->verificationRequired() && $locked->getAttribute('results_verified_at') === null) {
                throw CourseRuleException::refuse(
                    'status',
                    'These results have not been checked by a second person, which this institute requires.',
                );
            }

            $now = Carbon::now();
            $rows = $locked->results()->get();

            $positions = $this->calculator->positions(
                $rows->map(static fn (ExamResult $r): array => [
                    'id' => (int) $r->getKey(),
                    'percentage' => $r->getAttribute('percentage') === null ? null : (string) $r->getAttribute('percentage'),
                ])->all()
            );

            ExamResult::calculated(function () use ($rows, $positions, $now): void {
                foreach ($rows as $row) {
                    $row->forceFill([
                        'position_in_batch' => $positions[(int) $row->getKey()] ?? null,
                        'published_at' => $now,
                    ])->saveQuietly();
                }
            });

            $locked->forceFill([
                'status' => ExamStatus::ResultsPublished->value,
                'results_published_at' => $now,
                'results_published_by' => $actor?->getKey(),
            ])->saveQuietly();

            $this->exams->recountCaches($locked);

            $this->audit($locked, 'Results published', [
                'attributes' => ['rows' => $rows->count(), 'ranked' => count($positions)],
            ], self::MODULE);

            return $locked->refresh();
        });

        // **Each student with a row, and nobody else** (§10.3). A published result is personal:
        // telling the whole batch that results are out would be telling each of them something
        // about everybody else. `student_portal.results` is the event's `requiredPermission`, so a
        // student whose portal cannot show results is dropped by the service rather than given a
        // row that links to a 403.
        //
        // After the transaction, never inside it.
        $userIds = DB::table('exam_results')
            ->join('students', 'students.id', '=', 'exam_results.student_id')
            ->where('exam_results.exam_id', $published->getKey())
            ->whereNull('students.deleted_at')
            ->whereNotNull('students.user_id')
            ->pluck('students.user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($userIds !== []) {
            $this->notifications->dispatch('result.published', AudienceInput::of($userIds), [
                'title' => 'Your result is out',
                'body' => (string) $published->getAttribute('name'),
                'url' => '/student/results',
                'exam_id' => (int) $published->getKey(),
            ], $actor);
        }

        return $published;
    }

    /**
     * Take them back, with a reason.
     *
     * **Notifies nobody.** A retraction is a conversation a human has, not a push notification — and a
     * student told "your result has been withdrawn" by a system, with no explanation attached, is worse
     * off than one their teacher rings.
     */
    public function unpublish(Exam $exam, string $reason, ?User $actor = null): Exam
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::refuse('reason', 'Say why the results are being withdrawn.');
        }

        return DB::transaction(function () use ($exam, $reason): Exam {
            $locked = $this->lock($exam);

            if (! $locked->isPublished()) {
                throw CourseRuleException::refuse('status', 'These results are not published.');
            }

            ExamResult::calculated(function () use ($locked): void {
                $locked->results()->get()->each(
                    static fn (ExamResult $row) => $row->forceFill(['published_at' => null])->saveQuietly()
                );
            });

            $locked->forceFill([
                'status' => ExamStatus::Marking->value,
                'results_published_at' => null,
                'results_published_by' => null,
            ])->saveQuietly();

            $this->audit($locked, 'Results withdrawn', [
                'old' => ['status' => ExamStatus::ResultsPublished->value],
                'attributes' => ['status' => ExamStatus::Marking->value],
            ], self::MODULE, $reason);

            return $locked->refresh();
        });
    }

    /**
     * INV-20-5. The one way a mark moves after it has been given, and it carries who and why.
     *
     * @param  array<string, mixed>  $data
     */
    public function amend(ExamResult $result, array $data, string $reason, ?User $actor = null): ExamResult
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::refuse('reason', 'A mark somebody has been given does not change without a reason.');
        }

        $actor ??= Auth::user();

        return DB::transaction(function () use ($result, $data, $reason, $actor): ExamResult {
            $locked = ExamResult::query()->whereKey($result->getKey())->lockForUpdate()->firstOrFail();
            $exam = $locked->exam ?? Exam::query()->findOrFail($locked->getAttribute('exam_id'));

            $attendance = ExamAttendanceStatus::tryFrom((string) ($data['attendance_status'] ?? $locked->attendance_status->value))
                ?? $locked->attendance_status;

            $marks = $data['obtained_marks'] ?? $locked->getAttribute('obtained_marks');
            $marks = ($marks === '' || $marks === null) ? null : Money::round((string) $marks, 2);

            if ($attendance->requiresMarks() && $marks === null) {
                throw CourseRuleException::refuse('obtained_marks', 'A student who appeared has a mark.');
            }

            if (! $attendance->requiresMarks()) {
                $marks = null;
            }

            // The ceiling again, against the row's own snapshot — not the exam's current total.
            $total = (string) $locked->getAttribute('total_marks');

            if ($marks !== null && Money::compare($marks, $total) > 0) {
                throw CourseRuleException::refuse('obtained_marks', sprintf(
                    'This paper was out of %s, so %s is not a possible mark.',
                    $total,
                    $marks,
                ));
            }

            $scale = $locked->scale ?? $this->scales->resolveFor($exam);

            $before = $locked->only(['attendance_status', 'obtained_marks', 'percentage', 'grade', 'is_passed']);

            $outcome = $this->calculator->calculate(
                attendance: $attendance,
                obtainedMarks: $marks,
                totalMarks: $total,
                bands: $scale->bands()->get(),
                passingMarks: (string) $exam->getAttribute('passing_marks'),
                scalePassPercentage: (string) $scale->getAttribute('pass_percentage'),
            );

            ExamResult::calculated(function () use ($locked, $outcome, $data, $reason, $actor): void {
                $locked->forceFill(array_merge($outcome->toColumns(), [
                    'remarks' => $this->cleanText($data['remarks'] ?? null) ?? $locked->getAttribute('remarks'),
                    'amended_at' => Carbon::now(),
                    'amended_by' => $actor?->getKey(),
                    'amendment_reason' => mb_substr($reason, 0, 255),
                ]))->saveQuietly();
            });

            $this->exams->recountCaches($exam);

            $this->audit($locked, 'Result amended', [
                'old' => $before,
                'attributes' => $outcome->toColumns(),
            ], self::MODULE, $reason);

            return $locked->refresh();
        });
    }

    // -------------------------------------------------------------------------------------------

    /**
     * The roster on the exam's **own date** — not today's. A student who joined the batch last week was
     * never expected to sit an exam that happened a month ago.
     *
     * @return Collection<int, StudentBatchEnrollment>
     */
    private function roster(Exam $exam): Collection
    {
        return $this->enrollments->roster(
            $exam->batch ?? $exam->batch()->firstOrFail(),
            Carbon::parse((string) $exam->getAttribute('scheduled_date')),
        );
    }

    private function verificationRequired(): bool
    {
        return (bool) settings_repo()->get('institute.result_publish_requires_verification');
    }

    private function cleanText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, 500);
    }

    private function lock(Exam $exam): Exam
    {
        /** @var Exam $locked */
        $locked = Exam::query()->withTrashed()->whereKey($exam->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }
}
