<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Delivery;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\ProjectStatus;
use App\Models\Project\Project;
use App\Support\DateRange;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * What delivery is actually carrying: live projects, where they sit, and which ones are late.
 *
 * **The headline is the overdue count, not the total.** "Twelve projects" is a fact somebody already
 * knows; "three past their deadline" is the reason to open the list this morning. The total sits
 * underneath it, where it belongs, and the board-order split is there so a manager can tell twelve
 * projects sitting in `planning` from twelve in `testing` — the same number, two very different weeks.
 *
 * **"Live" is `ProjectStatus::open()`**, the enum's own answer to "not completed, not cancelled"
 * (golden rule 8). The board, the status filter and this card therefore cannot drift into three
 * different definitions of an active project, and a ninth status added later is counted here with no
 * edit to this file.
 *
 * **It is a state, not a period, so the dashboard's range is deliberately ignored** — exactly as
 * {@see \App\Dashboard\Widgets\Institute\ActiveCoursesWidget} explains. How much work is in flight does
 * not depend on which month the selector shows, and quietly applying the range to `created_at` would
 * drop the number to zero the moment somebody picked "today".
 *
 * **Late is judged against the business's today** ({@see Format::timezone()}), not the server's UTC
 * clock, because the project list beside this card already renders every deadline in that timezone and
 * a project must not be red here and black there.
 *
 * `deadline` is a DATE column, so it is compared to a date string: no `whereDate()`, which would wrap
 * the column in a function and give up the `(status, deadline)` index.
 */
final class ActiveProjectsWidget extends Widget
{
    public function key(): string
    {
        return 'delivery_active_projects';
    }

    public function title(): string
    {
        return 'Projects in delivery';
    }

    public function icon(): string
    {
        return 'folder';
    }

    public function permission(): ?string
    {
        return 'projects.view_any';
    }

    public function module(): ?string
    {
        return 'projects';
    }

    public function group(): string
    {
        return WidgetGroup::OPERATIONS;
    }

    public function sort(): int
    {
        return 10;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.projects.index');
    }

    public function emptyMessage(): ?string
    {
        return 'Nothing is in delivery.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $open = array_map(
                static fn (ProjectStatus $status): string => $status->value,
                ProjectStatus::open(),
            );

            $today = CarbonImmutable::now(Format::timezone())->toDateString();

            // **One query, not ten.** The split, the total, the overdue count and the undated count are
            // all aggregates over the same open rows, so they are counted in a single grouped pass
            // rather than one SELECT per figure — `GET /admin` has a 60-query ceiling to keep.
            $rows = Project::query()
                ->toBase()
                ->whereIn('status', $open)
                ->selectRaw(
                    'status,'
                    .' COUNT(*) as total,'
                    .' SUM(CASE WHEN deadline IS NOT NULL AND deadline < ? THEN 1 ELSE 0 END) as overdue,'
                    .' SUM(CASE WHEN deadline IS NULL THEN 1 ELSE 0 END) as undated',
                    [$today],
                )
                ->groupBy('status')
                ->get();
        } catch (Throwable) {
            return ['available' => false];
        }

        $live = 0;
        $overdue = 0;
        $undated = 0;

        /** @var array<string, int> $totals */
        $totals = [];

        foreach ($rows as $row) {
            $status = ProjectStatus::tryFrom((string) $row->status);

            if ($status === null) {
                continue;
            }

            $total = (int) $row->total;

            $live += $total;
            $overdue += (int) $row->overdue;
            $undated += (int) $row->undated;
            $totals[$status->value] = $total;
        }

        // Board order, and only the columns that actually hold something: an eight-row list of which
        // five are zeros hides the two statuses the reader came for.
        $split = [];

        foreach (ProjectStatus::open() as $status) {
            $total = $totals[$status->value] ?? 0;

            if ($total > 0) {
                $split[] = [
                    'label' => $status->label(),
                    'total' => $total,
                ];
            }
        }

        return [
            'available' => true,
            'live' => $live,
            'overdue' => $overdue,
            'undated' => $undated,
            'split' => $split,
        ];
    }
}
