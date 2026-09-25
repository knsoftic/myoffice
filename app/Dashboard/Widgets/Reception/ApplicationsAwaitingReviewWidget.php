<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Reception;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\StudentApplicationStatus;
use App\Models\Institute\StudentApplication;
use App\Support\DateRange;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The §67 application inbox: how many people are waiting for somebody to look at them.
 *
 * **A state, not a period.** The dashboard's date range is deliberately ignored. An application
 * submitted three weeks ago is *more* urgent than one submitted this morning, and a card that
 * filtered to "today" would report zero on the exact morning the backlog most needed reading.
 *
 * **The age is the number that matters, not the count.** Twelve applications that all arrived in the
 * last hour is a busy morning; one application that has been sitting for four days is somebody who
 * has already decided the institute does not answer. So the headline is the count and the line under
 * it is the oldest wait, because those two together are the only pair that tells the front desk
 * whether to act now.
 *
 * `isOpen()` on the enum decides what "waiting" means, rather than a status string written here —
 * the inbox screen, the stale-application alert and this card all have to agree, and they only do
 * that by asking the same method (golden rule 8).
 */
final class ApplicationsAwaitingReviewWidget extends Widget
{
    public function key(): string
    {
        return 'reception_applications_awaiting';
    }

    public function title(): string
    {
        return 'Applications waiting';
    }

    public function icon(): string
    {
        return 'inbox-arrow-down';
    }

    public function permission(): ?string
    {
        return 'student_applications.view_any';
    }

    public function module(): ?string
    {
        return 'student_applications';
    }

    public function group(): string
    {
        return WidgetGroup::FRONT_DESK;
    }

    public function sort(): int
    {
        return 10;
    }

    public function href(): ?string
    {
        return $this->routeUrlWithQuery('admin.student-applications.index', [
            'status' => StudentApplicationStatus::Submitted->value,
        ]);
    }

    public function emptyMessage(): ?string
    {
        return 'The inbox is clear.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $open = array_map(
                static fn (StudentApplicationStatus $status): string => $status->value,
                array_filter(
                    StudentApplicationStatus::cases(),
                    static fn (StudentApplicationStatus $status): bool => $status->isOpen(),
                ),
            );

            // **One query, not four.** Every figure below is an aggregate over the same rows, so
            // they are counted in a single pass with conditional sums rather than by asking the
            // table once per number. Five cards on this row used to cost twelve queries between
            // them, which is how a dashboard drifts past its budget one reasonable addition at a
            // time. "Today" is in the institute's timezone, not the server's, so the card cannot
            // disagree with the list screen it links to.
            $row = StudentApplication::query()
                ->toBase()
                ->whereIn('status', $open)
                ->selectRaw(
                    'COUNT(*) as waiting,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as submitted,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as under_review,'
                    .' SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) as arrived_today,'
                    .' MIN(created_at) as oldest',
                    [
                        StudentApplicationStatus::Submitted->value,
                        StudentApplicationStatus::UnderReview->value,
                        Carbon::now(config('app.timezone'))->toDateString(),
                    ],
                )
                ->first();
        } catch (Throwable) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'waiting' => (int) ($row->waiting ?? 0),
            'submitted' => (int) ($row->submitted ?? 0),
            'under_review' => (int) ($row->under_review ?? 0),
            // Of the ones still waiting, how many are from today — a fresh backlog and a stale one
            // of the same size are different problems.
            'today' => (int) ($row->arrived_today ?? 0),
            'oldest_at' => ($row->oldest ?? null) === null ? null : Carbon::parse($row->oldest),
        ];
    }
}
