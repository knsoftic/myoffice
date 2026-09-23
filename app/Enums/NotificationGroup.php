<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How the §97 preference screen is divided up (phase-19-23 §3.4, §6.19).
 *
 * **It mirrors `ModuleGroup` without redefining it, and the difference is the point.** A notification
 * group is not a module group: `hr` and `finance` events sit together under `finance` because the
 * person tuning their email preferences thinks "money", not "which module owns the table"; `website`
 * has no group at all because a CMS event reaches an administrator as a `system` notice; and
 * `support` exists here and not there, because tickets, meetings and messages are one concern to a
 * reader and three modules to the application.
 *
 * Six groups is a screen somebody can scan. A group per module would be seventeen accordions, which
 * is a preference screen nobody opens twice.
 *
 * `relatedModuleGroups()` is what maps back, in the one direction that is well defined — the reverse
 * is deliberately absent, because a `ModuleGroup` does not determine a notification group and a
 * method pretending otherwise would be used as though it did.
 */
enum NotificationGroup: string
{
    use HasOptions;

    /** Accounts, security, backups, the things that are about the application itself. */
    case System = 'system';

    /** Clients, projects, tasks, the CRM. */
    case SoftwareHouse = 'software_house';

    /** Courses, batches, students, teachers, everything the institute does. */
    case Institute = 'institute';

    /** Invoices, payments, payroll, expenses. */
    case Finance = 'finance';

    /** Referrals, commissions, payouts. */
    case Collaborator = 'collaborator';

    /** Tickets, meetings and messages. */
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::System => 'System',
            self::SoftwareHouse => 'Clients and projects',
            self::Institute => 'Institute',
            self::Finance => 'Money',
            self::Collaborator => 'Collaborators',
            self::Support => 'Tickets and messages',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::System => 'Your account, security and anything about the application itself.',
            self::SoftwareHouse => 'Leads, clients, projects and tasks.',
            self::Institute => 'Courses, batches, attendance, results and certificates.',
            self::Finance => 'Invoices, payments, fees, payroll and expenses.',
            self::Collaborator => 'Referrals, commissions and payouts.',
            self::Support => 'Support tickets, meetings and messages.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::System => 'cog-6-tooth',
            self::SoftwareHouse => 'briefcase',
            self::Institute => 'academic-cap',
            self::Finance => 'banknotes',
            self::Collaborator => 'user-group',
            self::Support => 'lifebuoy',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::System => 'slate',
            self::SoftwareHouse => 'sky',
            self::Institute => 'emerald',
            self::Finance => 'amber',
            self::Collaborator => 'indigo',
            self::Support => 'brand',
        };
    }

    /**
     * The module groups whose events land in this section of the preference screen.
     *
     * One-way on purpose: this says where a module's events are *shown*, and nothing reads it the
     * other way round. `ModuleGroup::Shared` maps to `Support` because tickets, meetings and
     * messages are the whole of it; `Website` maps to `System` because a CMS event reaches an
     * administrator as an administrative notice, not as marketing news.
     *
     * @return list<ModuleGroup>
     */
    public function relatedModuleGroups(): array
    {
        return match ($this) {
            self::System => [ModuleGroup::System, ModuleGroup::Website],
            self::SoftwareHouse => [ModuleGroup::SoftwareHouse],
            self::Institute => [ModuleGroup::Institute],
            self::Finance => [ModuleGroup::Finance, ModuleGroup::Hr],
            self::Collaborator => [ModuleGroup::Collaborator],
            self::Support => [ModuleGroup::Shared],
        };
    }
}
