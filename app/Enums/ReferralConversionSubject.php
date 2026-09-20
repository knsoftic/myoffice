<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What a referral visit turned into (phase-08-09 §3.2, requirement §38).
 *
 * Deliberately **wider** than the spine's `ReferralSubject`, which has no inquiry cases: a visit that
 * produced a contact inquiry is a conversion worth reporting even though no commission can ever follow
 * from it. The two enums are additive — the spine's is untouched, and this one is never stored in a
 * spine column.
 *
 * {@see earnsCommission()} says which of the seven can ever become money, so the funnel report can put a
 * line between "turned into something" and "turned into something payable".
 */
enum ReferralConversionSubject: string
{
    use HasOptions;

    case Student = 'student';
    case StudentAdmission = 'student_admission';
    case Project = 'project';
    case Client = 'client';
    case Lead = 'lead';
    case CourseInquiry = 'course_inquiry';
    case ContactInquiry = 'contact_inquiry';

    public function label(): string
    {
        return match ($this) {
            self::Student => 'Student',
            self::StudentAdmission => 'Admission',
            self::Project => 'Project',
            self::Client => 'Client',
            self::Lead => 'Lead',
            self::CourseInquiry => 'Course inquiry',
            self::ContactInquiry => 'Contact inquiry',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Student, self::StudentAdmission => 'emerald',
            self::Project => 'sky',
            self::Client => 'violet',
            self::Lead => 'amber',
            self::CourseInquiry, self::ContactInquiry => 'slate',
        };
    }

    /**
     * Can a conversion of this kind ever produce commission?
     */
    public function earnsCommission(): bool
    {
        return in_array($this, [self::Student, self::StudentAdmission, self::Project], true);
    }

    /**
     * Is this one of the spine's four commissionable referral subjects?
     */
    public function isSpineSubject(): bool
    {
        return in_array($this, [self::Student, self::Project, self::Client, self::Lead], true);
    }
}
