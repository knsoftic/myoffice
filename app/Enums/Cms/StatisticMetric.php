<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;

/**
 * What a statistic item counts (`website_section_items.metric`, phase-03 §2.3/§3) — requirement §9's
 * six hero statistics verbatim, plus `manual` for a typed-in number.
 *
 * Resolved by `App\Services\Cms\StatisticsProvider` (§6.11), never by a view. Every live metric is
 * guarded twice before it counts anything — `Modules::enabled(module())` **and**
 * `Schema::hasTable(table())` — so phase-03 ships a working statistics strip years before the
 * modules that feed it exist.
 *
 * **INV-12: values are decimal strings, never floats, and an unresolvable metric renders nothing —
 * never `0`.** A statistics strip must not claim "0 Students Trained" because the institute module
 * is not installed yet.
 *
 * `years_experience` counts nothing: it is `now()->year - company.founded_year` (a Phase 2 setting),
 * and resolves to null when that setting is empty, non-numeric or in the future. It is therefore the
 * one live metric with no module and no table.
 */
enum StatisticMetric: string
{
    use HasOptions;

    case Manual = 'manual';
    case ProjectsCompleted = 'projects_completed';
    case HappyClients = 'happy_clients';
    case StudentsTrained = 'students_trained';
    case ActiveCourses = 'active_courses';
    case TeamMembers = 'team_members';
    case YearsExperience = 'years_experience';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual value',
            self::ProjectsCompleted => 'Projects completed (live)',
            self::HappyClients => 'Happy clients (live)',
            self::StudentsTrained => 'Students trained (live)',
            self::ActiveCourses => 'Active courses (live)',
            self::TeamMembers => 'Team members (live)',
            self::YearsExperience => 'Years of experience (live)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Manual => 'slate',
            self::ProjectsCompleted => 'indigo',
            self::HappyClients => 'emerald',
            self::StudentsTrained => 'sky',
            self::ActiveCourses => 'cyan',
            self::TeamMembers => 'violet',
            self::YearsExperience => 'amber',
        };
    }

    /**
     * The module slug that must be enabled before this metric resolves, or null when none applies.
     */
    public function module(): ?string
    {
        return match ($this) {
            self::ProjectsCompleted => 'projects',
            self::HappyClients => 'clients',
            self::StudentsTrained => 'students',
            self::ActiveCourses => 'courses',
            self::TeamMembers => 'team',
            self::Manual, self::YearsExperience => null,
        };
    }

    /**
     * The table checked with `Schema::hasTable()` before counting, or null when nothing is counted.
     */
    public function table(): ?string
    {
        return match ($this) {
            self::ProjectsCompleted => 'projects',
            self::HappyClients => 'clients',
            self::StudentsTrained => 'students',
            self::ActiveCourses => 'courses',
            self::TeamMembers => 'team_members',
            self::Manual, self::YearsExperience => null,
        };
    }

    /**
     * Is the value computed at render time rather than typed in by an editor?
     *
     * True for everything except `manual`. `years_experience` is live even though it counts no rows:
     * it is derived from `company.founded_year`.
     */
    public function isLive(): bool
    {
        return $this !== self::Manual;
    }

    /**
     * The caption a freshly added statistic item starts with (requirement §9's wording).
     */
    public function defaultLabel(): string
    {
        return match ($this) {
            self::Manual => 'Statistic',
            self::ProjectsCompleted => 'Projects Completed',
            self::HappyClients => 'Happy Clients',
            self::StudentsTrained => 'Students Trained',
            self::ActiveCourses => 'Active Courses',
            self::TeamMembers => 'Team Members',
            self::YearsExperience => 'Years Experience',
        };
    }

    /**
     * The suffix a freshly added statistic item starts with, or null for none.
     *
     * The three cumulative counts and the years read as "500+"; a current count (active courses,
     * team members) states the exact number, so it gets no "+".
     */
    public function defaultSuffix(): ?string
    {
        return match ($this) {
            self::ProjectsCompleted,
            self::HappyClients,
            self::StudentsTrained,
            self::YearsExperience => '+',
            self::Manual, self::ActiveCourses, self::TeamMembers => null,
        };
    }

    /**
     * The six live metrics of requirement §9, in the order the hero seeds them (§6.14 step 5).
     *
     * @return list<self>
     */
    public static function heroDefaults(): array
    {
        return [
            self::ProjectsCompleted,
            self::HappyClients,
            self::StudentsTrained,
            self::ActiveCourses,
            self::TeamMembers,
            self::YearsExperience,
        ];
    }
}
