<?php

declare(strict_types=1);

namespace App\Dashboard;

use Illuminate\Support\Str;

/**
 * The sections a dashboard widget can be filed under.
 *
 * **Deliberately a string registry and not a PHP enum**, which is the one place this project
 * steps outside CLAUDE.md §3's "statuses come from enums" rule — and for a reason: a widget group
 * is not a status, it is a layout heading, and twenty-three later phases have to be able to open
 * a new dashboard section by shipping one widget class. An enum would force each of them to edit
 * this shared file, which is exactly the coupling the widget framework exists to avoid.
 *
 * So: the groups below are the **known** ones, with a label and a display order. Any other slug a
 * widget returns is accepted and rendered with a humanised label at the end of the page. Add a
 * constant here only when a group earns a fixed position.
 */
final class WidgetGroup
{
    /** Headline counts — who is in the system, what is switched on. */
    public const OVERVIEW = 'overview';

    /** Sign-ins, failures, audit trail. */
    public const SECURITY = 'security';

    /** The installation itself: versions, drivers, disk. */
    public const SYSTEM = 'system';

    /** Money — claimed by the finance phases, listed here so its position is fixed. */
    public const FINANCE = 'finance';

    /** Delivery work: projects, tasks, milestones. */
    public const OPERATIONS = 'operations';

    /** Institute: admissions, batches, attendance. */
    public const INSTITUTE = 'institute';

    /** Where an unknown group is sorted: after every known one. */
    private const UNKNOWN_SORT = 900;

    /**
     * slug => [label, sort]. Sort is the order the sections appear down the page.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private const KNOWN = [
        self::OVERVIEW => ['Overview', 100],
        self::OPERATIONS => ['Operations', 200],
        self::INSTITUTE => ['Institute', 300],
        self::FINANCE => ['Finance', 400],
        self::SECURITY => ['Security', 500],
        self::SYSTEM => ['System', 600],
    ];

    /**
     * Normalise whatever a widget returned into a usable slug.
     */
    public static function normalise(?string $group): string
    {
        $slug = Str::snake(trim((string) $group));

        return $slug === '' ? self::OVERVIEW : $slug;
    }

    /**
     * Display label. An unregistered slug is humanised rather than rejected.
     */
    public static function label(?string $group): string
    {
        $slug = self::normalise($group);

        return self::KNOWN[$slug][0] ?? Str::headline($slug);
    }

    /**
     * Where the section sits on the page. Unknown groups land after the known ones, in
     * alphabetical order among themselves.
     */
    public static function sort(?string $group): int
    {
        $slug = self::normalise($group);

        return self::KNOWN[$slug][1] ?? self::UNKNOWN_SORT;
    }

    /**
     * The known groups, in display order: slug => label.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $groups = [];

        foreach (self::KNOWN as $slug => [$label]) {
            $groups[$slug] = $label;
        }

        return $groups;
    }
}
