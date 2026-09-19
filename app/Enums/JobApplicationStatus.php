<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The six-stage hiring pipeline (phase-04 §3, `job_applications.status`).
 *
 * `allowedNext()` is the binding transition map; any other move is a 422 from
 * `ChangeApplicationStatusRequest` and is refused again by `JobApplicationService::changeStatus()`.
 *
 *   new         → reviewing, shortlisted, rejected
 *   reviewing   → shortlisted, interview, rejected
 *   shortlisted → interview, selected, rejected
 *   interview   → selected, rejected, shortlisted (second round)
 *   selected    → rejected (offer declined / withdrawn)
 *   rejected    → reviewing (re-opened by an admin)
 */
enum JobApplicationStatus: string
{
    use HasOptions;

    case New = 'new';
    case Reviewing = 'reviewing';
    case Shortlisted = 'shortlisted';
    case Interview = 'interview';
    case Selected = 'selected';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Reviewing => 'Reviewing',
            self::Shortlisted => 'Shortlisted',
            self::Interview => 'Interview',
            self::Selected => 'Selected',
            self::Rejected => 'Rejected',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'sky',
            self::Reviewing => 'amber',
            self::Shortlisted => 'violet',
            self::Interview => 'indigo',
            self::Selected => 'emerald',
            self::Rejected => 'rose',
        };
    }

    /**
     * The stages a candidate may move to from this one.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::New => [self::Reviewing, self::Shortlisted, self::Rejected],
            self::Reviewing => [self::Shortlisted, self::Interview, self::Rejected],
            self::Shortlisted => [self::Interview, self::Selected, self::Rejected],
            self::Interview => [self::Selected, self::Rejected, self::Shortlisted],
            self::Selected => [self::Rejected],
            self::Rejected => [self::Reviewing],
        };
    }

    /**
     * Is `$to` in `allowedNext()`?
     */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /**
     * `selected` and `rejected` end the pipeline (either can still move per `allowedNext()`).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Selected, self::Rejected], true);
    }

    /**
     * Moving into this stage requires a reason (`rejection_reason`).
     */
    public function requiresReason(): bool
    {
        return $this === self::Rejected;
    }

    /**
     * Moving into this stage requires a future `interview_at` plus a mode.
     */
    public function requiresInterviewSlot(): bool
    {
        return $this === self::Interview;
    }
}
