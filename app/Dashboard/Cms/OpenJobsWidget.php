<?php

declare(strict_types=1);

namespace App\Dashboard\Cms;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Models\Cms\JobOpening;
use App\Support\DateRange;
use Throwable;

/**
 * Openings accepting applications right now, and how many close within seven days (phase-04 §8.12). A
 * standing count; "open" means the public definition — status `open` and the deadline not passed.
 */
final class OpenJobsWidget extends Widget
{
    public function key(): string
    {
        return 'open_jobs';
    }

    public function title(): string
    {
        return 'Open positions';
    }

    public function icon(): string
    {
        return 'briefcase';
    }

    public function permission(): ?string
    {
        return 'jobs.view_any';
    }

    public function module(): ?string
    {
        return 'jobs';
    }

    public function group(): string
    {
        return WidgetGroup::OPERATIONS;
    }

    public function sort(): int
    {
        return 51;
    }

    public function href(): ?string
    {
        return $this->routeUrlWithQuery('admin.jobs.index', ['status' => 'open']);
    }

    public function emptyMessage(): ?string
    {
        return 'No open positions.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $open = JobOpening::query()->public()->count();
            $closingSoon = JobOpening::query()->closingWithin(7)->count();
            $expired = JobOpening::query()->expired()->count();
        } catch (Throwable) {
            return ['available' => false, 'open' => 0, 'closing_soon' => 0, 'expired_still_open' => 0];
        }

        return [
            'available' => true,
            'open' => $open,
            'closing_soon' => $closingSoon,
            // Past their deadline but not yet closed by careers:close-expired — should be zero after 00:10.
            'expired_still_open' => $expired,
        ];
    }
}
