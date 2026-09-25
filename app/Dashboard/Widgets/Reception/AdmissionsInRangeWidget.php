<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Reception;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\AdmissionStage;
use App\Models\Institute\StudentAdmission;
use App\Support\DateRange;
use Throwable;

/**
 * Admissions taken over the selected period, and how many are still stuck in the pipeline.
 *
 * **This one honours the date range, and the other front-desk cards do not.** The difference is not
 * inconsistency: an inbox, a follow-up queue and today's appointments are *states* that mean nothing
 * filtered to last month, whereas "how many admissions did we take" is a *period* question and is the
 * one number on this row somebody compares against last month.
 *
 * **Counted on `admission_date`, not `created_at`.** A walk-in admitted on Monday and entered into
 * the system on Wednesday belongs to Monday, and backdated entry is normal at a front desk. Counting
 * rows by when somebody typed them would make every late entry look like new business on the wrong
 * day, and would move admissions between months at the month boundary.
 *
 * **"In progress" is the actionable half.** An admission sitting at `fee_collection` or
 * `batch_assignment` is a student who has said yes and is not yet in a class — that is front-desk
 * work, not a statistic, so it is separated from the ones that reached `active`. `isTerminal()`
 * decides what counts as finished rather than a list of stage strings written here (golden rule 8).
 */
final class AdmissionsInRangeWidget extends Widget
{
    public function key(): string
    {
        return 'reception_admissions_range';
    }

    public function title(): string
    {
        return 'Admissions taken';
    }

    public function icon(): string
    {
        return 'user-plus';
    }

    public function permission(): ?string
    {
        return 'admissions.view_any';
    }

    public function module(): ?string
    {
        return 'admissions';
    }

    public function group(): string
    {
        return WidgetGroup::FRONT_DESK;
    }

    public function sort(): int
    {
        return 50;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.admissions.index');
    }

    public function emptyMessage(): ?string
    {
        return 'No admission was taken in this period.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $counts = StudentAdmission::query()
                ->toBase()
                ->whereBetween('admission_date', [
                    $range->start()->toDateString(),
                    $range->end()->toDateString(),
                ])
                ->selectRaw('stage, COUNT(*) as total')
                ->groupBy('stage')
                ->pluck('total', 'stage')
                ->all();
        } catch (Throwable) {
            return ['available' => false, 'range_label' => $range->label()];
        }

        $inProgress = 0;

        foreach ($counts as $stage => $total) {
            $case = AdmissionStage::tryFrom((string) $stage);

            // Live, but not yet sitting in a class: the rows the front desk can still move.
            if ($case !== null && $case->isLive() && $case !== AdmissionStage::Active) {
                $inProgress += (int) $total;
            }
        }

        return [
            'available' => true,
            'range_label' => $range->label(),
            'total' => array_sum($counts),
            'active' => (int) ($counts[AdmissionStage::Active->value] ?? 0),
            'in_progress' => $inProgress,
            'cancelled' => (int) ($counts[AdmissionStage::Cancelled->value] ?? 0)
                + (int) ($counts[AdmissionStage::Withdrawn->value] ?? 0),
        ];
    }
}
