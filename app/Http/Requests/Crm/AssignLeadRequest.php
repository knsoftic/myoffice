<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Crm\Lead;
use App\Models\User;

/**
 * Assign or unassign one lead — `admin.leads.assign`, `can:assign,lead` (phase-05 §6.1 `assign()`).
 *
 * `assigned_to` must be present; empty unassigns. The assignee must be an active user holding `leads.view` on their own
 * grants, so a lead can never be handed to someone who cannot open it. `reason` is optional and lands in the
 * `assigned` activity row and the audit entry.
 */
final class AssignLeadRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        $lead = $this->boundModel('lead', Lead::class);

        return $lead instanceof Lead && $this->actorCan('assign', $lead);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'assigned_to' => [
                'present',
                'nullable',
                'integer',
                'min:1',
                $this->activeUserHolding('leads.view', 'Choose an active user who can work on leads.'),
            ],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['assigned_to', 'reason']);
    }

    /**
     * The new assignee, or null to unassign.
     */
    public function assignee(): ?User
    {
        $id = $this->validated('assigned_to');

        return is_numeric($id) ? User::query()->find((int) $id) : null;
    }
}
