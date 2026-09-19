<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The employment type of a job opening and, from Phase 7, of an employee.
 *
 * **Semantic owner: phase-07 §3** (F-5.2, resolutions §2.2). This file ships early with Phase 4
 * (build-order §3 row E9) because `job_openings.employment_type` casts to it three phases before HR
 * exists. The seven cases and both methods are phase-07 §3's, verbatim. Phase 7 reuses the file
 * unchanged and is the only phase that may ever change the case list or the two methods; a second
 * declaration of this name is a merge conflict, never a style question.
 */
enum EmploymentType: string
{
    use HasOptions;

    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contract = 'contract';
    case Internship = 'internship';
    case Temporary = 'temporary';
    case Consultant = 'consultant';
    case Freelance = 'freelance';

    public function label(): string
    {
        return match ($this) {
            self::FullTime => 'Full-time',
            self::PartTime => 'Part-time',
            self::Contract => 'Contract',
            self::Internship => 'Internship',
            self::Temporary => 'Temporary',
            self::Consultant => 'Consultant',
            self::Freelance => 'Freelance',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FullTime => 'emerald',
            self::PartTime => 'teal',
            self::Contract => 'indigo',
            self::Internship => 'sky',
            self::Temporary => 'amber',
            self::Consultant => 'violet',
            self::Freelance => 'slate',
        };
    }

    /**
     * Paid through payroll: false for `consultant` and `freelance`.
     */
    public function isSalaried(): bool
    {
        return ! in_array($this, [self::Consultant, self::Freelance], true);
    }

    /**
     * Receives leave entitlements unless a policy says otherwise: false for `consultant` and `freelance`.
     */
    public function leaveEligibleByDefault(): bool
    {
        return ! in_array($this, [self::Consultant, self::Freelance], true);
    }
}
