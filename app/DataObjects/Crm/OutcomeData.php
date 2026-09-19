<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;
use App\Enums\LeadContactOutcome;
use InvalidArgumentException;

/**
 * Completing a follow-up (phase-05 §6.3 `complete()`): the mandatory outcome, an optional note and an optional
 * successor follow-up created in the same transaction.
 */
final readonly class OutcomeData
{
    use ReadsInput;

    public function __construct(
        public LeadContactOutcome $outcome,
        public ?string $note = null,
        public ?FollowUpData $next = null,
    ) {}

    /**
     * Keys: `outcome`, `outcome_note`, nested `next`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $outcome = self::enum($data, 'outcome', LeadContactOutcome::class);

        if (! $outcome instanceof LeadContactOutcome) {
            throw new InvalidArgumentException('Completing a follow-up needs an outcome.');
        }

        return new self(
            outcome: $outcome,
            note: self::str($data, 'outcome_note', 255) ?? self::str($data, 'note', 255),
            next: FollowUpData::fromNested($data, 'next'),
        );
    }
}
