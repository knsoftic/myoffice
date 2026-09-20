<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What an attribution is about (`collaborator_referrals.subject_type`, finance spine §3).
 *
 * Four subjects, one of which is set per row — `chk_cr_one_subject` makes "exactly one" a database fact
 * rather than a service convention. A lead that becomes a client that commissions a project produces
 * **three** referral rows, not one that migrates: each is evidence about a different thing, and
 * collapsing them would lose which stage the partner actually brought in.
 */
enum ReferralSubject: string
{
    use HasOptions;

    case Student = 'student';
    case Project = 'project';
    case Client = 'client';
    case Lead = 'lead';

    public function label(): string
    {
        return match ($this) {
            self::Student => 'Student',
            self::Project => 'Project',
            self::Client => 'Client',
            self::Lead => 'Lead',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Student => 'violet',
            self::Project => 'sky',
            self::Client => 'emerald',
            self::Lead => 'amber',
        };
    }

    /**
     * The `collaborator_referrals` column that holds this subject's id.
     */
    public function column(): string
    {
        return $this->value.'_id';
    }

    /**
     * Which side of the business earns on this subject.
     */
    public function scope(): CommissionScope
    {
        return match ($this) {
            self::Student => CommissionScope::Student,
            self::Project, self::Client, self::Lead => CommissionScope::Project,
        };
    }
}
