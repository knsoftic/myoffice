<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Support\DateRange;
use App\Support\Format;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Query-string validation for every phase-05 staff list, board and export (phase-05 §8.1, §8.2, §8.5, §8.6,
 * §8.7, §8.9): `admin.leads.index`, `.board`, `.board.column`, `.export`, `.follow-ups.index`,
 * `.import.index`, `admin.clients.index`, `.export`, `admin.clients.documents.index`.
 *
 * Same reasoning as Phase 1's `ListFilterRequest` and Phase 4's `ContentListRequest`: a filter read straight off
 * the request turns `?search[]=x` into a TypeError and a 500. Every filter is declared here with its shape, so a
 * probe answers 422 and the controllers read typed values. Multi-select filters (`status`, `source`) accept a
 * single value or a flat list of values; a nested array fails `*.string` and is a 422.
 *
 * Enum-valued filters are shape-checked here and resolved with `tryFrom()` in the accessors — an unknown value
 * simply matches nothing. Authorization is the route's `can:` plus the controller's `authorize()`: one request
 * class cannot name one permission for nine screens.
 */
final class CrmListRequest extends FormRequest
{
    private const FILTERS = [
        'search', 'q', 'status', 'source', 'assignee', 'follow_up', 'range', 'from', 'to', 'budget_min', 'budget_max',
        'has_duplicate', 'converted', 'trashed', 'account_manager', 'country', 'portal', 'category', 'visible',
        'expiring', 'type', 'overdue', 'view', 'mode', 'date', 'month', 'week', 'service_id', 'columns', 'sort', 'direction', 'page',
    ];

    /** alias => canonical filter name. */
    private const ALIASES = [
        'created_from' => 'from',
        'created_to' => 'to',
        'created_preset' => 'range',
        'account_manager_id' => 'account_manager',
    ];

    /** The multi-select filters. */
    private const LIST_FILTERS = ['status', 'source', 'columns'];

    /** A snake_case enum value (`proposal_sent`, `walk_in`). */
    private const ENUM_VALUE = 'regex:/^[a-z][a-z0-9_]{0,31}$/';

    /** A non-negative decimal(15,2) amount. */
    private const MONEY = 'regex:/^\d{1,13}(\.\d{1,2})?$/';

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
            // `q` is the same free-text search under the name the lead filter DTO and its links use.
            'q' => ['nullable', 'string', 'max:255'],

            'status' => ['nullable', 'array', 'max:12'],
            'status.*' => ['required', 'string', 'max:32', self::ENUM_VALUE],
            'source' => ['nullable', 'array', 'max:12'],
            'source.*' => ['required', 'string', 'max:32', self::ENUM_VALUE],

            // A user id, `me`, `unassigned` or `all` (the follow-up worklist's "All" needs `leads.view_any`).
            'assignee' => ['nullable', 'string', 'max:20', 'regex:/^(me|unassigned|all|[1-9][0-9]{0,18})$/'],
            'follow_up' => ['nullable', 'string', Rule::in(['overdue', 'today', 'week', 'none'])],

