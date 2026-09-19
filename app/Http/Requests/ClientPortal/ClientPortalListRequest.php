<?php

declare(strict_types=1);

namespace App\Http\Requests\ClientPortal;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query-string validation for every client-panel list (phase-05 §8.10): projects, tasks, milestones, files,
 * documents, invoices, payments, meetings, tickets, messages and notifications.
 *
 * Every filter is declared with its shape, so `?search[]=x` is a 422 and never a 500. The values are handed to the
 * owning section's `paginate(Client, array $filters)`, which applies only the filters it understands. **No filter
 * can widen the scope**: the client is resolved by `ClientContext` from the session, never from these values, and
 * a `project` filter only narrows within the client's own projects inside the section's own query.
 */
final class ClientPortalListRequest extends FormRequest
{
    private const FILTERS = [
        'search', 'status', 'priority', 'category', 'project', 'type', 'method', 'range', 'from', 'to', 'when',
        'unread', 'page',
    ];

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
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', 'max:32', self::ENUM_VALUE],
            'priority' => ['nullable', 'string', 'max:32', self::ENUM_VALUE],
            'category' => ['nullable', 'string', 'max:32', self::ENUM_VALUE],
            'project' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'string', 'max:32', self::ENUM_VALUE],
            'method' => ['nullable', 'string', 'max:32', self::ENUM_VALUE],
            'range' => ['nullable', 'string', 'max:16', 'regex:/^[a-z]+$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'when' => ['nullable', 'string', Rule::in(['upcoming', 'past'])],
            'unread' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

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
            if (! $this->query->has($key)) {
                continue;
            }

            $value = $this->query($key);

            if (is_string($value)) {
                $value = trim($value);
            }

            $clean[$key] = ($value === '' || $value === null) ? null : $value;
        }

        if ($clean !== []) {
            $this->merge($clean);
        }
    }

    /**
     * The applied filters, typed, for the section's `paginate()` and the filter bar.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = [];

        foreach (self::FILTERS as $key) {
            $value = $this->validated($key);

            if ($value === null || $value === '') {
                continue;
            }

            $filters[$key] = match ($key) {
                'project', 'page' => (int) $value,
                'unread' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => $value,
            };
        }

        return $filters;
    }
}
