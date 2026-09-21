<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a submitted admission form stands (`student_applications.status`, §67, phase-14-17 §2.30.3).
 *
 * **Four of the six are terminal, and none of them is a delete.** A public submission is evidence
 * that somebody asked: rejecting it, marking it a duplicate or recording that the applicant walked
 * away all keep the row and the reason. A rejected applicant who comes back is a new application, not
 * an edited old one, because the second attempt is a different event with a different date.
 */
enum StudentApplicationStatus: string
{
    use HasOptions;

    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Converted = 'converted';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::Converted => 'Converted',
            self::Rejected => 'Rejected',
            self::Duplicate => 'Duplicate',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Submitted => 'sky',
            self::UnderReview => 'amber',
            self::Converted => 'emerald',
            self::Rejected => 'rose',
            self::Duplicate => 'orange',
            self::Withdrawn => 'slate',
        };
    }

    /** Still waiting for a human. The inbox count and the "pending application" alert both ask this. */
    public function isOpen(): bool
    {
        return $this === self::Submitted || $this === self::UnderReview;
    }

    public function isTerminal(): bool
    {
        return ! $this->isOpen();
    }
}
