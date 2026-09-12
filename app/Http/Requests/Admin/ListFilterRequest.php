<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ModuleGroup;
use App\Enums\PanelType;
use App\Enums\UserStatus;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query-string validation for the four RBAC list screens — `admin.users.index`,
 * `admin.roles.index`, `admin.modules.index` and `admin.permissions.index`.
 *
 * Why this exists at all: the filters used to be read straight off the request with
 * `$request->string('search')`, and `Stringable`'s constructor is typed `string`. A hostile
 * `?search[]=x` therefore raised a `TypeError` and answered **500** — with an Ignition stack trace
 * attached whenever `APP_DEBUG` is on — where a validation error belongs. Declaring the shape once
 * turns every such probe into a 422 and leaves the controllers reading typed values.
 *
 * One class covers all four screens, exactly as `LogFilterRequest` covers both log viewers: a filter
 * the current screen does not use is simply never asked for. Nothing here is written to the
 * database, and authorization stays where it is enforced — the `can:` middleware on the route plus
 * the `authorize()` call in the action — because one request class cannot name one permission.
 */
final class ListFilterRequest extends FormRequest
{
    /** Filters the list screens understand. */
    private const FILTERS = [
        'search', 'status', 'role', 'branch', 'panel', 'system',
        'group', 'state', 'ability', 'module',
        'sort', 'direction', 'page',
    ];

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

            'status' => ['nullable', 'string', Rule::enum(UserStatus::class)],
            'role' => ['nullable', 'integer', 'min:1'],
            'branch' => ['nullable', 'integer', 'min:1'],

            'panel' => ['nullable', 'string', Rule::enum(PanelType::class)],
            'system' => ['nullable', 'string', Rule::in(['system', 'custom'])],

            // Modules and permissions screens.
            'group' => ['nullable', 'string', Rule::enum(ModuleGroup::class)],
            'state' => ['nullable', 'string', Rule::in(['enabled', 'disabled', 'core'])],

            // Matched against `permissions.ability` / `permissions.module`, whose values include the
            // portal namespaces' plain-string abilities as well as the Ability cases — so the shape
            // is capped rather than enumerated. An unknown value simply matches no rows.
            'ability' => ['nullable', 'string', 'max:64'],
            'module' => ['nullable', 'string', 'max:64'],

            // The column itself is whitelisted by the caller through sortColumn(); the length cap
            // only keeps an absurd value out of the validator's own error messages.
            'sort' => ['nullable', 'string', 'max:32'],
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
            'role' => 'role filter',
            'branch' => 'branch filter',
            'system' => 'role type',
            'direction' => 'sort direction',
        ];
    }

    /**
     * A list screen answers 422 instead of redirecting.
     *
     * The framework default for a failed web request is "redirect back with the errors", which makes
     * no sense for a GET list: these filters only ever come from the screen's own filter bar and its
     * own links, so a value that fails the shape declared above is a malformed request, not a user
     * typing something wrong. Redirecting would also bounce a crafted link to wherever the referrer
     * happens to point. The status is what matters — it used to be 500, complete with a stack trace
     * whenever APP_DEBUG was on.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        abort(422, 'This link carries a filter this screen cannot read: '.$validator->errors()->first());
    }

    /**
     * A cleared search box or an empty select arrives as `''` — that is "no filter", not an invalid
     * enum. Non-strings are left exactly as they came so the rules above can reject them.
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

    /*
    |--------------------------------------------------------------------------
    | Typed accessors — keep the controllers thin
    |--------------------------------------------------------------------------
    */

    public function searchTerm(): ?string
    {
        return $this->filterString('search');
    }

    public function statusFilter(): ?UserStatus
    {
        $value = $this->filterString('status');

        return $value === null ? null : UserStatus::tryFrom($value);
    }

    public function panelFilter(): ?PanelType
    {
        $value = $this->filterString('panel');

        return $value === null ? null : PanelType::tryFrom($value);
    }

    /**
     * `system` / `custom` / null — the roles index's "is this a protected role?" filter.
     */
    public function systemFilter(): ?string
    {
        return $this->filterString('system');
    }

    /**
     * The modules and permissions screens both filter by `ModuleGroup`.
     */
    public function groupFilter(): ?ModuleGroup
    {
        $value = $this->filterString('group');

        return $value === null ? null : ModuleGroup::tryFrom($value);
    }

    /**
     * `enabled` / `disabled` / `core` / null — the modules switchboard's state filter.
     */
    public function stateFilter(): ?string
    {
        return $this->filterString('state');
    }

    /**
     * A positive foreign key filter, or null when it was not supplied.
     */
    public function idFilter(string $key): ?int
    {
        $value = $this->validated($key);

        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
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
     * A sort column the caller explicitly allows, never raw input.
     *
     * @param  array<int, string>  $allowed
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
}
