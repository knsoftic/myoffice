<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Http\Requests\Crm\Concerns\ValidatesLeadIds;
use App\Models\User;

/**
 * Assign many leads at once — `admin.leads.bulk.assign`, `can:leads.assign` (phase-05 §6.1 `bulkAssign()`, §8.1).
 *
 * `ids` is an explicit list capped at `crm.bulk_max_ids` (422 above it, before any lock — test 36); `assigned_to` is
 * present and nullable (empty unassigns) and must name an active user holding `leads.view`.
 */
final class BulkLeadAssignRequest extends CrmFormRequest
{
    use ValidatesLeadIds;

    public function authorize(): bool
    {
        return $this->actorCan('leads.assign');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge($this->idRules(), [
            'assigned_to' => [
                'present',
                'nullable',
                'integer',
                'min:1',
                $this->activeUserHolding('leads.view', 'Choose an active user who can work on leads.'),
            ],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->idMessages();
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['assigned_to', 'reason']);
    }

    public function assignee(): ?User
    {
        $id = $this->validated('assigned_to');

        return is_numeric($id) ? User::query()->find((int) $id) : null;
    }
}
