<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\DataObjects\Crm\ActivityData;
use App\Enums\LeadActivityType;
use App\Enums\LeadContactOutcome;
use App\Models\Crm\Lead;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Log a manual timeline entry on a lead — `admin.leads.activities.store`, `can:update,lead` (phase-05 §2.2, §6.1
 * `recordActivity()`, §8.3).
 *
 * Only the five manual types (`note`, `call`, `whatsapp`, `email`, `meeting`) — every other `LeadActivityType` is
 * written by the services alone and is refused here. A note needs a body; an outcome belongs to a contact attempt,
 * not to a note; `duration_minutes` is a call length. `occurred_at` may be back-dated ("a call logged an hour
 * later") but never set in the future.
 */
class StoreLeadActivityRequest extends CrmFormRequest
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
            'type' => ['required', 'string', Rule::in(self::manualTypes())],
            'subject' => ['nullable', 'string', 'max:150'],
            'body' => ['required_if:type,'.LeadActivityType::Note->value, 'nullable', 'string', 'max:10000'],
            'outcome' => ['prohibited_if:type,'.LeadActivityType::Note->value, 'nullable', 'string', Rule::enum(LeadContactOutcome::class)],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'occurred_at' => ['nullable', 'string', 'date', $this->notInTheFuture()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'Choose a note, call, WhatsApp, email or meeting.',
            'body.required_if' => 'Write the note.',
            'outcome.prohibited_if' => 'A note has no call outcome.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['type', 'subject', 'body', 'outcome', 'duration_minutes', 'occurred_at']);
    }

    public function toData(): ActivityData
    {
        return ActivityData::fromArray($this->validated());
    }

    /**
     * The activity types a person may log (`LeadActivityType::isSystem()` is false).
     *
     * @return list<string>
     */
    public static function manualTypes(): array
    {
        $types = [];

        foreach (LeadActivityType::cases() as $type) {
            if (! $type->isSystem()) {
                $types[] = $type->value;
            }
        }

        return $types;
    }

    private function notInTheFuture(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            try {
                $at = CarbonImmutable::parse(trim($value), Format::displayTimezone());
            } catch (Throwable) {
                $fail('Enter a valid date and time.');

                return;
            }

            // A minute of slack for the clock of the browser that filled the field.
            if ($at->greaterThan(CarbonImmutable::now()->addMinute())) {
                $fail('An activity cannot be logged in the future.');
            }
        };
    }
}
