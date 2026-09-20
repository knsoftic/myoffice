<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Models\Collaborator\CollaboratorReferralVisit;
use App\Support\Collaborator\ReferralDecision;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * The evidence behind one attribution (phase-10-12 §6.3, F-4.3).
 *
 * Phase 9 decides *who* referred somebody; this carries *why anyone should believe it* — the click that
 * started it, the page it landed on, the address and browser that asked, the date the partner earned the
 * credit, and whatever a member of staff typed. Without this parameter the click evidence Phase 9 spends
 * a whole table collecting could never reach the attribution row, and six weeks later the only answer to
 * "why did my code not win" would be somebody's memory.
 *
 * `referralDate` is the **business** date the referral takes effect from, and is deliberately separate
 * from `created_at`: a back-dated admission credits the partner who was named on the day, not the day
 * somebody got round to entering it.
 */
final readonly class ReferralContext
{
    public function __construct(
        public ?int $referralVisitId = null,
        public ?string $landingUrl = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?CarbonInterface $referralDate = null,
        public ?string $notes = null,
    ) {}

    /**
     * Nothing known. Named rather than `new ReferralContext()` so a call site that genuinely has no
     * evidence says so out loud — an import, a console command, a fixture.
     */
    public static function none(): self
    {
        return new self;
    }

    /**
     * The evidence Phase 9's resolver gathered, turned into the six columns the spine stores.
     */
    public static function fromDecision(ReferralDecision $decision, ?Request $request = null): self
    {
        $visit = $decision->visit;

        return new self(
            referralVisitId: $visit instanceof CollaboratorReferralVisit ? (int) $visit->getKey() : null,
            landingUrl: $visit?->landing_url ?? $request?->fullUrl(),
            ipAddress: $visit?->ip_address ?? $request?->ip(),
            userAgent: $visit?->user_agent ?? $request?->userAgent(),
            referralDate: $decision->effectiveFrom,
            notes: $decision->overrideReason,
        );
    }

    /**
     * The six columns, ready to merge into an insert. Nulls are kept: "we do not know the IP" is a fact
     * worth storing as NULL rather than as an empty string that later reads as an address of length 0.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return [
            'referral_visit_id' => $this->referralVisitId,
            'landing_url' => $this->landingUrl === null ? null : mb_substr($this->landingUrl, 0, 255),
            'ip_address' => $this->ipAddress === null ? null : mb_substr($this->ipAddress, 0, 45),
            'user_agent' => $this->userAgent === null ? null : mb_substr($this->userAgent, 0, 255),
            'notes' => $this->notes === null ? null : mb_substr($this->notes, 0, 255),
        ];
    }
}
