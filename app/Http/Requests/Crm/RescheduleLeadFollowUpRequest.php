<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Crm\LeadFollowUp;
use App\Support\Format;
use Carbon\CarbonImmutable;

/**
 * Reschedule a follow-up — `admin.leads.follow-ups.reschedule`, `can:complete,followUp` (phase-05 §6.3
 * `reschedule(LeadFollowUp, CarbonInterface $to, string $reason)`).
 *
 * The new date is typed in the display timezone, may not be earlier than today there, and is handed to the service
 * in UTC (D61). The reason is mandatory.
 */
final class RescheduleLeadFollowUpRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        $followUp = $this->boundModel('followUp', LeadFollowUp::class);

        return $followUp instanceof LeadFollowUp
            && $this->actorCan('complete', $followUp)
            && $this->actorCan('reschedule', $followUp);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'scheduled_at' => ['required', 'string', 'date', $this->notBeforeTodayRule()],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the follow-up is moving.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['scheduled_at', 'reason']);
    }

    /**
     * The new date and time in UTC.
     */
    public function scheduledAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('scheduled_at'), Format::displayTimezone())->utc();
    }

    public function reasonText(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
