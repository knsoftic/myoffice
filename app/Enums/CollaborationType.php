<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What kind of partner a collaborator is (phase-08-09 §3.1, requirement §33).
 *
 * §33's list verbatim, plus `other` — because a list of ten that a business cannot extend becomes a
 * list of ten and a free-text column nobody reports on.
 *
 * **The two predicates drive exactly one thing**: which commission rules are pre-filled when an
 * application is approved. They decide no permission, no visibility and no rate. A marketing partner who
 * happens to bring a project still earns on it; the pre-fill is a convenience, not a rule.
 */
enum CollaborationType: string
{
    use HasOptions;

    case Freelancer = 'freelancer';
    case Agency = 'agency';
    case ReferralPartner = 'referral_partner';
    case BusinessPartner = 'business_partner';
    case ExternalDeveloper = 'external_developer';
    case ExternalDesigner = 'external_designer';
    case MarketingPartner = 'marketing_partner';
    case Consultant = 'consultant';
    case Trainer = 'trainer';
    case SalesPartner = 'sales_partner';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Freelancer => 'Freelancer',
            self::Agency => 'Agency',
            self::ReferralPartner => 'Referral partner',
            self::BusinessPartner => 'Business partner',
            self::ExternalDeveloper => 'External developer',
            self::ExternalDesigner => 'External designer',
            self::MarketingPartner => 'Marketing partner',
            self::Consultant => 'Consultant',
            self::Trainer => 'Trainer',
            self::SalesPartner => 'Sales partner',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Freelancer, self::ExternalDeveloper, self::ExternalDesigner => 'sky',
            self::Agency, self::BusinessPartner => 'violet',
            self::ReferralPartner, self::MarketingPartner, self::SalesPartner => 'emerald',
            self::Consultant, self::Trainer => 'amber',
            self::Other => 'slate',
        };
    }

    /**
     * Would this kind of partner usually bring students? Pre-fills the student commission rule only.
     */
    public function bringsStudents(): bool
    {
        return in_array($this, [
            self::ReferralPartner,
            self::MarketingPartner,
            self::SalesPartner,
            self::Trainer,
            self::Consultant,
        ], true);
    }

    /**
     * Would this kind of partner usually bring projects? Pre-fills the project commission rule only.
     */
    public function bringsProjects(): bool
    {
        return in_array($this, [
            self::Freelancer,
            self::Agency,
            self::BusinessPartner,
            self::ExternalDeveloper,
            self::ExternalDesigner,
            self::ReferralPartner,
            self::MarketingPartner,
            self::SalesPartner,
            self::Consultant,
        ], true);
    }
}
