<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Whether a contact inquiry has been handed to its target module (phase-04 §3, §6.10,
 * `contact_inquiries.routing_status`).
 *
 * `pending` is a normal, non-failing state: before Phase 5 / Phase 15 register their targets every
 * service and course inquiry waits here, and `inquiries:route-pending` drains the backlog later.
 */
enum InquiryRoutingStatus: string
{
    use HasOptions;

    case NotApplicable = 'not_applicable';
    case Pending = 'pending';
    case Routed = 'routed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'Not applicable',
            self::Pending => 'Awaiting routing',
            self::Routed => 'Routed',
            self::Failed => 'Routing failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotApplicable => 'slate',
            self::Pending => 'amber',
            self::Routed => 'emerald',
            self::Failed => 'rose',
        };
    }

    /**
     * Picked up by `InquiryRouter::routePending()`: `pending` and `failed`.
     */
    public function needsRetry(): bool
    {
        return in_array($this, [self::Pending, self::Failed], true);
    }
}
