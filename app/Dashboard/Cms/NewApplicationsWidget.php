<?php

declare(strict_types=1);

namespace App\Dashboard\Cms;

use App\Dashboard\Concerns\ComparesRanges;
use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\JobApplicationStatus;
use App\Models\Cms\JobApplication;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Job applications received in the range, with the six-stage funnel of those applications by their current
 * stage (phase-04 §8.12). Scoped by `JobApplication::visibleTo()`.
 */
final class NewApplicationsWidget extends Widget
{
    use ComparesRanges;

    public function key(): string
    {
        return 'new_applications';
    }

    public function title(): string
    {
        return 'Job applications';
    }

    public function icon(): string
    {
        return 'document-text';
    }

    public function permission(): ?string
    {
        return 'job_applications.view_any';
    }

    public function module(): ?string
    {
        return 'job_applications';
    }

    public function group(): string
    {
        return WidgetGroup::OPERATIONS;
    }

    public function sort(): int
    {
        return 50;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.job-applications.index');
    }

    public function emptyMessage(): ?string
    {
        return 'No applications in this period.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $previous = $range->previous();

        try {
            $query = JobApplication::query();
            $user = Auth::user();

            if ($user instanceof User) {
                $query->visibleTo($user);
            }

            $byStage = (clone $query)
                ->whereBetween('job_applications.created_at', [$range->storageStart()->format('Y-m-d H:i:s'), $range->storageEnd()->format('Y-m-d H:i:s')])
                ->selectRaw('job_applications.status as stage, count(*) as total')
                ->groupBy('job_applications.status')
                ->pluck('total', 'stage');

            $previousTotal = (clone $query)
                ->whereBetween('job_applications.created_at', [$previous->storageStart()->format('Y-m-d H:i:s'), $previous->storageEnd()->format('Y-m-d H:i:s')])
                ->count();
        } catch (Throwable) {
            return ['available' => false, 'total' => 0, 'funnel' => [], 'delta' => $this->delta(0, 0), 'range_label' => $range->label()];
        }

        $funnel = [];

        foreach (JobApplicationStatus::cases() as $stage) {
            $funnel[] = [
                'value' => $stage->value,
                'label' => $stage->label(),
                'color' => $stage->color(),
                'count' => (int) ($byStage[$stage->value] ?? 0),
                'href' => $this->routeUrlWithQuery('admin.job-applications.index', ['status' => $stage->value]),
            ];
        }

        $total = array_sum(array_column($funnel, 'count'));

        return [
            'available' => true,
            'total' => $total,
            'funnel' => $funnel,
            'delta' => $this->delta($total, $previousTotal),
            'range_label' => $range->label(),
            'previous_label' => $previous->label(),
        ];
    }
}
