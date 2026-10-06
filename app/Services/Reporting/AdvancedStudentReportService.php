<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\DataObjects\Institute\FeeSummary;
use App\DataObjects\Reporting\AdvancedReportFilters;
use App\DataObjects\Reporting\AdvancedReportPaginator;
use App\DataObjects\Reporting\AdvancedReportRow;
use App\Enums\AdmissionStage;
use App\Enums\DurationUnit;
use App\Enums\EnrollmentStatus;
use App\Enums\ProgressStatus;
use App\Enums\ReportPaymentStatus;
use App\Enums\ReportStudentStatus;
use App\Enums\ReversalApprovalStatus;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentStatus;
use App\Enums\Weekday;
use App\Models\Finance\PaymentReversal;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAdmission;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\Institute\StudentCourseProgress;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeePayment;
use App\Models\Institute\TimetableEntry;
use App\Models\User;
use App\Services\Institute\StudentFeeService;
use App\Support\Format;
use App\Support\Modules;
use App\Support\Money;
use DateTimeInterface;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Advanced Reports: students, enrolments and fees on one filterable screen (D177).
 *
 * **One row is one admission** — a student on one course (D172). A student on two courses is two
 * rows; the cards count `DISTINCT student_id` so they still count people.
 *
 * **One query, every consumer.** `query()` is the filtered base — joins, branch scope, filters and
 * the derived columns — and the table, the summary cards, the count that caps an export, the CSV,
 * the workbook, the PDF and the printed sheet are all built on it. That is the whole guarantee that
 * "export" means "what I am looking at": there is no second query to drift.
 *
 * **Everything is derived in SQL**, so filtering, sorting and paginating happen in the database:
 *
 *  - the batch is `COALESCE(admission.batch_id, latest enrolment's batch)` — an admission created by
 *    the two-step registration has no `batch_id` until one is assigned, and an enrolment created from
 *    the batch page never writes it back;
 *  - progress is `student_course_progress.completion_percentage` on that latest enrolment (never
 *    `student_batch_enrollments.progress_percentage`, which nothing writes), and a completed
 *    admission is 100% whatever the tracker last recomputed;
 *  - the money is the admission's four fee caches, which only `StudentFeeService::recomputeAdmission()`
 *    writes — this class reads them and never adds money up per row in PHP (D56, CLAUDE.md rule 4);
 *  - the payment bucket is one CASE over `StudentFeeService::admissionChargeRollup()` (the owning
 *    service's aggregate, joined in rather than re-derived here) and the admission's fee caches
 *    (see `ReportPaymentStatus`);
 *  - the student bucket is one CASE over the stage, the student's status and the latest enrolment's
 *    status (see `ReportStudentStatus`), used for the filter, the counts and the sort alike.
 *
 * **It follows its source modules.** With `students` switched off the report is closed — every
 * route carries `module:advanced_reports,students` — and with `student_fees` off no fee figure is
 * shown to anyone (`canSeeMoney()`): a disabled module keeps its data away from every screen, this
 * one included.
 *
 * Every status in the SQL is a bound enum value, never a literal; every sort key maps to a fixed
 * expression; every search term is LIKE-escaped and bound.
 *
 * **Money is withheld, not blanked.** Without `advanced_reports.view_financial` the money columns are
 * never selected, the money cards are never computed, a money sort falls back to the default, and the
 * export has no money columns at all (INV-23-2). The page itself never demands that permission.
 *
 * Branch isolation follows `StudentAdmission::forBranch()` — a user with a branch sees that branch's
 * admissions plus the ones belonging to no branch ([D-IN-5]) — applied to the qualified
 * `student_admissions.branch_id`, because the joins bring three more `branch_id` columns along.
 */
final class AdvancedStudentReportService
{
    /** Above this many rows a printed sheet is refused in favour of CSV/Excel. */
    public const PRINT_MAX_ROWS = 2000;

    /**
     * Above this many rows a PDF is refused in favour of CSV/Excel. Far lower than the printed sheet's
     * cap because dompdf lays every row out in memory, in the request: measured on this report's own
     * template, 250 rows take about 10 s and 216 MB, 500 rows about 40 s and 440 MB, and 1,000 rows
     * exhaust a 512 MB `memory_limit` — a 500 after a minute of a busy worker, not a file. 250 is the
     * largest size measured comfortably inside that limit, and still covers a whole batch or a month's
     * intake.
     */
    public const PDF_MAX_ROWS = 250;

    /** Rows per round-trip while streaming an export — fetched by id, never `get()` of everything. */
    public const EXPORT_CHUNK = 1000;

    /** The module the money comes from: switched off, the fee figures are withheld from everyone. */
    public const FEES_MODULE = 'student_fees';

    /** The fallback for `reports.export_max_rows` (the same default `ReportEngine` uses). */
    public const DEFAULT_EXPORT_MAX_ROWS = 200000;

    /**
     * Sort key => the SQL it orders by. `status` and `payment` are not here: they order by the CASE
     * expressions, which carry bindings. Constants only — nothing a caller types is ever in this map.
     */
    private const SORT_EXPRESSIONS = [
        'student_code' => 'st.student_code',
        'name' => 'st.name',
        'course' => 'co.name',
        'batch' => 'ba.code',
        'joining' => 'student_admissions.admission_date',
        'completion' => 'COALESCE(student_admissions.completed_on, ba.end_date)',
        'total' => 'student_admissions.charged_amount',
        'paid' => '(student_admissions.paid_amount - student_admissions.refunded_amount)',
        'remaining' => 'student_admissions.balance_amount',
    ];

    /** What the search box matches, every one with a LIKE on an escaped, bound term. */
    private const SEARCH_COLUMNS = [
        'st.name',
        'st.student_code',
        'st.registration_number',
        'st.phone',
        'st.email',
        'co.name',
        'co.code',
        'ba.name',
        'ba.code',
        'student_admissions.admission_number',
    ];

    /** The export's columns, in order; the three money ones are dropped for a viewer without money. */
    private const EXPORT_HEADERS = [
        'Student ID',
        'Student Name',
        'Admission No',
        'Phone',
        'Email',
        'Course',
        'Batch',
        'Joining Date',
        'Course Duration',
        'Course Completion Date',
        'Progress',
        'Total Fees',
        'Paid Amount',
        'Remaining Amount',
        'Payment Status',
        'Student Status',
    ];

    private const MONEY_HEADERS = ['Total Fees', 'Paid Amount', 'Remaining Amount'];

    /** `Course::durationLabel()` answers per (value, unit) pair, memoised for long exports. */
    private array $durations = [];

    public function __construct(
        private readonly StudentFeeService $fees,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Who may see what
    |--------------------------------------------------------------------------
    */

    /**
     * May this viewer see fee amounts? Asked, never required: the answer hides columns, cards and the
     * payment history, and it never turns into a 403 (SEC-21(b)).
     *
     * The fees module has to be on as well: switching `student_fees` off withholds every fee figure
     * from everyone, and this report must not be the one screen that still shows them.
     */
    public function canSeeMoney(User $viewer): bool
    {
        return Modules::enabled(self::FEES_MODULE) && $viewer->can('advanced_reports.view_financial');
    }

    /** The CSV/Excel ceiling — `reports.export_max_rows`, the same setting the report engine obeys. */
    public function exportMaxRows(): int
    {
        $value = setting('reports.export_max_rows', self::DEFAULT_EXPORT_MAX_ROWS);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_EXPORT_MAX_ROWS;
    }

    /*
    |--------------------------------------------------------------------------
    | The rows
    |--------------------------------------------------------------------------
    */

    /**
     * The filtered base query, every column the table and the exports read selected and aliased.
     * Unsorted — `sortedQuery()` adds the order.
     *
     * @return Builder<StudentAdmission>
     */
    public function query(AdvancedReportFilters $filters, User $viewer): Builder
    {
        return $this->selectRow($this->filtered($filters, $viewer), $this->canSeeMoney($viewer));
    }

    /**
     * `query()` in the requested order, with `student_admissions.id DESC` as the last key so two rows
     * with the same joining date never swap places between page 1 and page 2.
     *
     * @return Builder<StudentAdmission>
     */
    public function sortedQuery(AdvancedReportFilters $filters, User $viewer): Builder
    {
        return $this->applySort($this->query($filters, $viewer), $filters, $this->canSeeMoney($viewer));
    }

    /**
     * One page of rows, as `AdvancedReportRow`s, with the filters carried on the page links.
     *
     * The links carry `$filters->toQuery()` — the filters as the server applied them — never the raw
     * query string: a course the viewer may not filter by, a batch that does not fit the course, a
     * money sort for a viewer without money or a reversed date range are corrected once, and a page
     * link must not send the uncorrected version back. `AdvancedReportPaginator` keeps it that way
     * when the shared pagination component asks for `withQueryString()`.
     *
     * @return LengthAwarePaginator<int, AdvancedReportRow>
     */
    public function paginate(AdvancedReportFilters $filters, User $viewer, ?int $perPage = null): LengthAwarePaginator
    {
        $money = $this->canSeeMoney($viewer);

        $page = AdvancedReportPaginator::withFilters(
            $this->sortedQuery($filters, $viewer)->paginate($perPage ?? per_page()),
            $filters->toQuery(),
        );

        $page->through(fn (StudentAdmission $row): AdvancedReportRow => $this->present($row, $money));

        return $page;
    }

    /** How many rows match — what an export is capped on. */
    public function count(AdvancedReportFilters $filters, User $viewer): int
    {
        return $this->filtered($filters, $viewer)->count();
    }

    /**
     * The summary cards, in **one** aggregate over the same filtered base (no sort, no limit).
     *
     * People are counted once (`COUNT(DISTINCT student_id)`) and admissions as rows. A student with
     * one active and one completed course is counted in both of those cards — each card answers its
     * own question. The overdue *count* is shown to everybody; every money figure is computed and
     * returned only for a viewer who may see money, and is absent from the array otherwise.
     *
     * The sums are over the admission caches `StudentFeeService` writes, so they equal the column
     * totals of the table; SQL hands `SUM()` of a decimal back as a string, normalised through
     * `Money::of()` and never through a float.
     *
     * @return array{
     *     total_students: int,
     *     active_students: int,
     *     completed_students: int,
     *     total_enrolled: int,
     *     overdue_count: int,
     *     can_see_money: bool,
     *     total_fees?: string,
     *     total_paid?: string,
     *     total_remaining?: string,
     *     overdue_amount?: string
     * }
     */
    public function summary(AdvancedReportFilters $filters, User $viewer): array
    {
        $money = $this->canSeeMoney($viewer);
        [$status, $statusBindings] = $this->statusCase();
        [$payment, $paymentBindings] = $this->paymentCase();

        $query = $this->filtered($filters, $viewer)->toBase()
            ->selectRaw('COUNT(DISTINCT student_admissions.student_id) AS total_students')
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN ('.$status.') = ? THEN student_admissions.student_id END) AS active_students',
                [...$statusBindings, ReportStudentStatus::Active->value],
            )
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN ('.$status.') = ? THEN student_admissions.student_id END) AS completed_students',
                [...$statusBindings, ReportStudentStatus::Completed->value],
            )
            ->selectRaw('COUNT(*) AS total_enrolled')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN ('.$payment.') = ? THEN 1 ELSE 0 END), 0) AS overdue_count',
                [...$paymentBindings, ReportPaymentStatus::Overdue->value],
            );

        if ($money) {
            $query->selectRaw('COALESCE(SUM(student_admissions.charged_amount), 0) AS total_fees')
                ->selectRaw('COALESCE(SUM(student_admissions.paid_amount - student_admissions.refunded_amount), 0) AS total_paid')
                ->selectRaw('COALESCE(SUM(student_admissions.balance_amount), 0) AS total_remaining')
                ->selectRaw('COALESCE(SUM(fr.overdue_amount), 0) AS overdue_amount');
        }

        $row = (array) ($query->first() ?? []);

        $summary = [
            'total_students' => (int) ($row['total_students'] ?? 0),
            'active_students' => (int) ($row['active_students'] ?? 0),
            'completed_students' => (int) ($row['completed_students'] ?? 0),
            'total_enrolled' => (int) ($row['total_enrolled'] ?? 0),
            'overdue_count' => (int) ($row['overdue_count'] ?? 0),
            'can_see_money' => $money,
        ];

        if ($money) {
            $summary['total_fees'] = Money::of((string) ($row['total_fees'] ?? '0'));
            $summary['total_paid'] = Money::of((string) ($row['total_paid'] ?? '0'));
            $summary['total_remaining'] = Money::of((string) ($row['total_remaining'] ?? '0'));
            $summary['overdue_amount'] = Money::of((string) ($row['overdue_amount'] ?? '0'));
        }

        return $summary;
    }

    /*
    |--------------------------------------------------------------------------
    | Exports
    |--------------------------------------------------------------------------
    */

    /**
     * The export's column labels for this viewer, in order — the CSV header row, the workbook's bold
     * row and the PDF's `<th>`s. Money columns are absent (not blank) without `view_financial`.
     *
     * @return list<string>
     */
    public function exportHeaders(User $viewer): array
    {
        return $this->headers($this->canSeeMoney($viewer));
    }

    /**
     * Every matching row, in the screen's order, as a flat label => string array keyed by
     * `exportHeaders()`.
     *
     * A generator over chunks of `EXPORT_CHUNK` rows, so a 200,000-row CSV never holds 200,000 models
     * at once. **The order is decided once:** one query reads the ids of every matching row in the
     * screen's order, and each chunk is then fetched by primary key (`whereIn` on its ids) and yielded
     * in that order. Paging the sorted join with LIMIT/OFFSET instead (what `lazy()` does) would re-run
     * the filters, the derived columns and the sort for every chunk — two hundred full passes for a
     * 200,000-row file, inside one request.
     *
     * Dates are formatted (`Format::date()`); a completion that is only the batch's planned end says
     * "(expected)". Money is a plain decimal string ('12500.00', a negative one an advance) for
     * CSV/Excel, where a spreadsheet must be able to add it up; pass `$formatMoney` for a PDF or a
     * printed sheet, where a person reads it ('Rs 12,500.00', 'Rs 500.00 in advance').
     *
     * @return Generator<int, array<string, string>>
     */
    public function exportRows(AdvancedReportFilters $filters, User $viewer, bool $formatMoney = false): Generator
    {
        $money = $this->canSeeMoney($viewer);
        $headers = $this->headers($money);

        $ids = $this->applySort($this->filtered($filters, $viewer), $filters, $money)
            ->toBase()
            ->pluck('student_admissions.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach (array_chunk($ids, self::EXPORT_CHUNK) as $chunk) {
            $models = $this->selectRow($this->base($viewer), $money)
                ->whereIn('student_admissions.id', $chunk)
                ->get()
                ->keyBy(static fn (StudentAdmission $model): int => (int) $model->getKey());

            foreach ($chunk as $id) {
                $model = $models->get($id);

                // Gone between the two queries (deleted a moment ago): left out, never a half row.
                if ($model instanceof StudentAdmission) {
                    yield $this->exportRow($this->present($model, $money), $headers, $formatMoney);
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Filter options
    |--------------------------------------------------------------------------
    */

    /**
     * The course select: every course the viewer's branch can see, by name — `[id => name]`, the shape
     * every other institute filter uses.
     *
     * "Can see" is the union of two things, because the rows and the courses are scoped by different
     * columns: the courses of the viewer's branch (and of no branch), **and every course a row this
     * viewer can see sits on** — an admission of this branch on another branch's course, or on a
     * course archived since, is in the table, so it has to be in the select. The Form Request checks a
     * requested id against this same query (`course()`), so the list and the check never disagree.
     *
     * @return array<int, string>
     */
    public function courseOptions(User $viewer): array
    {
        return $this->courseOptionsQuery($viewer)
            ->orderBy('name')
            ->orderBy('id')
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])
            ->all();
    }

    /** The course `course_id` names, when this viewer may filter by it — else null (the filter is dropped). */
    public function course(int $courseId, User $viewer): ?Course
    {
        return $this->courseOptionsQuery($viewer)->whereKey($courseId)->first(['id', 'name']);
    }

    /**
     * The batch select — `[id => "CODE — Name"]`, newest first, narrowed to a course when one is
     * chosen. The page renders it (for the chosen course) and the `batches.options` JSON serves it,
     * so both read this.
     *
     * Scoped like `courseOptions()`: the branch's batches, plus every batch a visible row is shown
     * under (the same `COALESCE(admission's batch, latest enrolment's batch)` the table prints, a
     * batch deleted since included, as the table includes it). With no course chosen each label also
     * names its course — "CODE — Name (Course)" — so two courses' "Batch 01" can be told apart.
     *
     * @return array<int, string>
     */
    public function batchOptions(?int $courseId, User $viewer): array
    {
        return $this->batchOptionsQuery($viewer)
            ->when($courseId !== null, static fn (Builder $q): Builder => $q->where('course_id', $courseId))
            ->with(['course' => static fn ($q) => $q->withTrashed()->select(['id', 'name'])])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get(['id', 'code', 'name', 'course_id'])
            ->mapWithKeys(static fn (Batch $batch): array => [
                (int) $batch->getKey() => $courseId === null && $batch->course !== null
                    ? $batch->label().' ('.$batch->course->name.')'
                    : $batch->label(),
            ])
            ->all();
    }

    /** The batch `batch_id` names, when this viewer may filter by it — else null (the filter is dropped). */
    public function batch(int $batchId, User $viewer): ?Batch
    {
        return $this->batchOptionsQuery($viewer)->whereKey($batchId)->first(['id', 'code', 'name', 'course_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | One student
    |--------------------------------------------------------------------------
    */

    /**
     * Everything the detail page shows for one student and one of their admissions.
     *
     * The admissions list (the course switcher) is the same base query as the table, narrowed to this
     * student — so the status and payment badges here are the ones the row showed, from the same CASE.
     * The selected admission is the requested one when it belongs to this student and the viewer's
     * branch, otherwise the latest; an `admissionId` belonging to somebody else is ignored, not obeyed.
     *
     * **Branch: the same rule as the table.** The page opens when the student belongs to the viewer's
     * branch (or to none) or when at least one of their admissions does; otherwise it is a 404, not a
     * 403 — a 403 would confirm the record exists.
     *
     * Payments are present only for a viewer who may see money. The fee figures come from
     * `StudentFeeService::summaryFor()`; the history is rebuilt from the receipts and their reversals
     * and walked forward from the final fee with `Money`, and its closing balance must equal the
     * cached balance — when it does not, that is logged as a warning (the page still renders; a
     * reconciliation gap is for somebody to investigate, not a reason to hide the student).
     *
     * @return array{
     *     student: Student,
     *     admissions: list<AdvancedReportRow>,
     *     selected: ?AdvancedReportRow,
     *     can_see_money: bool,
     *     enrollment: ?array<string, mixed>,
     *     progress: ?array<string, mixed>,
     *     batch: ?array<string, mixed>,
     *     fees: ?array<string, mixed>,
     *     history: ?array<string, mixed>
     * }
     *
     * @throws ModelNotFoundException when neither the student nor any of their admissions is visible
     */
    public function studentDetail(Student $student, ?int $admissionId, User $viewer): array
    {
        $branchId = $this->branchOf($viewer);
        $money = $this->canSeeMoney($viewer);

        /** @var list<AdvancedReportRow> $admissions */
        $admissions = $this->selectRow($this->base($viewer), $money)
            ->where('student_admissions.student_id', $student->getKey())
            ->orderByDesc('student_admissions.admission_date')
            ->orderByDesc('student_admissions.id')
            ->get()
            ->map(fn (StudentAdmission $row): AdvancedReportRow => $this->present($row, $money))
            ->values()
            ->all();

        // One rule with the table: the page opens when the student is in the viewer's branch (or in
        // none) OR when one of their admissions is — the table scopes rows by the admission's branch,
        // so a row it lists must never lead to a 404. Neither is a 404, not a 403.
        $studentVisible = $branchId === null || $student->branch_id === null || (int) $student->branch_id === $branchId;

        if (! $studentVisible && $admissions === []) {
            throw (new ModelNotFoundException)->setModel(Student::class, [$student->getKey()]);
        }

        $selected = null;

        foreach ($admissions as $row) {
            if ($admissionId !== null && $row->admissionId === $admissionId) {
                $selected = $row;
                break;
            }
        }

        $selected ??= $admissions[0] ?? null;

        $detail = [
            'student' => $student,
            'admissions' => $admissions,
            'selected' => $selected,
            'can_see_money' => $money,
            'enrollment' => null,
            'progress' => null,
            'batch' => null,
            'fees' => null,
            'history' => null,
        ];

        if ($selected === null) {
            return $detail;
        }

        $admission = StudentAdmission::query()
            ->whereKey($selected->admissionId)
            ->first(['id', 'student_id', 'course_id', 'batch_id', 'stage', 'admission_number', 'admission_date', 'activated_on', 'completed_on']);

        if ($admission === null) {
            return $detail;
        }

        // The same enrolment the row's batch and progress came from: the highest live id.
        $enrollment = StudentBatchEnrollment::query()
            ->where('student_admission_id', $admission->getKey())
            ->orderByDesc('id')
            ->first(['id', 'batch_id', 'student_admission_id', 'roll_number', 'status', 'enrolled_on', 'completed_on']);

        // Trashed batches included, exactly as the row's join includes them: a batch deleted after the
        // student sat in it is still the batch they sat in.
        $batch = $selected->batchId === null ? null : Batch::withTrashed()
            ->with('teacher:id,name')
            ->whereKey($selected->batchId)
            ->first(['id', 'code', 'name', 'course_id', 'teacher_id', 'start_date', 'end_date', 'days', 'start_time', 'end_time']);

        $tracker = $enrollment === null ? null : StudentCourseProgress::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->first(['id', 'student_batch_enrollment_id', 'status', 'completion_percentage', 'topics_total', 'topics_completed']);

        $courseStart = $this->dateString($admission->activated_on)
            ?? $this->dateString($enrollment?->enrolled_on)
            ?? $this->dateString($batch?->start_date);
        $expectedEnd = $this->dateString($batch?->end_date);
        $actualEnd = $this->dateString($admission->completed_on) ?? $this->dateString($enrollment?->completed_on);
        $today = Carbon::now(Format::timezone())->toDateString();

        // Finished: the admission completed, its seat completed, or every topic covered. Shown as
        // 100%, whatever the tracker last recomputed (the row's progress column reads the same rule).
        $completed = $selected->stage === AdmissionStage::Completed
            || $enrollment?->status === EnrollmentStatus::Completed
            || bccomp($selected->progress, '100', 2) >= 0;
        $percentage = $completed ? '100.00' : $selected->progress;

        // The topic tracker alone says "not started" for every course nobody ticks topics on, which
        // contradicts a start date in the past beside it. So: finished is Completed; anything covered,
        // or an active admission whose course has begun, is In progress; otherwise Not started.
        $progressStatus = match (true) {
            $completed => ProgressStatus::Completed,
            Money::isPositive($percentage) => ProgressStatus::InProgress,
            $selected->stage === AdmissionStage::Active && $courseStart !== null && $courseStart <= $today => ProgressStatus::InProgress,
            default => ProgressStatus::Pending,
        };

        // "Not started" never sits beside a start date in the past: an admission that is not running
        // (still in the pipeline, or withdrawn) has no start of its own to show — the batch's start
        // is on the batch card. A start still ahead is shown: it is when the course will begin.
        if ($progressStatus === ProgressStatus::Pending && $courseStart !== null && $courseStart <= $today) {
            $courseStart = null;
        }

        $detail['enrollment'] = [
            'admission_number' => $selected->admissionNumber,
            'joining_date' => $selected->joiningDate,
            'course' => $selected->courseName,
            'course_duration' => $selected->courseDuration,
            'batch' => $selected->batchLabel(),
            'course_start' => $courseStart,
            'expected_completion' => $expectedEnd,
            'actual_completion' => $actualEnd,
            'status' => $selected->status,
            'stage' => $selected->stage,
            'completion_status' => $progressStatus,
        ];

        $detail['progress'] = [
            'course' => $selected->courseName,
            'start' => $courseStart,
            'end' => $actualEnd ?? $expectedEnd,
            'end_is_expected' => $actualEnd === null && $expectedEnd !== null,
            'percentage' => $percentage,
            'remaining' => Money::max(Money::ZERO, Money::sub('100.00', $percentage)),
            // A finished course is "All topics", not "0 of 9 topics"; a course with no topics is not
            // tracked (null — the page says so).
            'topics' => match (true) {
                $completed => 'All topics',
                $tracker === null || (int) $tracker->topics_total === 0 => null,
                default => $tracker->topicsCaption(),
            },
            'status' => $progressStatus,
            'completed_on' => $progressStatus === ProgressStatus::Completed ? $actualEnd : null,
        ];

        if ($batch !== null) {
            $detail['batch'] = $this->batchInfo($batch, $enrollment);
        }

        if ($money) {
            $summary = $this->fees->summaryFor($admission);

            $detail['fees'] = [
                'total_course_fee' => $summary->gross,
                'discount' => Money::add($summary->discount, $summary->scholarship),
                'final_fee' => $summary->net,
                'total_paid' => $summary->netReceived(),
                'remaining' => $summary->balance,
                'remaining_caption' => $summary->balanceCaption(),
                'in_advance' => $summary->isInAdvance(),
                // The row's own bucket, from the same CASE as the table, so the two can never disagree.
                'status' => $selected->payment,
                'next_due_date' => $summary->nextDueDate?->toDateString(),
                'charge_count' => $summary->chargeCount,
            ];

            $detail['history'] = $this->paymentHistory($admission, $summary);
        }

        return $detail;
    }

    /*
    |--------------------------------------------------------------------------
    | Query building
    |--------------------------------------------------------------------------
    */

    /**
     * The joins, the branch scope and the soft-delete exclusions — every row this viewer may see,
     * before any filter.
     *
     * @return Builder<StudentAdmission>
     */
    private function base(User $viewer): Builder
    {
        return $this->visibleAdmissions($viewer)
            ->join('courses as co', 'co.id', '=', 'student_admissions.course_id')
            ->leftJoinSub($this->latestEnrollments(), 'le', 'le.student_admission_id', '=', 'student_admissions.id')
            ->leftJoin('student_batch_enrollments as en', 'en.id', '=', 'le.enrollment_id')
            ->leftJoin('batches as ba', 'ba.id', '=', new Expression('COALESCE(student_admissions.batch_id, en.batch_id)'))
            ->leftJoin('student_course_progress as pr', static function (JoinClause $join): void {
                $join->on('pr.student_batch_enrollment_id', '=', 'en.id')->whereNull('pr.deleted_at');
            })
            ->leftJoinSub($this->fees->admissionChargeRollup(), 'fr', 'fr.student_admission_id', '=', 'student_admissions.id');
    }

    /**
     * Every admission this viewer may see, and nothing else: live admissions of live students, in the
     * viewer's branch or in none. `base()` builds the row on it, and the course and batch options are
     * read from it, so a filter can offer exactly what the table can contain.
     *
     * @return Builder<StudentAdmission>
     */
    private function visibleAdmissions(User $viewer): Builder
    {
        $query = StudentAdmission::query()
            ->join('students as st', 'st.id', '=', 'student_admissions.student_id')
            ->whereNull('st.deleted_at');

        $branchId = $this->branchOf($viewer);

        if ($branchId !== null) {
            $query->where(static function (Builder $q) use ($branchId): void {
                $q->where('student_admissions.branch_id', $branchId)->orWhereNull('student_admissions.branch_id');
            });
        }

        return $query;
    }

    /**
     * The latest live enrolment per admission. MAX(id) rather than "the active one": a dropped or
     * transferred-out seat is still the last batch the admission sat in, and a report that showed no
     * batch for every student who left would hide exactly the rows somebody is looking for.
     *
     * @return Builder<StudentBatchEnrollment>
     */
    private function latestEnrollments(): Builder
    {
        return StudentBatchEnrollment::query()
            ->whereNotNull('student_batch_enrollments.student_admission_id')
            ->groupBy('student_batch_enrollments.student_admission_id')
            ->select('student_batch_enrollments.student_admission_id')
            ->selectRaw('MAX(student_batch_enrollments.id) AS enrollment_id');
    }

    /**
     * The courses this viewer may filter by: the branch's live courses, or any course — archived
     * included — that a visible admission is on (see `courseOptions()`).
     *
     * @return Builder<Course>
     */
    private function courseOptionsQuery(User $viewer): Builder
    {
        $branchId = $this->branchOf($viewer);
        $onRows = $this->visibleAdmissions($viewer)->toBase()->select('student_admissions.course_id');

        return Course::withTrashed()->where(static function (Builder $q) use ($branchId, $onRows): void {
            $q->where(static function (Builder $own) use ($branchId): void {
                $own->whereNull('courses.deleted_at')->forBranch($branchId);
            })->orWhereIn('courses.id', $onRows);
        });
    }

    /**
     * The batches this viewer may filter by: the branch's live batches, or any batch — deleted
     * included — that a visible row is shown under (see `batchOptions()`).
     *
     * @return Builder<Batch>
     */
    private function batchOptionsQuery(User $viewer): Builder
    {
        $branchId = $this->branchOf($viewer);
        $onRows = $this->visibleAdmissions($viewer)
            ->leftJoinSub($this->latestEnrollments(), 'le', 'le.student_admission_id', '=', 'student_admissions.id')
            ->leftJoin('student_batch_enrollments as en', 'en.id', '=', 'le.enrollment_id')
            ->toBase()
            ->selectRaw('COALESCE(student_admissions.batch_id, en.batch_id) AS batch_id');

        return Batch::withTrashed()->where(static function (Builder $q) use ($branchId, $onRows): void {
            $q->where(static function (Builder $own) use ($branchId): void {
                $own->whereNull('batches.deleted_at')->forBranch($branchId);
            })->orWhereIn('batches.id', $onRows);
        });
    }

    /**
     * `base()` narrowed by the filters. No columns, no order — `query()`, `summary()` and `count()`
     * each add their own.
     *
     * @return Builder<StudentAdmission>
     */
    private function filtered(AdvancedReportFilters $filters, User $viewer): Builder
    {
        $query = $this->base($viewer);

        // `admission_date` is a DATE column: compared to dates, so no row is lost to a time component.
        if ($filters->from !== null) {
            $query->where('student_admissions.admission_date', '>=', $filters->from);
        }

        if ($filters->to !== null) {
            $query->where('student_admissions.admission_date', '<=', $filters->to);
        }

        if ($filters->courseId !== null) {
            $query->where('student_admissions.course_id', $filters->courseId);
        }

        // The batch the row *shows* — admission's own, else the latest enrolment's — so filtering by a
        // batch never lists a row under a different batch name.
        if ($filters->batchId !== null) {
            $query->where('ba.id', $filters->batchId);
        }

        if ($filters->status !== null) {
            [$sql, $bindings] = $this->statusCase();
            $query->whereRaw('('.$sql.') = ?', [...$bindings, $filters->status->value]);
        }

        if ($filters->payment !== null) {
            [$sql, $bindings] = $this->paymentCase();
            $query->whereRaw('('.$sql.') = ?', [...$bindings, $filters->payment->value]);
        }

        if ($filters->search !== null) {
            $like = '%'.$this->escapeLike($filters->search).'%';

            $query->where(static function (Builder $q) use ($like): void {
                foreach (self::SEARCH_COLUMNS as $column) {
                    $q->orWhere($column, 'like', $like);
                }
            });
        }

        return $query;
    }

    /**
     * The row's columns. Every attribute `present()` reads is selected here and nowhere else — strict
     * mode turns a forgotten one into an exception rather than a silent null. The money columns are
     * selected only for a viewer who may see them.
     *
     * @param  Builder<StudentAdmission>  $query
     * @return Builder<StudentAdmission>
     */
    private function selectRow(Builder $query, bool $money): Builder
    {
        [$status, $statusBindings] = $this->statusCase();
        [$payment, $paymentBindings] = $this->paymentCase();

        $query->select([
            'student_admissions.id',
            'student_admissions.admission_number',
            'student_admissions.student_id',
            'student_admissions.course_id',
            'student_admissions.stage',
            'student_admissions.admission_date',
            'student_admissions.completed_on',
            'st.student_code as student_code',
            'st.name as student_name',
            'st.registration_number as student_registration_number',
            'st.phone as student_phone',
            'st.email as student_email',
            'co.name as course_name',
            'co.code as course_code',
            'co.duration_value as course_duration_value',
            'co.duration_unit as course_duration_unit',
            'ba.id as report_batch_id',
            'ba.code as batch_code',
            'ba.name as batch_name',
        ])
            ->selectRaw('COALESCE(student_admissions.completed_on, ba.end_date) AS completion_date')
            ->selectRaw('CASE WHEN student_admissions.completed_on IS NULL AND ba.end_date IS NOT NULL THEN 1 ELSE 0 END AS completion_is_expected')
            // Finished — the admission completed, or its seat — is 100% whatever the tracker last
            // recomputed; the detail page reads the same rule (`studentDetail()`).
            ->selectRaw(
                'CASE WHEN student_admissions.stage = ? OR en.status = ? THEN 100 ELSE COALESCE(pr.completion_percentage, 0) END AS progress_percentage',
                [AdmissionStage::Completed->value, EnrollmentStatus::Completed->value],
            )
            ->selectRaw('('.$status.') AS report_status', $statusBindings)
            ->selectRaw('('.$payment.') AS payment_status', $paymentBindings)
            ->selectRaw('COALESCE(fr.n_overdue, 0) AS overdue_charges');

        if ($money) {
            $query->addSelect(['student_admissions.charged_amount', 'student_admissions.balance_amount'])
                ->selectRaw('(student_admissions.paid_amount - student_admissions.refunded_amount) AS net_paid')
                ->selectRaw('COALESCE(fr.overdue_amount, 0) AS overdue_amount');
        }

        return $query;
    }

    /**
     * The order. Anything off the allowlist — or a money key for a viewer without money — is the
     * default (joining date, newest first). The direction is one of two literals chosen here.
     *
     * @param  Builder<StudentAdmission>  $query
     * @return Builder<StudentAdmission>
     */
    private function applySort(Builder $query, AdvancedReportFilters $filters, bool $money): Builder
    {
        $sort = $filters->sort;
        $direction = $filters->direction === 'asc' ? 'asc' : 'desc';

        $allowed = $sort === 'status'
            || $sort === 'payment'
            || array_key_exists($sort, self::SORT_EXPRESSIONS);

        if (! $allowed || (! $money && in_array($sort, AdvancedReportFilters::MONEY_SORTS, true))) {
            $sort = AdvancedReportFilters::DEFAULT_SORT;
            $direction = AdvancedReportFilters::DEFAULT_DIRECTION;
        }

        if ($sort === 'status' || $sort === 'payment') {
            [$sql, $bindings] = $sort === 'status' ? $this->statusCase() : $this->paymentCase();
            $query->orderByRaw('('.$sql.') '.$direction, $bindings);
        } else {
            $query->orderByRaw(self::SORT_EXPRESSIONS[$sort].' '.$direction);
        }

        return $query->orderBy('student_admissions.id', 'desc');
    }

    /**
     * `ReportStudentStatus`'s mapping as one SQL CASE, first match wins. Reads the `st` and `en`
     * aliases `base()` joins.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function statusCase(): array
    {
        return [
            'CASE'
            .' WHEN student_admissions.stage IN (?, ?) OR en.status IN (?, ?) OR st.status = ? THEN ?'
            .' WHEN student_admissions.stage = ? OR en.status = ? THEN ?'
            .' WHEN st.status = ? OR en.status = ? THEN ?'
            .' WHEN student_admissions.stage = ? THEN ?'
            .' ELSE ? END',
            [
                AdmissionStage::Withdrawn->value, AdmissionStage::Cancelled->value,
                EnrollmentStatus::Dropped->value, EnrollmentStatus::Cancelled->value,
                StudentStatus::Dropped->value,
                ReportStudentStatus::Dropped->value,
                AdmissionStage::Completed->value, EnrollmentStatus::Completed->value, ReportStudentStatus::Completed->value,
                StudentStatus::Suspended->value, EnrollmentStatus::Suspended->value, ReportStudentStatus::Inactive->value,
                AdmissionStage::Active->value, ReportStudentStatus::Active->value,
                ReportStudentStatus::Pending->value,
            ],
        ];
    }

    /**
     * `ReportPaymentStatus`'s mapping over the `fr` roll-up and the admission's own fee caches.
     *
     * Overdue comes from the charges — a live charge the nightly sweep marked overdue that still owes
     * something (`fr.overdue_amount` is Σ exactly those balances, so it is positive exactly when one
     * exists). The rest is read from the money: no live charges is Unpaid; nothing left to pay is
     * Paid; something received is Partially Paid; nothing received is Unpaid. The caches are read
     * only inside the CASE, so a viewer without money learns the bucket, never a figure.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function paymentCase(): array
    {
        return [
            'CASE'
            .' WHEN COALESCE(fr.overdue_amount, 0) > 0 THEN ?'
            .' WHEN COALESCE(fr.n_charges, 0) = 0 THEN ?'
            .' WHEN student_admissions.balance_amount <= 0 THEN ?'
            .' WHEN (student_admissions.paid_amount - student_admissions.refunded_amount) > 0 THEN ?'
            .' ELSE ? END',
            [
                ReportPaymentStatus::Overdue->value,
                ReportPaymentStatus::Unpaid->value,
                ReportPaymentStatus::Paid->value,
                ReportPaymentStatus::Partial->value,
                ReportPaymentStatus::Unpaid->value,
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Presenting
    |--------------------------------------------------------------------------
    */

    private function present(StudentAdmission $row, bool $money): AdvancedReportRow
    {
        $batchId = $row->getAttribute('report_batch_id');

        return new AdvancedReportRow(
            admissionId: (int) $row->getKey(),
            admissionNumber: (string) $row->getAttribute('admission_number'),
            studentId: (int) $row->getAttribute('student_id'),
            studentCode: (string) $row->getAttribute('student_code'),
            studentName: (string) $row->getAttribute('student_name'),
            registrationNumber: $this->text($row->getAttribute('student_registration_number')),
            phone: $this->text($row->getAttribute('student_phone')),
            email: $this->text($row->getAttribute('student_email')),
            courseId: (int) $row->getAttribute('course_id'),
            courseName: (string) $row->getAttribute('course_name'),
            courseCode: $this->text($row->getAttribute('course_code')),
            courseDuration: $this->durationLabel($row->getAttribute('course_duration_value'), $row->getAttribute('course_duration_unit')),
            batchId: $batchId === null ? null : (int) $batchId,
            batchCode: $this->text($row->getAttribute('batch_code')),
            batchName: $this->text($row->getAttribute('batch_name')),
            joiningDate: (string) $this->dateString($row->getAttribute('admission_date')),
            completionDate: $this->dateString($row->getAttribute('completion_date')),
            completionIsExpected: (int) $row->getAttribute('completion_is_expected') === 1,
            progress: Money::round((string) $row->getAttribute('progress_percentage'), 2),
            stage: $row->getAttribute('stage'),
            status: ReportStudentStatus::from((string) $row->getAttribute('report_status')),
            payment: ReportPaymentStatus::from((string) $row->getAttribute('payment_status')),
            overdueCharges: (int) $row->getAttribute('overdue_charges'),
            moneyVisible: $money,
            totalFees: $money ? Money::of((string) $row->getAttribute('charged_amount')) : null,
            paid: $money ? Money::of((string) $row->getAttribute('net_paid')) : null,
            remaining: $money ? Money::of((string) $row->getAttribute('balance_amount')) : null,
            overdueAmount: $money ? Money::of((string) $row->getAttribute('overdue_amount')) : null,
        );
    }

    /**
     * @return list<string>
     */
    private function headers(bool $money): array
    {
        return array_values(array_filter(
            self::EXPORT_HEADERS,
            static fn (string $header): bool => $money || ! in_array($header, self::MONEY_HEADERS, true),
        ));
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string>
     */
    private function exportRow(AdvancedReportRow $row, array $headers, bool $formatMoney): array
    {
        $values = [
            'Student ID' => $row->studentCode,
            'Student Name' => $row->studentName,
            'Admission No' => $row->admissionNumber,
            'Phone' => (string) $row->phone,
            'Email' => (string) $row->email,
            'Course' => $row->courseName,
            'Batch' => (string) $row->batchLabel(),
            'Joining Date' => Format::date($row->joiningDate),
            'Course Duration' => (string) $row->courseDuration,
            'Course Completion Date' => $row->completionDate === null
                ? ''
                : Format::date($row->completionDate).($row->completionIsExpected ? ' (expected)' : ''),
            'Progress' => Format::percentage($row->progress, 2),
            'Payment Status' => $row->payment->label(),
            'Student Status' => $row->status->label(),
        ];

        if ($row->moneyVisible) {
            $total = (string) $row->totalFees;
            $paid = (string) $row->paid;
            $remaining = (string) $row->remaining;

            $values['Total Fees'] = $formatMoney ? Money::format($total) : $total;
            $values['Paid Amount'] = $formatMoney ? Money::format($paid) : $paid;
            $values['Remaining Amount'] = match (true) {
                ! $formatMoney => $remaining,
                Money::isNegative($remaining) => Money::format(Money::abs($remaining)).' in advance',
                default => Money::format($remaining),
            };
        }

        // Ordered by the header list, so the headers and the cells can never disagree about a column.
        $ordered = [];

        foreach ($headers as $header) {
            $ordered[$header] = $values[$header];
        }

        return $ordered;
    }

    /**
     * The batch card: dates, the schedule actually in force today, the instructor, and this student's
     * seat on it.
     *
     * The schedule is the batch's active timetable slots effective today, ordered by the configured
     * week (`Weekday::ordered()`) then start time — and when there are none (a batch not started yet,
     * or one run without a timetable), the batch's own weekly shorthand, so the card never claims a
     * batch meets on no days at all.
     *
     * @return array<string, mixed>
     */
    private function batchInfo(Batch $batch, ?StudentBatchEnrollment $enrollment): array
    {
        $today = Carbon::now(Format::timezone())->startOfDay();
        $position = array_flip(array_map(static fn (Weekday $day): string => $day->value, Weekday::ordered()));
        $batchTeacher = $batch->teacher?->name;

        $entries = TimetableEntry::query()
            ->where('batch_id', $batch->getKey())
            ->active()
            ->effectiveBetween($today, $today)
            ->with('teacher:id,name')
            ->get(['id', 'batch_id', 'teacher_id', 'day_of_week', 'start_time', 'end_time'])
            ->all();

        usort($entries, static fn (TimetableEntry $a, TimetableEntry $b): int => [$position[$a->day_of_week->value] ?? 7, (string) $a->start_time]
            <=> [$position[$b->day_of_week->value] ?? 7, (string) $b->start_time]);

        $schedule = [];

        foreach ($entries as $entry) {
            $schedule[] = [
                'day' => $entry->day_of_week,
                'label' => $entry->slotLabel(),
                'teacher' => $entry->teacher?->name ?? $batchTeacher,
            ];
        }

        $source = $schedule === [] ? 'none' : 'timetable';

        if ($schedule === []) {
            $days = $batch->weekdays();
            $hours = $batch->start_time !== null && $batch->end_time !== null
                ? ' '.Format::clock($batch->start_time, 'H:i').'–'.Format::clock($batch->end_time, 'H:i')
                : '';

            foreach (Weekday::ordered() as $day) {
                if (in_array($day, $days, true)) {
                    $schedule[] = ['day' => $day, 'label' => $day->short().$hours, 'teacher' => $batchTeacher];
                }
            }

            $source = $schedule === [] ? 'none' : 'batch';
        }

        $instructors = array_values(array_unique(array_filter(array_column($schedule, 'teacher'))));

        return [
            'id' => (int) $batch->getKey(),
            'code' => (string) $batch->code,
            'name' => (string) $batch->name,
            'label' => $batch->label(),
            'start_date' => $this->dateString($batch->start_date),
            'end_date' => $this->dateString($batch->end_date),
            'schedule' => $schedule,
            'schedule_source' => $source,
            'instructor' => $batchTeacher ?? ($instructors === [] ? null : implode(', ', $instructors)),
            'enrollment_status' => $enrollment?->status,
            'roll_number' => $enrollment === null ? null : $this->text($enrollment->roll_number),
            'enrolled_on' => $this->dateString($enrollment?->enrolled_on),
        ];
    }

    /**
     * The admission's payment history with a running balance (map-4 §5).
     *
     * Opening balance: the final fee — Σ net of the admission's live charges, `summaryFor()`'s `net`.
     * Then, in date order: every receipt on those charges (− amount, on `paid_on`), and every reversal
     * of those receipts that was not rejected (+ amount, on `occurred_on`), ties broken by
     * `recorded_at` then id, a receipt always before its own reversal.
     *
     * Why this ends exactly at the cached balance: a voided receipt and its reversals net to zero, as
     * the cache — which leaves voided receipts out — does; a bounced one stays in the cache with paid =
     * refunded, which nets to zero too; a rejected reversal was already taken back out of
     * `refunded_amount`, so it is left out here; a reversal awaiting approval already counts in the
     * cache and so counts here, marked as waiting.
     *
     * @return array{opening: string, rows: list<array<string, mixed>>, closing: string, expected_closing: string, reconciles: bool}
     */
    private function paymentHistory(StudentAdmission $admission, FeeSummary $summary): array
    {
        // `liveChargesFor()`'s rule — not cancelled, not trashed — for the ids only; the figures are
        // the service's own (`$summary`).
        $chargeIds = StudentFee::query()
            ->where('student_admission_id', $admission->getKey())
            ->whereNot('status', StudentFeeStatus::Cancelled->value)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $events = [];

        if ($chargeIds !== []) {
            $receipts = StudentFeePayment::query()
                ->whereIn('student_fee_id', $chargeIds)
                ->get(['id', 'receipt_no', 'student_fee_id', 'amount', 'payment_method', 'reference_no', 'paid_on', 'recorded_at', 'status', 'notes']);

            foreach ($receipts as $receipt) {
                $amount = Money::of((string) $receipt->amount);

                $events[] = [
                    'key' => [$this->dateString($receipt->paid_on) ?? '', $this->dateTimeString($receipt->recorded_at), 0, (int) $receipt->getKey()],
                    'credit' => true,
                    'row' => [
                        'date' => $this->dateString($receipt->paid_on),
                        'kind' => 'payment',
                        'label' => 'Payment',
                        'amount' => Money::negate($amount),
                        'magnitude' => $amount,
                        'method' => $receipt->payment_method?->label(),
                        'receipt_no' => (string) $receipt->receipt_no,
                        'reference_no' => $this->text($receipt->reference_no),
                        'reference' => implode(' · ', array_filter([(string) $receipt->receipt_no, $this->text($receipt->reference_no)])),
                        'status' => $receipt->status?->label(),
                        'status_color' => $receipt->status?->color(),
                        'notes' => $this->text($receipt->notes),
                    ],
                ];
            }

            $receiptIds = $receipts->modelKeys();

            if ($receiptIds !== []) {
                $reversals = PaymentReversal::query()
                    ->whereIn('student_fee_payment_id', $receiptIds)
                    ->where('approval_status', '<>', ReversalApprovalStatus::Rejected->value)
                    ->get(['id', 'reversal_no', 'student_fee_payment_id', 'type', 'amount', 'reason', 'refund_method', 'reference_no', 'occurred_on', 'recorded_at', 'approval_status', 'notes']);

                foreach ($reversals as $reversal) {
                    $amount = Money::of((string) $reversal->amount);
                    $pending = $reversal->approval_status === ReversalApprovalStatus::Pending;

                    $events[] = [
                        'key' => [$this->dateString($reversal->occurred_on) ?? '', $this->dateTimeString($reversal->recorded_at), 1, (int) $reversal->getKey()],
                        'credit' => false,
                        'row' => [
                            'date' => $this->dateString($reversal->occurred_on),
                            'kind' => 'reversal',
                            'label' => $reversal->type?->label() ?? 'Reversal',
                            'amount' => $amount,
                            'magnitude' => $amount,
                            'method' => $reversal->refund_method?->label(),
                            'receipt_no' => (string) $reversal->reversal_no,
                            'reference_no' => $this->text($reversal->reference_no),
                            'reference' => implode(' · ', array_filter([(string) $reversal->reversal_no, $this->text($reversal->reference_no)])),
                            'status' => $pending ? $reversal->approval_status->label() : null,
                            'status_color' => $pending ? $reversal->approval_status->color() : null,
                            'notes' => $this->text($reversal->reason) ?? $this->text($reversal->notes),
                        ],
                    ];
                }
            }
        }

        usort($events, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        $balance = $summary->net;
        $rows = [];

        foreach ($events as $event) {
            $balance = $event['credit']
                ? Money::sub($balance, $event['row']['magnitude'])
                : Money::add($balance, $event['row']['magnitude']);

            $rows[] = $event['row'] + ['balance' => $balance];
        }

        $reconciles = Money::equals($balance, $summary->balance);

        if (! $reconciles) {
            Log::warning('Advanced Reports: an admission\'s payment history does not end at its cached balance.', [
                'student_admission_id' => (int) $admission->getKey(),
                'opening' => $summary->net,
                'history_closing' => $balance,
                'cached_balance' => $summary->balance,
            ]);
        }

        return [
            'opening' => $summary->net,
            'rows' => $rows,
            'closing' => $balance,
            'expected_closing' => $summary->balance,
            'reconciles' => $reconciles,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Small readers
    |--------------------------------------------------------------------------
    */

    private function branchOf(User $viewer): ?int
    {
        return $viewer->branch_id === null ? null : (int) $viewer->branch_id;
    }

    /** LIKE's three metacharacters, escaped with MariaDB's default escape character. */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    /**
     * `Course::durationLabel()` for a joined (value, unit) pair — the model's own wording, asked of a
     * throwaway instance rather than re-implemented, so "12 weeks" reads the same here as on the course.
     */
    private function durationLabel(mixed $value, mixed $unit): ?string
    {
        if (! is_numeric($value) || ! is_string($unit) || DurationUnit::tryFrom($unit) === null) {
            return null;
        }

        $key = (int) $value.'|'.$unit;

        return $this->durations[$key] ??= (new Course)
            ->forceFill(['duration_value' => (int) $value, 'duration_unit' => $unit])
            ->durationLabel();
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return substr(trim($value), 0, 10);
    }

    private function dateTimeString(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
