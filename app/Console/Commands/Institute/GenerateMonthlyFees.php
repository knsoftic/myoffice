<?php

declare(strict_types=1);

namespace App\Console\Commands\Institute;

use App\Jobs\Institute\GenerateMonthlyFeeCharges;
use App\Models\Institute\Batch;
use App\Models\Institute\StudentAdmission;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `fees:generate-monthly` — raise next month's fees ahead of time (phase-18 §10.4).
 *
 * Daily at 00:20, and a no-op on almost every one of those days. It dispatches one
 * `GenerateMonthlyFeeCharges` per batch whose active admissions carry a monthly fee and whose due day
 * falls inside the next ten days — so the charges exist, and the reminders can go out, before anybody
 * is expected to pay.
 *
 * **Running it twice raises nothing twice.** The job is `ShouldBeUnique` per batch and month, and
 * underneath that `uq_sf_generation` on `monthly:{admission}:{YYYY-MM}` means the INSERT itself is the
 * duplicate check (§6.1.3). Neither guard is trusted alone: the lock saves the work, the index saves
 * the money.
 */
#[AsCommand(name: 'fees:generate-monthly')]
final class GenerateMonthlyFees extends Command
{
    /** How far ahead a due day has to be for the month to be worth raising now. */
    private const LOOKAHEAD_DAYS = 10;

    protected $signature = 'fees:generate-monthly
        {--month= : The month to raise (YYYY-MM); defaults to whichever one is coming up}
        {--batch= : Only this batch id}
        {--dry-run : List the batches that would be dispatched and dispatch nothing}';

    protected $description = 'Dispatch monthly fee generation for batches whose due day is coming up';

    public function handle(): int
    {
        $month = $this->option('month') === null
            ? $this->upcomingMonth()
            : CarbonImmutable::parse((string) $this->option('month').'-01');

        if ($month === null) {
            $this->info(sprintf(
                'No monthly fee falls due in the next %d days. Nothing to do.',
                self::LOOKAHEAD_DAYS,
            ));

            return self::SUCCESS;
        }

        $batches = $this->batchesWithMonthlyFees();

        if ($batches->isEmpty()) {
            $this->info('No batch has an active admission with a monthly fee.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%s — %d batch%s.', $month->format('F Y'), $batches->count(), $batches->count() === 1 ? '' : 'es'));

        foreach ($batches as $batch) {
            if ((bool) $this->option('dry-run')) {
                $this->line(sprintf('  would dispatch %s', (string) $batch->code));

                continue;
            }

            GenerateMonthlyFeeCharges::dispatch((int) $batch->getKey(), $month->format('Y-m'));
        }

        if ((bool) $this->option('dry-run')) {
            $this->line('Dry run — nothing was dispatched.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d job%s dispatched.', $batches->count(), $batches->count() === 1 ? '' : 's'));

        return self::SUCCESS;
    }

    /**
     * The month whose due day lands inside the lookahead — or null, which is the answer on most days.
     */
    private function upcomingMonth(): ?CarbonImmutable
    {
        $day = max(1, min(28, (int) setting('institute.monthly_fee_due_day', 5)));
        $today = CarbonImmutable::parse(now()->toDateString());
        $horizon = $today->addDays(self::LOOKAHEAD_DAYS);

        foreach ([$today, $today->addMonthNoOverflow()] as $candidate) {
            $due = $candidate->setDay(min($day, $candidate->daysInMonth));

            if ($due->betweenIncluded($today, $horizon)) {
                return $candidate->startOfMonth();
            }
        }

        return null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Batch>
     */
    private function batchesWithMonthlyFees(): \Illuminate\Support\Collection
    {
        return Batch::query()
            ->when($this->option('batch') !== null, fn ($q) => $q->whereKey((int) $this->option('batch')))
            ->whereIn('id', StudentAdmission::query()
                ->whereNull('cancelled_at')
                ->whereNull('withdrawn_at')
                ->whereNotNull('batch_id')
                ->where(function ($q): void {
                    // Either the admission carries its own figure, or the course does. A batch where
                    // neither does has nothing to raise, and this command does not invent one.
                    $q->where('monthly_fee', '>', 0)
                        ->orWhereIn('course_id', \App\Models\Institute\Course::query()
                            ->where('monthly_fee', '>', 0)
                            ->select('id'));
                })
                ->select('batch_id'))
            ->get();
    }
}
