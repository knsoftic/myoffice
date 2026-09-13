<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\LoginStatus;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Query-string validation for both log viewers (phase-01 §8: `activity-log.index`,
 * `login-history.index` and their CSV exports).
 *
 * Nothing here is written to the database — the log tables are append-only — but the filters
 * still go through a Form Request so no unvalidated value ever reaches a query, the sort
 * column is always whitelisted by the caller, and the date range is interpreted in the
 * viewer's own timezone before being compared against UTC timestamps.
 *
 * Authorization stays with the routes (`can:` middleware) and the explicit permission checks
 * in the controllers: the same request class serves two modules, so it cannot name one
 * permission here.
 */
final class LogFilterRequest extends FormRequest
{
    /** Page sizes the UI offers. */
    public const PER_PAGE_OPTIONS = [15, 25, 50, 100];

    public const DEFAULT_PER_PAGE = 25;

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
            'search' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'module' => ['nullable', 'string', 'max:64'],
            'event' => ['nullable', 'string', 'max:64'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(LoginStatus::values())],
            'ip' => ['nullable', 'string', 'max:45'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', 'string', 'max:32'],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in(self::perPageOptions())],
        ];
    }

    /**
     * The page sizes the select offers and the rules accept: PER_PAGE_OPTIONS plus the
     * `appearance.table_page_size` default (per_page(), already clamped to 10..100), ascending.
     *
     * Without the setting's own size in the list, the select could not show the size the page was
     * actually rendered at, and the first filter submit would post a different one and override it.
     *
     * @return list<int>
     */
    public static function perPageOptions(): array
    {
        $options = self::PER_PAGE_OPTIONS;

        if (function_exists('per_page')) {
            $options[] = per_page();
        }

        $options = array_values(array_unique($options));
        sort($options);

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'user_id' => 'user',
            'subject_type' => 'record type',
            'ip' => 'IP address',
            'from' => 'start date',
            'to' => 'end date',
            'per_page' => 'page size',
        ];
    }

    /**
     * An empty select or a cleared search box arrives as '' — treat it as "no filter" so the
     * rules above do not have to special-case blank strings.
     */
    protected function prepareForValidation(): void
    {
        $keys = ['search', 'user_id', 'module', 'event', 'subject_type', 'status', 'ip', 'from', 'to', 'sort', 'direction', 'per_page'];

        $clean = [];

        foreach ($keys as $key) {
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

    /*
    |--------------------------------------------------------------------------
    | Typed accessors — keep the controllers thin
    |--------------------------------------------------------------------------
    */

    public function searchTerm(): ?string
    {
        return $this->filterString('search');
    }

    public function userId(): ?int
    {
        $value = $this->validated('user_id');

        return $value === null ? null : (int) $value;
    }

    public function statusFilter(): ?LoginStatus
    {
        $value = $this->filterString('status');

        return $value === null ? null : LoginStatus::tryFrom($value);
    }

    /**
     * One validated string filter, or null when it was not supplied.
     */
    public function filterString(string $key): ?string
    {
        $value = $this->validated($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Start of the range, in UTC (the timezone the timestamps are stored in).
     */
    public function fromDate(): ?CarbonImmutable
    {
        return $this->boundary('from', false);
    }

    /**
     * End of the range, inclusive of the whole day, in UTC.
     */
    public function toDate(): ?CarbonImmutable
    {
        return $this->boundary('to', true);
    }

    /**
     * The timezone dates typed into the filter bar are meant in.
     */
    public function viewerTimezone(): string
    {
        $user = $this->user();

        if ($user instanceof User) {
            return $user->effectiveTimezone();
        }

        // The business display timezone, never the UTC storage timezone (D61).
        return Format::timezone();
    }

    /**
     * A sort column the caller explicitly allows, never raw input.
     *
     * @param  array<int, string>  $allowed
     */
    public function sortColumn(array $allowed, string $default): string
    {
        $sort = $this->filterString('sort');

        return $sort !== null && in_array($sort, $allowed, true) ? $sort : $default;
    }

    public function sortDirection(string $default = 'desc'): string
    {
        $direction = strtolower((string) $this->filterString('direction'));

        if (in_array($direction, ['asc', 'desc'], true)) {
            return $direction;
        }

        return $default === 'asc' ? 'asc' : 'desc';
    }

    public function perPage(int $default = self::DEFAULT_PER_PAGE): int
    {
        $value = $this->validated('per_page');

        if ($value === null) {
            return $default;
        }

        $value = (int) $value;

        return in_array($value, self::perPageOptions(), true) ? $value : $default;
    }

    /**
     * Is anything actually filtered? Drives the "no rows match" empty state copy.
     */
    public function hasActiveFilters(): bool
    {
        foreach (['search', 'user_id', 'module', 'event', 'subject_type', 'status', 'ip', 'from', 'to'] as $key) {
            if ($this->validated($key) !== null) {
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * A date typed in the viewer's timezone, snapped to the start or end of that day and
     * converted to the application timezone for comparison against stored timestamps.
     */
    private function boundary(string $key, bool $endOfDay): ?CarbonImmutable
    {
        $value = $this->filterString($key);

        if ($value === null) {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($value, $this->viewerTimezone());
        } catch (Throwable) {
            return null;
        }

        $date = $endOfDay ? $date->endOfDay() : $date->startOfDay();

        return $date->setTimezone((string) config('app.timezone', 'UTC'));
    }
}
