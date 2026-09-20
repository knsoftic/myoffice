<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What a commission promise is attached to (`collaborator_commission_entitlements.document_type`,
 * finance spine §3).
 *
 * An entitlement is "this partner is owed up to X on this document". It exists because a base other
 * than `paid` promises money before the business has collected it: the promise is recorded once, and
 * each receipt releases a slice of it, so the total can never exceed what was agreed however many
 * payments arrive.
 */
enum EntitlementDocumentType: string
{
    use HasOptions;

    case StudentAdmission = 'student_admission';
    case StudentFee = 'student_fee';
    case Project = 'project';
    case ProjectMilestone = 'project_milestone';

    public function label(): string
    {
        return match ($this) {
            self::StudentAdmission => 'Admission',
            self::StudentFee => 'Fee charge',
            self::Project => 'Project',
            self::ProjectMilestone => 'Milestone',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::StudentAdmission, self::StudentFee => 'violet',
            self::Project, self::ProjectMilestone => 'sky',
        };
    }

    public function scope(): CommissionScope
    {
        return match ($this) {
            self::StudentAdmission, self::StudentFee => CommissionScope::Student,
            self::Project, self::ProjectMilestone => CommissionScope::Project,
        };
    }

    /**
     * The column on `collaborator_commission_entitlements` that holds the document's id.
     */
    public function column(): string
    {
        return match ($this) {
            self::StudentAdmission => 'student_admission_id',
            self::StudentFee => 'student_fee_id',
            self::Project => 'project_id',
            self::ProjectMilestone => 'project_milestone_id',
        };
    }
}
