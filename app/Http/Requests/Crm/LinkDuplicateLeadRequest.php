<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Crm\Lead;
use App\Models\User;
use Closure;

/**
 * Link a lead as a duplicate of another — `admin.leads.duplicate-link`, `can:update,lead` (phase-05 §6.1
 * `linkDuplicate()`, §8.4).
 *
 * `original_lead_id` must be a different lead that the actor can see — an id outside the actor's pipeline is
 * answered exactly like an id that does not exist, so ids cannot be probed. Self-links and cycles are refused by the
 * service, which walks the chain under its own rules. `note` is mandatory: it is written on both timelines.
 */
final class LinkDuplicateLeadRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        $lead = $this->boundModel('lead', Lead::class);

        return $lead instanceof Lead && $this->actorCan('update', $lead);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'original_lead_id' => ['required', 'integer', 'min:1', $this->visibleOtherLead()],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['original_lead_id', 'note']);
    }

    public function original(): Lead
    {
        $original = $this->visibleLead((int) $this->validated('original_lead_id'));

        abort_unless($original instanceof Lead, 404);

        return $original;
    }

    public function note(): string
    {
        return trim((string) $this->validated('note'));
    }

    private function visibleOtherLead(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $lead = $this->boundModel('lead', Lead::class);

            if (! is_numeric($value)) {
                return;
            }

            if ($lead instanceof Lead && (int) $value === (int) $lead->getKey()) {
                $fail('A lead cannot be a duplicate of itself.');

                return;
            }

            if (! $this->visibleLead((int) $value) instanceof Lead) {
                $fail('That lead was not found.');
            }
        };
    }

    /**
     * The lead by id through the model's visibility scope, re-checked against the policy.
     */
    private function visibleLead(int $id): ?Lead
    {
        $lead = Lead::query()->find($id);
        $actor = $this->actor();

        return $lead instanceof Lead && $actor instanceof User && $actor->can('view', $lead) ? $lead : null;
    }
}
