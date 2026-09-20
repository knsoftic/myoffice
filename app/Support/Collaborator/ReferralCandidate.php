<?php

declare(strict_types=1);

namespace App\Support\Collaborator;

use App\Enums\ReferralCandidateChannel;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorReferralVisit;

/**
 * One answer to "who referred this person?", and where it came from (phase-08-09 §6.3).
 *
 * A candidate that loses is **not discarded**. The ladder produces one winner and keeps every lower rank
 * that named a *different* partner, because the interesting conversation months later is never "who won"
 * — it is "why did my code not win", and only a kept loser can answer it.
 */
final readonly class ReferralCandidate
{
    public function __construct(
        public ReferralCandidateChannel $channel,
        public ?Collaborator $collaborator,
        public ?string $code = null,
        public ?CollaboratorReferralVisit $visit = null,
        /** Why this candidate cannot win, when it cannot. Null means it is eligible. */
        public ?string $rejectedBecause = null,
    ) {}

    public function isEligible(): bool
    {
        return $this->collaborator !== null && $this->rejectedBecause === null;
    }

    public function collaboratorId(): ?int
    {
        $id = $this->collaborator?->getKey();

        return $id === null ? null : (int) $id;
    }

    /**
     * What goes into `properties.referral_decision` — the code and the channel, never a contact detail.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'collaborator_id' => $this->collaboratorId(),
            'code' => $this->code ?? $this->collaborator?->referral_code,
            'channel' => $this->channel->value,
            'visit_id' => $this->visit?->getKey(),
            'rejected_because' => $this->rejectedBecause,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
