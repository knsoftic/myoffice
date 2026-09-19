<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\DataObjects\Crm\StatusChangeData;
use App\Enums\LeadFollowUpType;
use App\Enums\LeadStatus;
use App\Models\Crm\Lead;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Move a lead to another status (phase-05 §2.11, §6.1 `changeStatus()`, §6.5 `move()`, §8.2):
 *
 *   `admin.leads.status`      PATCH  `can:changeStatus,lead`
 *   `admin.leads.board.move`  PATCH  `can:leads.change_status` (+ throttle) — the Kanban drag and "Move to"
 *
 * `to_status` is a `LeadStatus`; `lost_reason` is mandatory when the target is `lost` (test 13); `reason` is
 * mandatory when reopening a `won` or `lost` lead. `expected_from_status` is the compare-and-swap value — the
 * board **must** send it, a stale one is a 409 from the service (test 14). An optional `follow_up` block satisfies
 * `crm.require_follow_up_on_contacted` in the same request (test 20).
 *
 * Whether the pair is legal is decided by the service against `LeadStatus::allowedTransitions()` (422 with the
 * allowed list, test 12) — never here, so the request and the service cannot disagree.
 */
final class ChangeLeadStatusRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        $lead = $this->boundModel('lead', Lead::class);

        if (! $lead instanceof Lead) {
            return false;
        }

        if ($this->isBoardMove()) {
            return $this->actorCan('leads.change_status') && $this->actorCan('changeStatus', $lead);
        }

        return $this->actorCan('changeStatus', $lead);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'to_status' => ['required', 'string', Rule::enum(LeadStatus::class)],
            'expected_from_status' => [$this->isBoardMove() ? 'required' : 'nullable', 'string', Rule::enum(LeadStatus::class)],
            'lost_reason' => ['required_if:to_status,'.LeadStatus::Lost->value, 'nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], $this->followUpRules('follow_up', false, LeadFollowUpType::values()));
    }

    /**
     * Reopening a `won` or `lost` lead needs a reason (§2.11). The current status is the bound row's; the service
     * re-reads it under a lock, so this is the friendly early answer, not the guarantee.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $lead = $this->boundModel('lead', Lead::class);
                $from = $lead?->status;
                $from = $from instanceof LeadStatus ? $from : LeadStatus::tryFrom((string) $from);

                if (! in_array($from, [LeadStatus::Won, LeadStatus::Lost], true)) {
                    return;
                }

                $to = LeadStatus::tryFrom((string) $this->input('to_status'));

                if ($to === null || $to === $from) {
                    return;
                }

                $reason = $this->input('reason');

                if (! is_string($reason) || trim($reason) === '') {
                    $validator->errors()->add('reason', 'Say why this lead is being reopened.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lost_reason.required_if' => 'Say why this lead was lost.',
            'expected_from_status.required' => 'The board sent no starting column. Reload the board and try again.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['to_status', 'expected_from_status', 'lost_reason', 'reason']);

        $followUp = $this->input('follow_up');

        if (is_array($followUp) && array_filter($followUp, static fn (mixed $value): bool => $value !== null && $value !== '') === []) {
            $this->merge(['follow_up' => null]);
        }
    }

    public function isBoardMove(): bool
    {
        return $this->route()?->getName() === 'admin.leads.board.move';
    }

    public function targetStatus(): LeadStatus
    {
        return LeadStatus::from((string) $this->validated('to_status'));
    }

    public function expectedFrom(): ?LeadStatus
    {
        $value = $this->validated('expected_from_status');

        return is_string($value) ? LeadStatus::tryFrom($value) : null;
    }

    public function toData(): StatusChangeData
    {
        return StatusChangeData::fromArray($this->validated());
    }
}
