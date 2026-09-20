<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Enums\Priority;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query string of a project list screen (phase-06 §8.1).
 *
 * Validated rather than read raw so a hand-edited URL cannot sort by an arbitrary column — the allowed
 * list lives in the controller and {@see sortColumn()} falls back to it, which keeps the ordering clause
 * out of reach of the request entirely.
 */
final class ProjectListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(ProjectStatus::values())],
            'priority' => ['nullable', 'string', Rule::in(Priority::values())],
            'type' => ['nullable', 'string', Rule::in(ProjectType::values())],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'overdue' => ['nullable', 'boolean'],
            'trashed' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'max:40'],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    public function sortColumn(array $allowed, string $fallback): string
    {
        $sort = (string) $this->query('sort', '');

        return in_array($sort, $allowed, true) ? $sort : $fallback;
    }

    public function sortDirection(string $fallback = 'desc'): string
    {
        $direction = strtolower((string) $this->query('direction', ''));

        return in_array($direction, ['asc', 'desc'], true) ? $direction : $fallback;
    }

    public function search(): ?string
    {
        $term = trim((string) $this->query('q', ''));

        return $term === '' ? null : $term;
    }
}
