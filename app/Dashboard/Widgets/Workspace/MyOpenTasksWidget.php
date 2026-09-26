<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Workspace;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\TaskStatus;
use App\Models\Project\Task;
use App\Support\DateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The signed-in person's own open tasks — how many, how many are late, what is due today.
 *
 * **Scoped to `tasks.assigned_user_id`, and to nothing else.** That is the column INV-P10 makes the
 * single staff assignee of a task (`assigned_collaborator_id` is the other half of the same
 * constraint and belongs to the collaborator panel, not here). The id comes from `Auth::id()` —
 * never from a request parameter, never from a hidden field — which is CLAUDE.md golden rule 10 as
 * written: scope by the owning relation, not by something the browser can post.
 *
 * **A Developer holds `tasks.view_any`, and this card still shows only their own rows.** That is
 * deliberate. The permission is what makes the card visible; it is not an instruction to widen it.
 * `Task::scopeVisibleTo()` treats `view_any` as "the whole board", and the whole board is what
 * `admin.tasks.index` is for — this card is the answer to "what am I supposed to be doing", and a
 * developer's own eleven tasks are a to-do list where the company's four hundred are wallpaper.
 *
 * **`Auth::id()` null means zero, never everybody.** A console render — a queued job, an artisan
 * command warming the dashboard — has no viewer, and a widget that quietly falls back to "all rows"
 * when it cannot identify one is a data leak with a friendly face. It returns a truthful empty
 * result instead, and the card says so.
 *
 * **`due_date` is a DATE column, so it is compared to a date string.** No `whereDate()` and no
 * `DATE(due_date)`: either would wrap the column in a function and throw away `idx` on
 * `(due_date, status)` — and on `(assigned_user_id, status)`, which is the index this query lives on.
 *
 * **One query.** Five figures, one pass, conditional sums.
 */
final class MyOpenTasksWidget extends Widget
{
    public function key(): string
    {
        return 'my_open_tasks';
    }

    public function title(): string
    {
        return 'My open tasks';
    }

    public function icon(): string
    {
        return 'check-circle';
    }

    public function permission(): ?string
    {
        return 'tasks.view_any';
    }

    public function module(): ?string
    {
        return 'tasks';
    }

    public function group(): string
    {
        return WidgetGroup::OPERATIONS;
    }

    public function sort(): int
    {
        return 20;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.tasks.my');
    }

    public function emptyMessage(): ?string
    {
        return 'Nothing is assigned to you.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $viewer = Auth::id();

        if ($viewer === null) {
            return self::nobody();
        }

        try {
            // "Today" in the institute's timezone, not the server's, so the card cannot disagree
            // with the list screen it links to.
            $today = DateRange::today()->start()->toDateString();

            $open = array_map(
                static fn (TaskStatus $status): string => $status->value,
                array_filter(
                    TaskStatus::cases(),
                    static fn (TaskStatus $status): bool => $status->isOpen(),
                ),
            );

            $row = Task::query()
                ->toBase()
                ->where('assigned_user_id', $viewer)
                ->whereIn('status', $open)
                ->selectRaw(
                    'COUNT(*) as open_total,'
                    .' SUM(CASE WHEN due_date IS NOT NULL AND due_date < ? THEN 1 ELSE 0 END) as overdue,'
                    .' SUM(CASE WHEN due_date = ? THEN 1 ELSE 0 END) as due_today,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as blocked,'
                    // The soonest deadline still ahead of the person, so the card has something to
                    // say on the good morning when nothing is late.
                    .' MIN(CASE WHEN due_date > ? THEN due_date END) as next_due',
                    [$today, $today, TaskStatus::Blocked->value, $today],
                )
                ->first();
        } catch (Throwable) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'mine' => true,
            'open' => (int) ($row->open_total ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'due_today' => (int) ($row->due_today ?? 0),
            'blocked' => (int) ($row->blocked ?? 0),
            'next_due' => ($row->next_due ?? null) === null ? null : Carbon::parse($row->next_due),
        ];
    }

    /**
     * What a render with no signed-in viewer reports: zero, honestly, rather than everyone's work.
     *
     * @return array<string, mixed>
     */
    private static function nobody(): array
    {
        return [
            'available' => true,
            'mine' => false,
            'open' => 0,
            'overdue' => 0,
            'due_today' => 0,
            'blocked' => 0,
            'next_due' => null,
        ];
    }
}
