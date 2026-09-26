<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Delivery;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Models\Project\ProjectMilestone;
use App\Support\DateRange;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Throwable;

/**
 * The next fortnight of commitments, and the ones already broken.
 *
 * **A missed milestone is not a late task.** A milestone is what was promised to the client — and once
 * Phase 10 is live, `project_milestones.amount` is what a payment can be booked against — so a missed
 * one is a conversation somebody has to have today, not a card to drag. It leads the widget; the
 * fortnight ahead sits underneath as the workload it implies.
 *
 * **Fourteen days, counted inclusively from today.** A fortnight horizon is long enough to show the
 * commitment somebody can still rescue and short enough that everything on it is this sprint's problem;
 * a month-long window fills the card with dates nobody will act on this week. The seven-day figure is
 * carried separately, because "eleven due in a fortnight" and "nine of them this week" are different
 * situations.
 *
 * **Open is `MilestoneStatus::isOpen()`** — not completed, not cancelled (golden rule 8) — and only
 * inside a live project: a cancelled project's pending milestones are not commitments any more, and a
 * soft-deleted (archived) project keeps its milestone rows, so without the join they would haunt this
 * count for ever. The join is part of the single query.
 *
 * **The oldest missed deadline is named**, because "four missed" is a statistic and a date is something
 * a reader can weigh. It comes out of the same aggregate as a `MIN()`, not a second query, and is
 * rendered through `app_date()` like every other date in the application.
 *
 * A state, not a period: the dashboard's range is ignored, as
 * {@see \App\Dashboard\Widgets\Institute\ActiveCoursesWidget} explains — a deadline horizon that moved
 * with the selector would report last month's fortnight. `project_milestones.deadline` is a DATE
 * column, compared to date strings, so the `deadline` index stays usable.
 */
final class MilestonesDueWidget extends Widget
{
    /** The horizon, in calendar days including today: a fortnight. */
    private const HORIZON_DAYS = 14;

    /** The nearer horizon carried alongside it: this week. */
    private const WEEK_DAYS = 7;

    public function key(): string
    {
        return 'delivery_milestones_due';
    }

    public function title(): string
    {
        return 'Milestones due';
    }

    public function icon(): string
    {
        return 'flag';
    }

    public function permission(): ?string
    {
        return 'project_milestones.view_any';
    }

    public function module(): ?string
    {
        return 'project_milestones';
    }

    public function group(): string
    {
        return WidgetGroup::OPERATIONS;
    }

    public function sort(): int
    {
        return 30;
    }

    /**
     * Phase 6 §7.2 registers no global milestone list — `milestones.index` is nested under a project
     * (`projects/{project}/milestones`) and `routeUrl()` would refuse it for want of the parameter. The
     * project list is the screen every milestone is reachable from, so that is where the card points.
     */
    public function href(): ?string
    {
        return $this->routeUrl('admin.projects.index');
    }

    public function emptyMessage(): ?string
    {
        return 'Nothing is promised in the next fortnight.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $openMilestones = array_map(
                static fn (MilestoneStatus $status): string => $status->value,
                array_filter(
                    MilestoneStatus::cases(),
                    static fn (MilestoneStatus $status): bool => $status->isOpen(),
                ),
            );

            $openProjects = array_map(
                static fn (ProjectStatus $status): string => $status->value,
                ProjectStatus::open(),
            );

            // One `now()`, three boundaries: two calls could straddle midnight and put "today" and the
            // horizon on different days.
            $now = CarbonImmutable::now(Format::timezone());
            $today = $now->toDateString();
            $weekEnd = $now->addDays(self::WEEK_DAYS - 1)->toDateString();
            $horizon = $now->addDays(self::HORIZON_DAYS - 1)->toDateString();

            // **One query.** Six figures over the same open milestones of live projects. `deadline` is
            // qualified throughout: `projects` has a `deadline` column of its own and the join would
            // otherwise make every reference ambiguous.
            $row = ProjectMilestone::query()
                ->toBase()
                ->join('projects', static fn (JoinClause $join): JoinClause => $join
                    ->on('projects.id', '=', 'project_milestones.project_id')
                    ->whereNull('projects.deleted_at')
                    ->whereIn('projects.status', $openProjects))
                ->whereIn('project_milestones.status', $openMilestones)
                ->selectRaw(
                    'COUNT(*) as open_total,'
                    .' SUM(CASE WHEN project_milestones.deadline IS NOT NULL AND project_milestones.deadline < ?'
                    .' THEN 1 ELSE 0 END) as missed,'
                    .' SUM(CASE WHEN project_milestones.deadline BETWEEN ? AND ? THEN 1 ELSE 0 END) as due_fortnight,'
                    .' SUM(CASE WHEN project_milestones.deadline BETWEEN ? AND ? THEN 1 ELSE 0 END) as due_week,'
                    .' SUM(CASE WHEN project_milestones.deadline IS NULL THEN 1 ELSE 0 END) as undated,'
                    .' MIN(CASE WHEN project_milestones.deadline < ? THEN project_milestones.deadline ELSE NULL END)'
                    .' as oldest_missed',
                    [$today, $today, $horizon, $today, $weekEnd, $today],
                )
                ->first();
        } catch (Throwable) {
            return ['available' => false];
        }

        $oldest = $row?->oldest_missed ?? null;

        return [
            'available' => true,
            'open' => (int) ($row?->open_total ?? 0),
            'missed' => (int) ($row?->missed ?? 0),
            'due_fortnight' => (int) ($row?->due_fortnight ?? 0),
            'due_week' => (int) ($row?->due_week ?? 0),
            'undated' => (int) ($row?->undated ?? 0),
            'oldest_missed' => is_string($oldest) && $oldest !== '' ? $oldest : null,
            'horizon_days' => self::HORIZON_DAYS,
        ];
    }
}
