<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reporting;

use App\DataObjects\Reporting\AdvancedReportFilters;
use App\Enums\ReportPaymentStatus;
use App\Enums\ReportStudentStatus;
use App\Models\User;
use App\Services\Reporting\AdvancedStudentReportService;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query-string validation for every Advanced Reports read: the screen, its exports and its print
 * sheet (D177). One class for all of them, so the table and the file it exports can never be built
 * from differently-read filters.
 *
 * Built on `ListFilterRequest`'s two lessons. A value of the wrong *shape* — `?status[]=x`, a date
 * that is not a date, a period nobody offers — is a malformed link, answered 422 rather than a 500
 * with a stack trace or a redirect to wherever the referrer points (`failedValidation()`). A cleared
 * input arriving as `''` is "no filter", not an invalid value (`prepareForValidation()`).
 *
 * **`sort` is deliberately not validated against the allowlist.** A stale or junk sort key is ignored
 * and the default order used — a bookmarked link from before a column was renamed still opens — and
 * since the key only ever *selects* a SQL expression from a fixed map in the service, nothing a caller
 * types can reach the statement (SEC-09).
 *
 * Authorization is the route's `can:` middleware, and only that: SEC-21(b) gives a user exactly the
 * route's one permission and expects no 403, so nothing here may demand a second one. The money
 * permission is *asked* — to drop a money sort the viewer may not use — never required.
 */
final class AdvancedReportRequest extends FormRequest
{
    /** The parameters these screens read. */
    private const FILTERS = [
        'period', 'from', 'to', 'course_id', 'batch_id', 'status', 'payment', 'search',
        'sort', 'direction', 'page',
    ];

    /** @var list<string> What the last `toData()` dropped as not filterable by this viewer. */
    private array $ignored = [];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'period' => ['nullable', 'string', Rule::in(array_keys(AdvancedReportFilters::PERIODS))],
            'from' => ['nullable', 'string', 'date_format:Y-m-d'],
            'to' => ['nullable', 'string', 'date_format:Y-m-d'],

            // Existence and the course/batch fit are checked in toData(), where a stale id is dropped
            // rather than refused: a link to a batch that has since been deleted should still open.
            'course_id' => ['nullable', 'integer', 'min:1'],
            'batch_id' => ['nullable', 'integer', 'min:1'],

            'status' => ['nullable', 'string', Rule::enum(ReportStudentStatus::class)],
            'payment' => ['nullable', 'string', Rule::enum(ReportPaymentStatus::class)],
            'search' => ['nullable', 'string', 'max:'.AdvancedReportFilters::SEARCH_MAX],

            // Allowlisted by AdvancedReportFilters, not here — see the class note.
            'sort' => ['nullable', 'string', 'max:64'],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],

            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'period' => 'period',
            'from' => 'from date',
            'to' => 'to date',
            'course_id' => 'course filter',
            'batch_id' => 'batch filter',
            'status' => 'student status',
            'payment' => 'payment status',
            'direction' => 'sort direction',
        ];
    }

    /**
     * The validated query string as the filter set every Advanced Reports read is built from.
     *
     * Three corrections happen here, because each is a stale link rather than a mistake:
     *
     *  - a course the viewer cannot filter by (missing, or neither in their branch nor on any row they
     *    can see) is dropped;
     *  - a batch the viewer cannot filter by is dropped, and so is a batch that does not belong to the
     *    chosen course — "Course A, Batch of course B" is an empty table that looks like a bug;
     *  - a money sort is dropped for a viewer without `advanced_reports.view_financial`, so the screen,
     *    the printed "Sorted by" line and the export links all say the order actually used.
     *
     * A course or batch is checked against the very queries the selects are built from
     * (`AdvancedStudentReportService::course()` / `batch()`), so an option the form offers is never
     * refused here, and one it cannot offer never quietly applies. The first two drops are recorded in
     * `ignoredFilters()`, for the screen to say so instead of silently listing every row.
     */
    public function toData(): AdvancedReportFilters
    {
        $filters = AdvancedReportFilters::fromArray($this->validated());
        $user = $this->user();
        $reports = app(AdvancedStudentReportService::class);
        $this->ignored = [];

        if ($filters->courseId !== null) {
            $course = $user instanceof User ? $reports->course($filters->courseId, $user) : null;

            if ($course === null) {
                $this->ignored[] = 'course';
            }

            $filters = $filters->withCourse($course === null ? null : (int) $course->getKey(), $course?->name);
        }

        if ($filters->batchId !== null) {
            $batch = $user instanceof User ? $reports->batch($filters->batchId, $user) : null;

            if ($batch === null) {
                $this->ignored[] = 'batch';
            }

            $fits = $batch !== null && ($filters->courseId === null || (int) $batch->course_id === $filters->courseId);

            $filters = $filters->withBatch($fits ? (int) $batch->getKey() : null, $fits ? $batch->label() : null);
        }

        if ($filters->sortsByMoney() && ! ($user instanceof User && $reports->canSeeMoney($user))) {
            $filters = $filters->withSort(AdvancedReportFilters::DEFAULT_SORT, AdvancedReportFilters::DEFAULT_DIRECTION);
        }

        return $filters;
    }

    /**
     * The filters `toData()` dropped because the viewer cannot filter by them — `'course'`, `'batch'`
     * — as opposed to the silent, expected drop of a batch that does not fit the chosen course.
     *
     * @return list<string>
     */
    public function ignoredFilters(): array
    {
        return $this->ignored;
    }

    /**
     * A report screen answers 422 instead of redirecting — `ListFilterRequest::failedValidation()`'s
     * reasoning exactly: these values only ever come from the screen's own filter form and its own
     * links, so one that fails the declared shape is a malformed request, and bouncing it to the
     * referrer would send a crafted link wherever the referrer happens to point.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        abort(422, 'This link carries a filter this report cannot read: '.$validator->errors()->first());
    }

    /**
     * A cleared input or an "All …" option arrives as `''` — that is "no filter", not an invalid enum.
     * Non-strings are left exactly as they came so the rules above can reject them.
     */
    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (self::FILTERS as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $value = $this->input($key);

            if (is_string($value)) {
                $value = trim($value);
            }

            $clean[$key] = ($value === '' || $value === null) ? null : $value;
        }

        if ($clean !== []) {
            $this->merge($clean);
        }
    }
}
