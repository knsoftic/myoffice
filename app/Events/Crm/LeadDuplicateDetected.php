<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\Lead;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A newly saved lead matches existing leads or clients (phase-05 §6.2, §10.1) — a warning, never a block (D29).
 *
 * Carries counts only: the match details are re-derived per viewer by `LeadDuplicateDetector::checkLead()`, so a
 * listener can never broadcast another rep's lead.
 */
final class LeadDuplicateDetected implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Lead $lead,
        public readonly int $matchCount,
        public readonly bool $hasExactMatch,
    ) {}
}
