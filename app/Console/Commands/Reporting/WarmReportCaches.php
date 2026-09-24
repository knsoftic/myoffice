<?php

declare(strict_types=1);

namespace App\Console\Commands\Reporting;

use App\DataObjects\Reporting\ReportRequest;
use App\Models\User;
use App\Services\Reporting\ReportEngine;
use App\Support\DateRange;
use App\Support\ReportRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Run the common reports ahead of the people who will open them (phase-19-23 10.4).
 *
 * **It warms per user, because the cache is per user.** Two people with different scopes asking the
 * same question are asking two different questions, and `ReportEngine` keys its cache on
 * `(report, viewer, filters)` for exactly that reason - so a warm-up that ran "as the system" would
 * fill the cache with entries nobody's key ever matches, taking the time and giving no benefit.
 *
 * **It warms only the default view of each report.** The filter space is unbounded; the thing worth
 * pre-computing is the page somebody actually lands on, which is the report with its own default
 * preset and no filters. Anything past that is guessing with somebody else's CPU.
 *
 * A report that throws during warm-up is skipped and reported. A nightly command that dies on the
 * fourth of thirty reports leaves twenty-six cold and looks like it worked.
 */
final class WarmReportCaches extends Command
{
    protected $signature = 'reports:warm-caches
                            {--user=* : Only these user ids}
                            {--report=* : Only these report keys}';

    protected $description = 'Pre-run the default view of each report for the people who can open it.';

    public function handle(ReportEngine $engine): int
    {
        if ((int) setting('reports.cache_ttl_seconds', 300) <= 0) {
            // Warming a cache that is switched off is pure cost.
            $this->components->warn('reports.cache_ttl_seconds is 0, so there is no cache to warm.');

            return self::SUCCESS;
        }

        $users = $this->targetUsers();

        if ($users->isEmpty()) {
            $this->components->info('No staff users to warm for.');

            return self::SUCCESS;
        }

        $only = (array) $this->option('report');
        $preset = (string) setting('reports.default_date_preset', 'month');

        $warmed = 0;
        $skipped = 0;

        foreach ($users as $user) {
            $reports = ReportRegistry::visibleTo($user);

            foreach ($reports as $report) {
                if ($only !== [] && ! in_array($report->key(), $only, true)) {
                    continue;
                }

                if (! $report->isAvailable()) {
                    continue;
                }

                try {
                    $engine->run($report->key(), new ReportRequest(range: DateRange::make($preset)), $user);
                    $warmed++;
                } catch (Throwable $exception) {
                    // Skipped, counted, reported - see the class note.
                    report($exception);
                    $skipped++;
                }
            }
        }

        $this->components->info(sprintf(
            '%d report view(s) warmed for %d user(s).%s',
            $warmed,
            $users->count(),
            $skipped > 0 ? sprintf(' %d skipped after an error.', $skipped) : '',
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, User>
     */
    private function targetUsers(): Collection
    {
        $ids = array_filter(array_map('intval', (array) $this->option('user')));

        return User::query()
            ->when($ids !== [], fn ($q) => $q->whereKey($ids))
            // Only people who could open a report at all. Warming for a student would run the
            // permission check thirty times to reach thirty refusals.
            ->whereHas('roles', fn ($q) => $q->where('panel', 'admin'))
            ->where('status', 'active')
            ->get();
    }
}
