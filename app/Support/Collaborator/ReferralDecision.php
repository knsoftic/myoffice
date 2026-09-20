<?php

declare(strict_types=1);

namespace App\Support\Collaborator;

use App\Enums\ReferralCandidateChannel;
use App\Enums\ReferralConversionSubject;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorReferralVisit;
use Illuminate\Support\Carbon;

/**
 * What the ladder decided, and everything needed to justify it later (phase-08-09 §6.3).
 *
 * **A decision with no winner is a real decision**, not a failure. Staff explicitly choosing "no
 * collaborator", or nobody being eligible at all, means **no referral row is created** — not a row with
 * a null collaborator, which `collaborator_referrals.collaborator_id` could not hold anyway, and not a
 * zero-commission row, which would tell the engine to pay nothing to somebody rather than to pay nobody.
 */
final readonly class ReferralDecision
{
    /**
     * @param  list<ReferralCandidate>  $losers  every lower rank that named a *different* partner
     */
    public function __construct(
        public ?Collaborator $winner,
        public ?ReferralCandidateChannel $channel,
        public ?string $source,
        public ?CollaboratorReferralVisit $visit,
        public array $losers,
        public bool $overrideReasonRequired,
        public ?string $rejectionReason,
        public Carbon $effectiveFrom,
        public string $attributionModel,
        public ?ReferralConversionSubject $subject = null,
        public ?int $subjectId = null,
        public ?string $overrideReason = null,
    ) {}

    public function hasWinner(): bool
    {
        return $this->winner !== null;
    }

    public function winnerId(): ?int
    {
        $id = $this->winner?->getKey();

        return $id === null ? null : (int) $id;
    }

    /**
     * `properties.referral_decision` for the `referral.decided` activity row (§6.3).
     *
     * Codes and channels only. No contact detail, no rate, no amount — an audit row about attribution
     * has no business carrying money, and a property that carried one would end up on a screen.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'winner' => $this->winner === null ? null : array_filter([
                'collaborator_id' => $this->winnerId(),
                'code' => $this->winner->referral_code,
                'channel' => $this->channel?->value,
                'source' => $this->source,
                'visit_id' => $this->visit?->getKey(),
            ], static fn (mixed $value): bool => $value !== null),
            'losers' => array_map(
                static fn (ReferralCandidate $candidate): array => $candidate->toArray(),
                $this->losers,
            ),
            'subject' => $this->subject === null ? null : [
                'type' => $this->subject->value,
                'id' => $this->subjectId,
            ],
            'effective_from' => $this->effectiveFrom->toDateString(),
            'override_reason' => $this->overrideReason,
            'rejection_reason' => $this->rejectionReason,
            'attribution_model' => $this->attributionModel,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
