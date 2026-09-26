<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Hr;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\JobApplicationStatus;
use App\Enums\JobOpeningStatus;
use App\Models\Cms\JobApplication;
use App\Models\Cms\JobOpening;
use App\Support\DateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * What is advertised, and who is waiting to be looked at (requirement §16, phase-04 §2.18–2.19, T57).
 *
 * **Untriaged applications are the headline, not the opening count.** "Four positions open" is a
 * fact that was equally true last week; "nine CVs nobody has opened, the oldest six days old" is a
 * morning's work, and a candidate who hears nothing for a week has already formed an opinion of the
 * company. The openings sit underneath, as the context that explains the queue.
 *
 * **Two queries, because it is two tables**, and each one is a single aggregate pass with
 * conditional sums. `GET /admin` is measured against a query ceiling that a previous set of cards
 * broke by asking the obvious way, so neither table is counted once per figure.
 *
 * **The application half is gated separately.** The card is shown for `jobs.view_any`, which is
 * permission to see *adverts* — it says nothing about candidates, whose CVs, ratings and notes are
 * a narrower circle (§9.1.3). So the pipeline figures are only read when the viewer also holds
 * `job_applications.view_any`, and the card renders the openings alone otherwise. Hiding a number
 * in the Blade would not do: the query would still have run (golden rule 7).
 *
 * `deadline` is a `date` column and is compared against plain date strings — `whereDate()` on a
 * DATE column is unindexable. "Today" is {@see JobOpening::businessToday()}, the same calendar day
 * the public careers page and `careers:close-expired` use, so the card cannot disagree with the
 * screen it links to. Statuses come from the enums (`acceptsApplications()`, `isTerminal()`), never
 * from a literal written here (golden rule 8).
 */
final class HiringWidget extends Widget
{
    /** An advert closing inside this many days needs its shortlist now, not next week. */
    private const CLOSING_SOON_DAYS = 7;

    public function key(): string
    {
        return 'hr_hiring';
    }

    public function title(): string
    {
        return 'Hiring';
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
        return WidgetGroup::PEOPLE;
    }

    public function sort(): int
    {
        return 40;
    }

    /**
     * The untriaged queue when the viewer may read candidates, and the open adverts when they may
     * not — a "view all" that lands on a 403 is worse than no link at all.
     */
    public function href(): ?string
    {
        if ($this->mayReadApplications()) {
            return $this->routeUrlWithQuery('admin.job-applications.index', [
                'status' => JobApplicationStatus::New->value,
            ]);
        }

        return $this->routeUrlWithQuery('admin.jobs.index', [
            'status' => JobOpeningStatus::Open->value,
        ]);
    }

    public function emptyMessage(): ?string
    {
        return 'Nothing is being advertised.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $today = JobOpening::businessToday();
        $closingBy = Carbon::parse($today)->addDays(self::CLOSING_SOON_DAYS)->toDateString();
        $openStatus = JobOpeningStatus::Open->value;

        try {
            // Query 1 — the adverts. "Open" is the public definition: status `open` AND the
            // deadline not passed, exactly as JobOpening::scopePublic() reads it.
            $openings = JobOpening::query()
                ->toBase()
                ->selectRaw(
                    'SUM(CASE WHEN status = ? AND (deadline IS NULL OR deadline >= ?) THEN 1 ELSE 0 END) as open_now,'
                    .' SUM(CASE WHEN status = ? AND (deadline IS NULL OR deadline >= ?) THEN openings_count ELSE 0 END) as seats,'
                    .' SUM(CASE WHEN status = ? AND deadline BETWEEN ? AND ? THEN 1 ELSE 0 END) as closing_soon,'
                    .' SUM(CASE WHEN status = ? AND deadline IS NOT NULL AND deadline < ? THEN 1 ELSE 0 END) as expired_still_open',
                    [
                        $openStatus, $today,
                        $openStatus, $today,
                        $openStatus, $today, $closingBy,
                        $openStatus, $today,
                    ],
                )
                ->first();
        } catch (Throwable) {
            return ['available' => false];
        }

        $data = [
            'available' => true,
            'open_now' => (int) ($openings->open_now ?? 0),
            // Adverts are for a number of hires, not for one each: two openings can be eleven seats.
            'seats' => (int) ($openings->seats ?? 0),
            'closing_soon' => (int) ($openings->closing_soon ?? 0),
            'closing_soon_days' => self::CLOSING_SOON_DAYS,
            // Past the deadline but still flagged open — `careers:close-expired` should leave this
            // at zero every morning, so anything here says the scheduler is not running.
            'expired_still_open' => (int) ($openings->expired_still_open ?? 0),
            'applications_visible' => false,
            'live' => 0,
            'untriaged' => 0,
            'unassigned' => 0,
            'interviewing' => 0,
            'oldest_untriaged_at' => null,
        ];

        if (! $this->mayReadApplications()) {
            return $data;
        }

        $live = array_values(array_map(
            static fn (JobApplicationStatus $status): string => $status->value,
            array_filter(
                JobApplicationStatus::cases(),
                static fn (JobApplicationStatus $status): bool => ! $status->isTerminal(),
            ),
        ));

        try {
            // Query 2 — the live pipeline only. A rejected candidate from last year is history, and
            // scanning them would make every figure here grow with the archive.
            $applications = JobApplication::query()
                ->toBase()
                ->whereIn('status', $live)
                ->selectRaw(
                    'COUNT(*) as live,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as untriaged,'
                    .' SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) as unassigned,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as interviewing,'
                    .' MIN(CASE WHEN status = ? THEN created_at ELSE NULL END) as oldest_untriaged',
                    [
                        JobApplicationStatus::New->value,
                        JobApplicationStatus::Interview->value,
                        JobApplicationStatus::New->value,
                    ],
                )
                ->first();
        } catch (Throwable) {
            // The adverts were read; only the candidates were not. Reporting the half that
            // succeeded beats blanking a card that already has something to say.
            return $data;
        }

        return [
            ...$data,
            'applications_visible' => true,
            'live' => (int) ($applications->live ?? 0),
            'untriaged' => (int) ($applications->untriaged ?? 0),
            'unassigned' => (int) ($applications->unassigned ?? 0),
            'interviewing' => (int) ($applications->interviewing ?? 0),
            'oldest_untriaged_at' => ($applications->oldest_untriaged ?? null) === null
                ? null
                : Carbon::parse((string) $applications->oldest_untriaged),
        ];
    }

    /**
     * May the viewer see candidates as well as adverts?
     *
     * `Gate::before` already refuses every ability of a disabled module, so this answers the module
     * question too. Best effort: a gate that cannot be resolved means "no", never a 500 on the
     * admin landing page.
     */
    private function mayReadApplications(): bool
    {
        try {
            return Gate::allows('job_applications.view_any');
        } catch (Throwable) {
            return false;
        }
    }
}
