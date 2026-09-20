<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What the engine did with a receipt (`student_fee_payments.commission_state`,
 * `project_payments.commission_state`, finance spine §3).
 *
 * **Every receipt carries one**, including the ones that earned nothing. A payment that produced no
 * commission and says `skipped` with a reason is answerable; one that simply has no ledger row is a
 * question nobody can settle — and "why did this not pay" is the single most common thing a partner
 * asks.
 */
enum CommissionProcessingState: string
{
    use HasOptions;

    case Queued = 'queued';
    case Processed = 'processed';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Processed => 'Commission created',
            self::Skipped => 'Skipped',
            self::Failed => 'Failed',
            self::NotApplicable => 'Not applicable',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Processed => 'emerald',
            self::Queued => 'amber',
            self::Skipped => 'slate',
            self::Failed => 'rose',
            self::NotApplicable => 'slate',
        };
    }

    /**
     * Should the sweeper pick this receipt up again?
     *
     * `failed` and a receipt still `queued` long after the fact are the two the sweeper retries;
     * `skipped` is a decision, not a failure, and re-running it would produce the same decision.
     */
    public function isRetryable(): bool
    {
        return in_array($this, [self::Queued, self::Failed], true);
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::Processed, self::Skipped, self::NotApplicable], true);
    }
}