            // The Phase 2 date-range selector (`?range=month` or `?range=custom&from=…&to=…`).
            'range' => ['nullable', 'string', 'max:16', 'regex:/^[a-z]+$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],

            'budget_min' => ['nullable', 'string', 'max:16', self::MONEY],
            'budget_max' => ['nullable', 'string', 'max:16', self::MONEY],

            'service_id' => ['nullable', 'integer', 'min:1'],

            'has_duplicate' => ['nullable', 'boolean'],
            'converted' => ['nullable', 'boolean'],
            'trashed' => ['nullable', 'boolean'],

            'account_manager' => ['nullable', 'string', 'max:20', 'regex:/^(none|[1-9][0-9]{0,18})$/'],
            'country' => ['nullable', 'string', 'max:64'],
            'portal' => ['nullable', 'string', Rule::in(['enabled', 'invited', 'off'])],

            'category' => ['nullable', 'string', 'max:32', self::ENUM_VALUE],
            'visible' => ['nullable', 'boolean'],
            'expiring' => ['nullable', 'string', Rule::in(['soon', 'past'])],

            'type' => ['nullable', 'string', 'max:32', self::ENUM_VALUE],
            'overdue' => ['nullable', 'boolean'],
            'view' => ['nullable', 'string', Rule::in(['list', 'calendar'])],
            'mode' => ['nullable', 'string', Rule::in(['month', 'week'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'week' => ['nullable', 'date_format:Y-m-d'],

            // The table-column choices an export streams (users.preferences); the exporter whitelists them.
            'columns' => ['nullable', 'array', 'max:50'],
            'columns.*' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],

            'sort' => ['nullable', 'string', 'max:32'],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * A list screen answers 422 rather than redirecting wherever the referrer points; a `fetch()` gets the JSON
     * error bag.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        abort(422, 'This link carries a filter this screen cannot read: '.$validator->errors()->first());
    }

    /**
     * A cleared search box or an empty select arrives as `''` — that is "no filter", not an invalid value. A
     * single value of a multi-select filter becomes a one-item list. Anything else is left exactly as it came so
     * the rules can reject it.
     */
    protected function prepareForValidation(): void
    {
        $clean = [];

        // The names the screens' links and the filter DTOs also use for the same filters.
        foreach (self::ALIASES as $alias => $key) {
            if (! $this->query->has($key) && $this->query->has($alias)) {
                $this->query->set($key, $this->query->get($alias));
            }
        }

        $followUp = $this->query->get('follow_up');

        if ($followUp === 'next_7_days') {
            $this->query->set('follow_up', 'week');
        }

        foreach (['converted', 'has_duplicate', 'trashed', 'visible', 'overdue'] as $flag) {
            $value = $this->query->get($flag);

            if (is_string($value) && in_array(strtolower(trim($value)), ['yes', 'no'], true)) {
                $this->query->set($flag, strtolower(trim($value)) === 'yes' ? '1' : '0');
            }
        }

        foreach (self::FILTERS as $key) {
            if (! $this->query->has($key) && ! $this->request->has($key)) {
                continue;
            }

            $value = $this->input($key);

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '' || $value === null) {
                $clean[$key] = null;

                continue;
            }

            if (in_array($key, self::LIST_FILTERS, true) && is_string($value)) {
                $value = [$value];
            }

            if (in_array($key, self::LIST_FILTERS, true) && is_array($value)) {
                $value = array_values(array_filter($value, static fn (mixed $item): bool => $item !== '' && $item !== null));
                $value = $value === [] ? null : $value;
            }

            $clean[$key] = $value;
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
        return $this->filterString('search') ?? $this->filterString('q');
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
        $values = $this->filterEnums($key, $enum);

        return $values[0] ?? null;
    }

    /**
     * The known cases of a multi-select enum filter; unknown values are dropped.
     *
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return list<TEnum>
     */
    public function filterEnums(string $key, string $enum): array
    {
        $raw = $this->validated($key);
        $raw = is_string($raw) ? [$raw] : (is_array($raw) ? $raw : []);
        $cases = [];

        foreach ($raw as $value) {
            $case = is_string($value) ? $enum::tryFrom($value) : null;

            if ($case !== null && ! in_array($case, $cases, true)) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /**
     * The flat string list of a multi-select filter (`columns`).
     *
     * @return list<string>
     */
    public function filterList(string $key): array
    {
        $raw = $this->validated($key);

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter($raw, 'is_string')));
    }

    /**
     * A decimal(15,2) bound as the string the request carried (never a float).
     */
    public function filterMoney(string $key): ?string
    {
        return $this->filterString($key);
    }

    /**
     * The `created` range filter, or null when neither a preset nor a date was supplied.
     */
    public function dateRange(): ?DateRange
    {
        $preset = $this->filterString('range');
        $from = $this->filterString('from');
        $to = $this->filterString('to');

        if ($preset === null && $from === null && $to === null) {
            return null;
        }

        return DateRange::make($preset, $from, $to);
    }

    /**
     * A `Y-m` month as the first day of that month in the display timezone (the follow-up calendar), or null.
     */
    public function month(): ?CarbonImmutable
    {
        $value = $this->filterString('month');

        if ($value === null) {
            return null;
        }

        try {
            $month = CarbonImmutable::createFromFormat('!Y-m', $value, Format::displayTimezone());
        } catch (Throwable) {
            return null;
        }

        return $month instanceof CarbonImmutable ? $month->startOfMonth() : null;
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

    public function page(): int
    {
        return $this->filterId('page') ?? 1;
    }

    /**
     * The filters that were actually applied, for the filter bar, `withQueryString()` and the service DTOs.
     *
     * @return array<string, mixed>
     */
    public function activeFilters(): array
    {
        $active = [];

        foreach (self::FILTERS as $key) {
            if (in_array($key, ['page', 'sort', 'direction', 'columns', 'q'], true)) {
                continue;
            }

            $value = $this->validated($key);

            if ($value !== null && $value !== '' && $value !== []) {
                $active[$key] = $value;
            }
        }

        $search = $this->searchTerm();

        if ($search !== null) {
            $active['search'] = $search;
        }

        return $active;
    }
}
