<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which side of the business a report is about (phase-19-23 §3.5, §99, [D-23-2]).
 *
 * **The labels are read from settings, not written here.** §99 organises its thirty-one reports into
 * "the software house", "the institute" and "the collaborators", and on a real installation those
 * are not generic words — they are *this company's* name and *this institute's* name. A hardcoded
 * "Software House" heading on a screen belonging to a firm with its own name reads as a placeholder
 * somebody forgot to fill in. This is phase-13's `FinanceContext` precedent applied to a heading.
 *
 * **`system` is deliberately not about a side of the business.** §106's activity log, §107's audit
 * trail and §108's search are about the application rather than about clients or students, so they
 * get their own group and their own label, which no setting can rename.
 *
 * The four groups are the report screen's top-level tabs, in this order: it is the order §99 lists
 * them in, and it puts the two revenue-bearing halves before the two that support them.
 */
enum ReportGroup: string
{
    use HasOptions;

    /** Clients, projects, invoices, the money the firm bills. */
    case SoftwareHouse = 'software_house';

    /** Students, batches, attendance, fees, results, certificates. */
    case Institute = 'institute';

    /** Referrals, commissions, wallets, payouts. */
    case Collaborator = 'collaborator';

    /** The application about itself: activity, audit, exports, search. */
    case System = 'system';

    /**
     * The heading a person reads.
     *
     * Falls back to a generic word when the setting is empty, because a blank tab is worse than a
     * generic one — an installation mid-setup should still be able to find its reports.
     */
    public function label(): string
    {
        return match ($this) {
            self::SoftwareHouse => $this->named('company.name', 'Software House'),
            self::Institute => $this->named('institute.name', 'Institute'),
            self::Collaborator => 'Collaborators',
            self::System => 'System',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SoftwareHouse => 'indigo',
            self::Institute => 'emerald',
            self::Collaborator => 'amber',
            self::System => 'slate',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::SoftwareHouse => 'briefcase',
            self::Institute => 'academic-cap',
            self::Collaborator => 'users',
            self::System => 'cog-6-tooth',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SoftwareHouse => 'Clients, projects, invoices and what the firm has billed.',
            self::Institute => 'Students, batches, attendance, fees, results and certificates.',
            self::Collaborator => 'Referrals, commissions, wallets and payouts.',
            self::System => 'What the application has recorded about itself.',
        };
    }

    /**
     * The order the tabs appear in, which is §99's own.
     */
    public function sort(): int
    {
        return match ($this) {
            self::SoftwareHouse => 10,
            self::Institute => 20,
            self::Collaborator => 30,
            self::System => 40,
        };
    }

    /**
     * A settings-driven name, trimmed, with a generic fallback.
     *
     * Reading a setting inside an enum is unusual and worth the sentence: this is the only member
     * whose correct value is installation-specific, and pushing it out to every call site would
     * mean every screen, every export header and every PDF footer had to remember.
     */
    private function named(string $key, string $fallback): string
    {
        $value = trim((string) setting($key, ''));

        return $value === '' ? $fallback : $value;
    }
}
