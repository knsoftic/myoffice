<?php

declare(strict_types=1);

namespace App\Console\Commands\Institute;

use App\Services\Institute\StudentFeeService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `fees:mark-overdue` — the nightly sweep (phase-18 §6.6, §10.4; spine §10.4).
 *
 * Daily at 01:00. **The only thing in the system that introduces `overdue` on a quiet charge.** A
 * receipt landing on an overdue charge moves it back to `partial` or `paid` through the same
 * `deriveStatus()`, so the two directions cannot disagree about what "overdue" means.
 *
 * Idempotent and bounded. A row already `overdue` is not rewritten and produces no event, so running
 * it twice in a night is a no-op; `--limit` caps the pass, and a run that hits its ceiling says so
 * rather than letting the count read as the whole backlog. `cancelled`, `paid`, `refunded`, `overpaid`
 * and anything with a balance of zero or less are never touched.
 *
 * One activity row per run, not one per charge: a two-thousand-row night would otherwise bury every
 * human action in that day's log, and the question anybody asks the log is "what did the sweeper do",
 * which is one answer.
 */
#[AsCommand(name: 'fees:mark-overdue')]
final class MarkFeesOverdue extends Command
{
    protected $signature = 'fees:mark-overdue
        {--as-of= : Treat this date as today (YYYY-MM-DD), for catching up a missed night}
        {--limit=1000 : Rows per pass}
        {--dry-run : Report what would be marked and write nothing}';

    protected $description = 'Mark charges and installments past their due date (plus grace) as overdue';

    public function handle(StudentFeeService $fees): int
    {
        $asOf = $this->option('as-of') === null ? null : Carbon::parse((string) $this->option('as-of'));
        $limit = max(1, (int) $this->option('limit'));

        if ((bool) $this->option('dry-run')) {
            // A dry run still has to be honest about being a dry run: the count it prints is what a
            // real pass would mark right now, and a second real pass a minute later can legitimately
            // differ because a receipt arrived.
            $this->line('Dry run — nothing will be written.');
        }

        $grace = max(0, (int) setting('institute.fee_overdue_grace_days', 0));

        $this->line(sprintf(
            'As at %s, with %d day%s of grace.',
            ($asOf ?? Carbon::now())->toDateString(),
            $grace,
            $grace === 1 ? '' : 's',
        ));

        if ((bool) $this->option('dry-run')) {
            return self::SUCCESS;
        }

        $result = $fees->markOverdue($asOf, $limit);

        $result->touchedNothing()
            ? $this->info($result->caption())
            : $this->warn($result->caption());

        if ($result->reachedLimit) {
            $this->line('Run it again, or raise --limit, to clear the rest.');
        }

        activity('student_fees')
            ->withProperties([
                'as_of' => ($asOf ?? Carbon::now())->toDateString(),
                'grace_days' => $grace,
                'charges' => $result->chargeIds,
                'installments' => $result->installmentIds,
                'reached_limit' => $result->reachedLimit,
            ])
            ->log('fees.overdue_sweep');

        return self::SUCCESS;
    }
}
