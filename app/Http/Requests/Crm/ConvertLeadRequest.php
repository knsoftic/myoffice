<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Contracts\Projects\ProjectCreator;
use App\DataObjects\Crm\ConvertLeadData;
use App\Enums\LeadDuplicateMatchType;
use App\Enums\LeadStatus;
use App\Http\Requests\Crm\Concerns\ValidatesClientFields;
use App\Models\Crm\Lead;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Convert a won lead — `admin.leads.convert.store`, `can:convert,lead` (phase-05 §6.4 `convert()`, §8.3, tests 43-48).
 *
 * The `ConvertLeadData` keys:
 *
 *   · **either** `existing_client_id` (an explicitly chosen, undeleted client — a client is never auto-matched,
 *     §6.4 step 3) with an optional `matched_by`, **or** a `client` block with the §19 fields of the new client;
 *   · `promote_to_won` — "mark as won and convert" in one transaction (Q6), which needs `leads.change_status`;
 *   · `create_project` + a `project` block — the Phase 6 hand-off, which needs `projects.create` (test 47). While
 *     `ProjectCreator::isAvailable()` is false the controller answers 404 for it (D28);
 *   · `notes`.
 *
 * The wizard's own field names are accepted too: `client_mode` (`create` | `existing`) with `client_id`, and
 * `project[name|notes]`; `client.account_manager_id` is honoured only for someone holding `clients.assign`.
 *
 * `convert` itself is `leads.edit` **and** `clients.create` (the policy); a missing grant is a 403 before any rule.
 */
final class ConvertLeadRequest extends CrmFormRequest
{
    use ValidatesClientFields;

    public function authorize(): bool
    {
        $lead = $this->boundModel('lead', Lead::class);

        if (! $lead instanceof Lead || ! $this->actorCan('convert', $lead)) {
            return false;
        }

        if ($this->boolean('promote_to_won') && ! $this->actorCan('leads.change_status')) {
            return false;
        }

        return ! $this->boolean('create_project') || $this->actorCan('projects.create');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $clientRules = [];

        foreach ($this->clientFieldRules('client.') as $field => $fieldRules) {
            // The new-client block is required only when no existing client was chosen.
            $clientRules[$field] = array_map(
                static fn (mixed $rule): mixed => $rule === 'required' ? 'required_without:existing_client_id' : $rule,
                $fieldRules,
            );
        }

        return array_merge([
            'existing_client_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('clients', 'id')->whereNull('deleted_at'),
            ],
            'matched_by' => ['nullable', 'string', Rule::enum(LeadDuplicateMatchType::class)],
            'client_mode' => ['nullable', 'string', Rule::in(['create', 'existing'])],
            'client.account_manager_id' => [
                Rule::prohibitedIf(fn (): bool => ! $this->actorCan('clients.assign')),
                'nullable',
                'integer',
                'min:1',
                $this->activeUserHolding('clients.view', 'Choose an active user who can work with clients.'),
            ],
            'client' => ['required_without:existing_client_id', 'nullable', 'array'],
            'promote_to_won' => ['nullable', 'boolean'],
            'promotion_reason' => ['nullable', 'string', 'max:500'],
            'create_project' => ['nullable', 'boolean'],
            'project' => ['required_if_accepted:create_project', 'nullable', 'array:project_name,name,project_description,service_id,project_value,start_date,deadline,project_notes,notes'],
            'project.project_name' => ['required_if_accepted:create_project', 'nullable', 'string', 'max:150'],
            'project.name' => ['nullable', 'string', 'max:150'],
            'project.notes' => ['nullable', 'string', 'max:255'],
            'project.project_description' => ['nullable', 'string', 'max:10000'],
            'project.service_id' => ['nullable', 'integer', 'min:1', Rule::exists('services', 'id')->whereNull('deleted_at')],
            'project.project_value' => $this->moneyRules(),
            'project.start_date' => ['nullable', 'date_format:Y-m-d'],
            'project.deadline' => ['nullable', 'date_format:Y-m-d'],
            'project.project_notes' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], $clientRules);
    }

    /**
     * A lead that is not `won` converts only with `promote_to_won` (§6.4 step 1, test 44); the service re-checks the
     * locked row.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $lead = $this->boundModel('lead', Lead::class);
                $status = $lead?->status;
                $status = $status instanceof LeadStatus ? $status : LeadStatus::tryFrom((string) $status);

                if ($status !== LeadStatus::Won && ! $this->boolean('promote_to_won')) {
                    $validator->errors()->add('promote_to_won', 'Only a won lead can be converted. Mark it as won and convert in one step.');
                }

                if ($this->filled('existing_client_id') && is_array($this->input('client')) && array_filter($this->input('client')) !== []) {
                    $validator->errors()->add('existing_client_id', 'Either link an existing client or create a new one, not both.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'existing_client_id' => 'existing client',
            'client.name' => 'client name',
            'client.client_type' => 'client type',
            'project.project_name' => 'project name',
            'project.project_value' => 'project value',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['existing_client_id', 'client_id', 'client_mode', 'matched_by', 'promotion_reason', 'notes']);

        // The wizard's names: `client_mode=existing` + `client_id` is a link; `client_mode=create` never links.
        $mode = $this->input('client_mode');

        if ($mode === 'existing' && ! $this->filled('existing_client_id') && $this->filled('client_id')) {
            $this->merge(['existing_client_id' => $this->input('client_id')]);
        }

        if ($mode === 'create') {
            $this->merge(['existing_client_id' => null]);
        }

        $project = $this->input('project');

        if (is_array($project)) {
            $project['project_name'] ??= $project['name'] ?? null;
            $project['project_notes'] ??= $project['notes'] ?? null;
            $this->merge(['project' => $project]);
        }

        foreach (['client', 'project'] as $block) {
            $value = $this->input($block);

            if (is_array($value)) {
                $clean = [];

                foreach ($value as $key => $item) {
                    $clean[$key] = is_string($item) ? (trim($item) === '' ? null : trim($item)) : $item;
                }

                $this->merge([$block => $clean]);
            }
        }

        if ($this->filled('existing_client_id')) {
            $client = $this->input('client');

            // The wizard keeps posting the (prefilled) new-client block when "existing" is chosen: with an explicit
            // mode it is discarded; without one, only an untouched block is.
            if ($mode === 'existing' || (is_array($client) && array_filter($client, static fn (mixed $item): bool => $item !== null && $item !== '') === [])) {
                $this->merge(['client' => null]);
            }
        }
    }

    public function wantsProject(): bool
    {
        return $this->boolean('create_project');
    }

    /**
     * The hand-off is requested but no `ProjectCreator` is bound: the capability is unavailable (D28).
     */
    public function projectHandOffUnavailable(): bool
    {
        return $this->wantsProject() && ! app(ProjectCreator::class)->isAvailable();
    }

    public function toData(): ConvertLeadData
    {
        return ConvertLeadData::fromArray($this->validated());
    }
}
