<?php

declare(strict_types=1);

namespace App\Console\Commands\Institute;

use App\Enums\BatchStatus;
use App\Enums\ClassSessionStatus;
use App\Models\Institute\Batch;
use App\Services\Institute\BatchService;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `batches:advance-status` — the two moves a calendar can make on its own (§10.4, §2.30.7).
 *
 * Daily at 00:30. `enrolling -> running` when the start date has arrived and somebody is enrolled;
 * `running -> completed` when the end date has passed and no class is still outstanding.
 *
 * **Both go through `BatchService::changeStatus()`**, so the same guards apply as when a person does
 * it. A command that wrote the column directly would be the one caller allowed to skip the checks,
 * and it would be the one that eventually produced a completed batch with classes still to come.
 */
#[AsCommand(name: 'batches:advance-status')]
final class AdvanceBatchStatus extends Command
{
    protected $signature = 'batches:advance-status {--dry-run : Report what would move and change nothing}';

    protected $description = 'Start batches whose date has come, and close the ones that are finished';

    public function handle(BatchService $batches): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::today();
        $started = [];
        $completed = [];
        $refused = [];

        // enrolling -> running
        Batch::query()
            ->where('status', BatchStatus::Enrolling->value)
            ->whereDate('start_date', '<=', $today->toDateString())
            ->each(function (Batch $batch) use ($batches, $dryRun, &$started, &$refused): void {
                try {
                    if (! $dryRun) {
                        $batches->changeStatus($batch, BatchStatus::Running);
                    }

                    $started[] = $batch->code;
                } catch (CourseRuleException $e) {
                    // Most often "cannot start running with nobody in it" — which is correct, and
                    // worth reporting rather than retrying silently every night.
                    $refused[] = $batch->code.': '.$e->getMessage();
                }
            });

        // running -> completed
        Batch::query()
            ->where('status', BatchStatus::Running->value)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', $today->toDateString())
            ->each(function (Batch $batch) use ($batches, $dryRun, &$completed, &$refused): void {
                $outstanding = $batch->sessions()
                    ->where('status', ClassSessionStatus::Scheduled->value)
                    ->count();

                if ($outstanding > 0) {
                    $refused[] = sprintf('%s: %d class(es) still scheduled', $batch->code, $outstanding);

                    return;
                }

                try {
                    if (! $dryRun) {
                        $batches->changeStatus($batch, BatchStatus::Completed);
                    }

                    $completed[] = $batch->code;
                } catch (CourseRuleException $e) {
                    $refused[] = $batch->code.': '.$e->getMessage();
                }
            });

        $this->info(sprintf(
            '%d started, %d completed%s.',
            count($started),
            count($completed),
            $dryRun ? ' (dry run — nothing was written)' : '',
        ));

        foreach ($started as $code) {
            $this->line('  started   '.$code);
        }

        foreach ($completed as $code) {
            $this->line('  completed '.$code);
        }

        if ($refused !== []) {
            $this->newLine();
            $this->warn(count($refused).' left where they were:');

            foreach (array_slice($refused, 0, 25) as $line) {
                $this->line('  '.$line);
            }
        }

        return self::SUCCESS;
    }
}
