<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;
use App\Enums\LeadStatus;

/**
 * The extras of a lead status move (phase-05 §2.11, §6.1 `changeStatus()`, §6.5 `move()`).
 *
 *   · `expectedFrom` — the compare-and-swap value the board sends; a stale one is a 409.
 *   · `reason` — mandatory when reopening a `won` or `lost` lead.
 *   · `lostReason` — mandatory when moving to `lost`.
 *   · `followUp` — satisfies `crm.require_follow_up_on_contacted` in the same request.
 */
final readonly class StatusChangeData
{
    use ReadsInput;

    public function __construct(
        public ?LeadStatus $expectedFrom = null,
        public ?string $reason = null,
        public ?string $lostReason = null,
        public ?FollowUpData $followUp = null,
    ) {}

    /**
     * Keys: `expected_from_status`, `reason`, `lost_reason`, nested `follow_up`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            expectedFrom: self::enum($data, 'expected_from_status', LeadStatus::class),
            reason: self::str($data, 'reason', 500),
            lostReason: self::str($data, 'lost_reason', 255),
            followUp: FollowUpData::fromNested($data, 'follow_up'),
        );
    }
}
