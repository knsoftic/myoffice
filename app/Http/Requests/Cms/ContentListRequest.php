<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Support\Format;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Query-string validation for every phase-04 admin list screen (phase-04 §8.1-§8.10) and their CSV
 * exports: taxonomies, services, portfolio, team, the two moderation queues, success stories, blog
 * posts (list, calendar), jobs, applications and inquiries.
 *
 * Same reasoning as Phase 3's `CmsListRequest`: a filter read straight off the request turns
 * `?search[]=x` into a TypeError and a 500. Every filter is declared once with its shape, so a probe is
 * a 422 and the controllers read typed values. Enum-valued filters are shape-checked here and resolved
 * with `tryFrom()` in the controller — an unknown value simply matches nothing.
 *
 * Authorization is the route's `can:` plus the controller's `authorize()`: one request class cannot
 * name one permission for fifteen screens.
 */
final class ContentListRequest extends FormRequest
{
    private const FILTERS = [
        'search', 'tab', 'stage', 'status', 'state', 'category', 'technology', 'featured', 'trashed', 'type',
        'rating', 'source', 'course', 'platform', 'date_from', 'date_to', 'department', 'visibility',
        'work_mode', 'employment_type', 'deadline', 'job', 'assigned', 'unassigned', 'routing', 'service',
        'spam', 'year', 'author', 'tag', 'has_image', 'month', 'range', 'from', 'to',
        'sort', 'direction', 'page',
    ];

    /** A snake_case enum value (`in_progress`, `public_form`). */
    private const ENUM_VALUE = 'regex:/^[a-z][a-z0-9_]{0,31}$/';

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
            'tab' => ['nullable', 'string', 'max:32', self::ENUM_VALUE],
            // The application pipeline's tab (`new`, `reviewing`, ...).
            'stage' => ['nullable', 'string', 'max:32', self::ENUM_VALUE],
            'status' => ['nullable', 'string', 'max:32', self::ENUM_VALUE],
            'state' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'category' => ['nullable', 'integer', 'min:1'],
            'technology' => ['nullable', 'integer', 'min:1'],
            'featured' => ['nullable', 'boolean'],
            'trashed' => ['nullable', 'boolean'],
            'type' => ['nullable', 'string', 'max:32', self::ENUM_VALUE],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'source' => ['nullable', 'string', 'max:32', self::ENUM_VALUE],
            'course' => ['nullable', 'string', 'max:150'],
            'platform' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'department' => ['nullable', 'string', 'max:100'],
            'visibility' => ['nullable', 'string', Rule::in(['public', 'hidden'])],
            'work_mode' => ['nullable', 'string', 'max:32', self::ENUM_VALUE],
            'employment_type' => ['nullable', 'string', 'max:32', self::ENUM_VALUE],
            'deadline' => ['nullable', 'string', Rule::in(['expired', 'week', 'month'])],
            'job' => ['nullable', 'integer', 'min:1'],
            'assigned' => ['nullable', 'integer', 'min:1'],
            'unassigned' => ['nullable', 'boolean'],
            'routing' => ['nullable', 'string', 'max:32', self::ENUM_VALUE],
            'service' => ['nullable', 'integer', 'min:1'],
            'spam' => ['nullable', 'boolean'],
            'year' => ['nullable', 'integer', 'between:1990,2100'],
            'author' => ['nullable', 'integer', 'min:1'],
            'tag' => ['nullable', 'integer', 'min:1'],
            'has_image' => ['nullable', 'boolean'],
            // The blog calendar month, `2026-10`.
            'month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            // The Phase 2 date-range selector (`?range=month` or `?range=custom&from=…&to=…`).
            'range' => ['nullable', 'string', 'max:16', 'regex:/^[a-z]+$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', 'string', 'max:32'],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * A list screen answers 422 rather than redirecting wherever the referrer points.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        abort(422, 'This link carries a filter this screen cannot read: '.$validator->errors()->first());
    }

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

    /*
    |--------------------------------------------------------------------------
    | Typed accessors
    |--------------------------------------------------------------------------
    */

    public function searchTerm(): ?string
    {
        return $this->filterString('search');
    }

    public function filterString(string $key): ?string
    {
        $value = $this->validated($key);

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * A tri-state boolean filter: true, false, or null when it was not supplied.
     */
    public function filterBool(string $key): ?bool
    {
        $value = $this->validated($key);

        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public function filterId(string $key): ?int
    {
        $value = $this->validated($key);

        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum|null
     */
    public function filterEnum(string $key, string $enum): ?BackedEnum
    {
        $value = $this->filterString($key);

        return $value === null ? null : $enum::tryFrom($value);
    }

    /**
     * A `Y-m-d` filter as the start (or end) of that day in the display timezone, converted to UTC
     * for the query (D61).
     */
    public function filterDate(string $key, bool $endOfDay = false): ?CarbonImmutable
    {
        $value = $this->filterString($key);

        if ($value === null) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $value, Format::displayTimezone());
        } catch (Throwable) {
            return null;
        }

        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        return ($endOfDay ? $date->endOfDay() : $date->startOfDay())->utc();
    }

    /**
     * The list's "from" day (`?from=` or `?date_from=`) as a UTC instant at the start of that display day.
     */
    public function fromDate(): ?CarbonImmutable
    {
        return $this->filterDate('from') ?? $this->filterDate('date_from');
    }

    /**
     * The list's "to" day (`?to=` or `?date_to=`) as a UTC instant at the end of that display day.
     */
    public function toDate(): ?CarbonImmutable
    {
        return $this->filterDate('to', true) ?? $this->filterDate('date_to', true);
    }

    /**
     * @param  list<string>  $allowed
     */
    public function sortColumn(array $allowed, string $default): string
    {
        $sort = $this->filterString('sort');

        return $sort !== null && in_array($sort, $allowed, true) ? $sort : $default;
    }

    public function sortDirection(string $default = 'asc'): string
    {
        $direction = mb_strtolower((string) $this->filterString('direction'));

        if (in_array($direction, ['asc', 'desc'], true)) {
            return $direction;
        }

        return $default === 'desc' ? 'desc' : 'asc';
    }

    /**
     * The filters that were actually applied, for the filter bar and `withQueryString()`.
     *
     * @return array<string, mixed>
     */
    public function activeFilters(): array
    {
        $active = [];

        foreach (self::FILTERS as $key) {
            if (in_array($key, ['page', 'sort', 'direction'], true)) {
                continue;
            }

            $value = $this->validated($key);

            if ($value !== null && $value !== '') {
                $active[$key] = $value;
            }
        }

        return $active;
    }
}
