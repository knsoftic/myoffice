<?php

declare(strict_types=1);

namespace App\DataObjects\Reporting;

use App\Enums\ReportPaymentStatus;
use App\Enums\ReportStudentStatus;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * What the Advanced Reports screen is being asked for (D177).
 *
 * A readonly filter set rather than a bag of request parameters, for the reason `ClientFilters` and
 * `ActivityLogFilters` are one: the table, the summary cards, the CSV, the workbook, the PDF and the
 * printed sheet all have to narrow **the same rows**, and an export that rebuilt its filters from a
 * query string of its own would drift from the screen that produced it. Every one of them is handed
 * this object and `AdvancedStudentReportService::query()` applies it.
 *
 * **The period is resolved once, here, into two calendar dates.** The presets are relative ("this
 * week"), so they are turned into dates in the business timezone (`Format::timezone()`), with the
 * week starting where `localization.week_start` says — the same rule `DateRange::week()` uses — and
 * compared against `student_admissions.admission_date`, a `date` column, as dates. `DateRange` itself
 * is not used: it has no "all time", and `DateRange::custom()` clamps a span to 366 days, which would
 * silently cut a two-year custom range down to one without the screen saying so.
 *
 * **`custom` is open-ended on either side.** A blank `from` means "since the beginning", a blank `to`
 * means "until now", and a range typed backwards is swapped rather than answered with nothing.
 *
 * Sorting is an allowlist of keys, never a column name: `sort` names a SQL expression only through
 * `AdvancedStudentReportService`, so nothing a caller types can reach the statement as text.
 */
final readonly class AdvancedReportFilters
{
    public const PERIOD_ALL = 'all';

    public const PERIOD_TODAY = 'today';

    public const PERIOD_YESTERDAY = 'yesterday';

    public const PERIOD_THIS_WEEK = 'this_week';

    public const PERIOD_THIS_MONTH = 'this_month';

    public const PERIOD_LAST_MONTH = 'last_month';

    public const PERIOD_THIS_YEAR = 'this_year';

    public const PERIOD_LAST_YEAR = 'last_year';

    public const PERIOD_CUSTOM = 'custom';

    /**
     * Every period the selector offers, value => label, in display order.
     *
     * @var array<string, string>
     */
    public const PERIODS = [
        self::PERIOD_ALL => 'All time',
        self::PERIOD_TODAY => 'Today',
        self::PERIOD_YESTERDAY => 'Yesterday',
        self::PERIOD_THIS_WEEK => 'This Week',
        self::PERIOD_THIS_MONTH => 'This Month',
        self::PERIOD_LAST_MONTH => 'Last Month',
        self::PERIOD_THIS_YEAR => 'This Year',
        self::PERIOD_LAST_YEAR => 'Last Year',
        self::PERIOD_CUSTOM => 'Custom Date Range',
    ];

    /**
     * Sort keys a caller may ask for, key => what a printed sheet calls it.
     *
     * @var array<string, string>
     */
    public const SORTS = [
        'student_code' => 'Student ID',
        'name' => 'Student name',
        'course' => 'Course',
        'batch' => 'Batch',
        'joining' => 'Joining date',
        'completion' => 'Course completion date',
        'status' => 'Student status',
        'payment' => 'Payment status',
        'total' => 'Total fees',
        'paid' => 'Paid amount',
        'remaining' => 'Remaining amount',
    ];

    /**
     * The sort keys that order by money. Honoured only for a viewer who may see the money — sorting
     * by a column you cannot see still tells you its order, which is most of what it says.
     *
     * @var list<string>
     */
    public const MONEY_SORTS = ['total', 'paid', 'remaining'];

    public const DEFAULT_SORT = 'joining';

    public const DEFAULT_DIRECTION = 'desc';

    /** The longest search term kept; the Form Request refuses a longer one before it gets here. */
    public const SEARCH_MAX = 100;

    /**
     * Build it through `fromArray()`, which resolves the period; the constructor takes `from` / `to`
     * as already-resolved `Y-m-d` dates.
     */
    public function __construct(
        public string $period = self::PERIOD_ALL,
        public ?string $from = null,
        public ?string $to = null,
        public ?int $courseId = null,
        public ?int $batchId = null,
        public ?ReportStudentStatus $status = null,
        public ?ReportPaymentStatus $payment = null,
        public ?string $search = null,
        public string $sort = self::DEFAULT_SORT,
        public string $direction = self::DEFAULT_DIRECTION,
        /** What `describe()` prints for the course — resolved by whoever checked the id exists. */
        public ?string $courseLabel = null,
        /** What `describe()` prints for the batch — "CODE — Name", like `Batch::label()`. */
        public ?string $batchLabel = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * Keys: `period`, `from`, `to`, `course_id`, `batch_id`, `status`, `payment`, `search`, `sort`,
     * `direction` — the query string `toQuery()` writes.
     *
     * Never throws: an unknown period is "all time", an unreadable date is no bound, an unknown status
     * is no status. The Form Request is where garbage becomes a 422; this is where a stored or
     * hand-built array becomes a filter set without a second opinion.
     *
     * `$now` pins "today" for the relative periods; it defaults to now in the business timezone.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?CarbonImmutable $now = null): self
    {
        $requested = self::str($data, 'period', 32);
        $from = self::date($data, 'from');
        $to = self::date($data, 'to');

        // A link that carries dates but no period (hand-built, or from an older screen) means the
        // dates; an explicit preset wins, so the date inputs the form always submits are harmless.
        $period = match (true) {
            $requested !== null && array_key_exists($requested, self::PERIODS) => $requested,
            $requested === null && ($from !== null || $to !== null) => self::PERIOD_CUSTOM,
            default => self::PERIOD_ALL,
        };

        [$from, $to] = self::resolve($period, $from, $to, $now ?? CarbonImmutable::now(Format::timezone()));

        $sort = self::str($data, 'sort', 32);
        $sort = $sort !== null && array_key_exists($sort, self::SORTS) ? $sort : self::DEFAULT_SORT;

        $direction = strtolower((string) self::str($data, 'direction', 4));
        $direction = in_array($direction, ['asc', 'desc'], true)
            ? $direction
            : ($sort === self::DEFAULT_SORT ? self::DEFAULT_DIRECTION : 'asc');

        $status = self::str($data, 'status', 32);
        $payment = self::str($data, 'payment', 32);

        return new self(
            period: $period,
            from: $from,
            to: $to,
            courseId: self::id($data, 'course_id'),
            batchId: self::id($data, 'batch_id'),
            status: $status === null ? null : ReportStudentStatus::tryFrom($status),
            payment: $payment === null ? null : ReportPaymentStatus::tryFrom($payment),
            search: self::str($data, 'search', self::SEARCH_MAX),
            sort: $sort,
            direction: $direction,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Narrowing a copy
    |--------------------------------------------------------------------------
    */

    /** The same filters on a course that was checked to exist (or none, when it did not). */
    public function withCourse(?int $courseId, ?string $label = null): self
    {
        return $this->with(['courseId' => $courseId, 'courseLabel' => $courseId === null ? null : $label]);
    }

    /** The same filters on a batch that was checked to exist and to fit the course (or none). */
    public function withBatch(?int $batchId, ?string $label = null): self
    {
        return $this->with(['batchId' => $batchId, 'batchLabel' => $batchId === null ? null : $label]);
    }

    /** The same filters, sorted differently. Anything off the allowlist means the default order. */
    public function withSort(string $sort, ?string $direction = null): self
    {
        if (! array_key_exists($sort, self::SORTS)) {
            $sort = self::DEFAULT_SORT;
            $direction = self::DEFAULT_DIRECTION;
        }

        $direction = in_array($direction, ['asc', 'desc'], true)
            ? $direction
            : ($sort === self::DEFAULT_SORT ? self::DEFAULT_DIRECTION : 'asc');

        return $this->with(['sort' => $sort, 'direction' => $direction]);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading it back
    |--------------------------------------------------------------------------
    */

    /** Does anything narrow the rows? Sorting does not — it reorders the same set. */
    public function isFiltered(): bool
    {
        return $this->from !== null
            || $this->to !== null
            || $this->courseId !== null
            || $this->batchId !== null
            || $this->status !== null
            || $this->payment !== null
            || $this->search !== null;
    }

    public function sortsByMoney(): bool
    {
        return in_array($this->sort, self::MONEY_SORTS, true);
    }

    /**
     * The filter set as query-string parameters — what the export, print, reset and pagination links
     * carry, so every one of them rebuilds exactly this object through `fromArray()`.
     *
     * A preset travels as its name, not its dates: "this month" printed tomorrow is still this month.
     * Defaults are left out so a plain screen keeps a plain URL.
     *
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        $custom = $this->period === self::PERIOD_CUSTOM;

        return array_filter([
            'period' => $this->period === self::PERIOD_ALL ? null : $this->period,
            'from' => $custom ? $this->from : null,
            'to' => $custom ? $this->to : null,
            'course_id' => $this->courseId,
            'batch_id' => $this->batchId,
            'status' => $this->status?->value,
            'payment' => $this->payment?->value,
            'search' => $this->search,
            'sort' => $this->sort === self::DEFAULT_SORT && $this->direction === self::DEFAULT_DIRECTION ? null : $this->sort,
            'direction' => $this->sort === self::DEFAULT_SORT && $this->direction === self::DEFAULT_DIRECTION ? null : $this->direction,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * The filters in words, label => value, for the head of a printed sheet, a PDF or an export file.
     *
     * The period is always stated — "All time" included — and labelled by what it filters (the
     * joining date), because a sheet with no period line is one nobody can reproduce, and the sort is
     * stated because two printouts of the same filters in a different order look like two different
     * reports.
     *
     * @return array<string, string>
     */
    public function describe(): array
    {
        $lines = ['Joining date' => $this->periodLabel()];

        if ($this->courseId !== null) {
            $lines['Course'] = $this->courseLabel ?? '#'.$this->courseId;
        }

        if ($this->batchId !== null) {
            $lines['Batch'] = $this->batchLabel ?? '#'.$this->batchId;
        }

        if ($this->status !== null) {
            $lines['Student status'] = $this->status->label();
        }

        if ($this->payment !== null) {
            $lines['Payment status'] = $this->payment->label();
        }

        if ($this->search !== null) {
            $lines['Search'] = '"'.$this->search.'"';
        }

        $lines['Sorted by'] = self::SORTS[$this->sort].($this->direction === 'asc' ? ' (ascending)' : ' (descending)');

        return $lines;
    }

    /**
     * 'All time', 'Today (05 Oct 2026)', 'This Month (01 Oct 2026 – 31 Oct 2026)',
     * '01 Jan 2026 – 31 Jan 2026', 'From 01 Jan 2026', 'Up to 31 Jan 2026' — and, for a custom range
     * with neither date picked, a label that says so rather than one that looks like a choice.
     */
    public function periodLabel(): string
    {
        if ($this->from === null && $this->to === null) {
            return $this->period === self::PERIOD_CUSTOM
                ? 'Custom Date Range — no dates chosen, so all time'
                : self::PERIODS[self::PERIOD_ALL];
        }

        if ($this->from === null) {
            return 'Up to '.Format::date($this->to);
        }

        if ($this->to === null) {
            return 'From '.Format::date($this->from);
        }

        $dates = $this->from === $this->to
            ? Format::date($this->from)
            : Format::date($this->from).' – '.Format::date($this->to);

        return $this->period === self::PERIOD_CUSTOM
            ? $dates
            : self::PERIODS[$this->period].' ('.$dates.')';
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The inclusive `Y-m-d` bounds a period stands for, either of which may be open (null).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function resolve(string $period, ?string $from, ?string $to, CarbonImmutable $now): array
    {
        $span = static fn (CarbonImmutable $start, CarbonImmutable $end): array => [$start->toDateString(), $end->toDateString()];

        switch ($period) {
            case self::PERIOD_TODAY:
                return $span($now, $now);

            case self::PERIOD_YESTERDAY:
                return $span($now->subDay(), $now->subDay());

            case self::PERIOD_THIS_WEEK:
                $start = $now->startOfWeek(Format::weekStartsOn());

                return $span($start, $start->addDays(6));

            case self::PERIOD_THIS_MONTH:
                return $span($now->startOfMonth(), $now->endOfMonth());

            case self::PERIOD_LAST_MONTH:
                $month = $now->startOfMonth()->subMonthNoOverflow();

                return $span($month->startOfMonth(), $month->endOfMonth());

            case self::PERIOD_THIS_YEAR:
                return $span($now->startOfYear(), $now->endOfYear());

            case self::PERIOD_LAST_YEAR:
                $year = $now->startOfYear()->subYearNoOverflow();

                return $span($year->startOfYear(), $year->endOfYear());

            case self::PERIOD_CUSTOM:
                // `Y-m-d` strings order the same way the dates do.
                if ($from !== null && $to !== null && strcmp($from, $to) > 0) {
                    [$from, $to] = [$to, $from];
                }

                return [$from, $to];

            default:
                return [null, null];
        }
    }

    /** A copy with some properties replaced — readonly objects are never edited, only re-made. */
    private function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /**
     * A strict `Y-m-d` calendar date, or null. '2026-02-31' is not a date and is not rolled into March.
     *
     * @param  array<string, mixed>  $data
     */
    private static function date(array $data, string $key): ?string
    {
        $value = self::str($data, $key, 10);

        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, Format::timezone());
        } catch (Throwable) {
            return null;
        }

        return $date !== null && $date->toDateString() === $value ? $value : null;
    }

    /**
     * A positive id, or null.
     *
     * @param  array<string, mixed>  $data
     */
    private static function id(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^\d{1,18}$/', trim($value)) !== 1) {
            return null;
        }

        $id = (int) trim($value);

        return $id > 0 ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function str(array $data, string $key, int $max): ?string
    {
        $value = $data[$key] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
