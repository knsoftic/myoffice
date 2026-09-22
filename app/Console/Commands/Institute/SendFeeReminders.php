<?php

declare(strict_types=1);

namespace App\Console\Commands\Institute;

use App\Services\Institute\FeeReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `fees:installment-reminders` — the 09:00 run (phase-18 §6.8, §10.4).
 *
 * Three reminders per line over its life: one `institute.installment_reminder_days` ahead, one on the
 * day, and an overdue chase on a weekly cadence. Each is its own row under `uq_sfr_dedupe`, so they
 * never count as duplicates of each other — and a second run of the same morning sends nothing,
 * because the guard is a unique index rather than a `SELECT` somebody has to remember to write.
 *
 * `skipped_duplicate` in the output is **the guard working**, not a failure. A retried job, a second
 * scheduler tick and a member of staff pressing "Send reminder now" all race for the same row; one
 * wins and sends, the rest are counted here.
 *
 * `skipped_no_recipient` is deliberately separate, because it is the opposite: a student nobody can
 * write to is a data problem somebody should fix, and folding it into the duplicate count would hide it.
 */
#[AsCommand(name: 'fees:installment-reminders')]
final class SendFeeReminders extends Command
{
    protected $signature = 'fees:installment-reminders
        {--as-of= : Treat this date as today (YYYY-MM-DD)}
        {--limit= : Cap the rows examined in this run}';

    protected $description = 'Send fee-due, due-today and overdue reminders (at most one per line per day)';

    public function handle(FeeReminderService $reminders): int
    {
        $asOf = $this->option('as-of') === null ? null : Carbon::parse((string) $this->option('as-of'));
        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));

        $lead = max(0, (int) setting('institute.installment_reminder_days', 3));

        $this->line($lead === 0
            ? 'Lead-time reminders are switched off (installment_reminder_days = 0); due-today and overdue still run.'
            : sprintf('Lead time %d day%s.', $lead, $lead === 1 ? '' : 's'));

        $result = $reminders->queueDueReminders($asOf, $limit);

        $this->info($result->caption());
        $this->line(sprintf('Run %s.', $result->runUuid));

        if ($result->skippedNoRecipient > 0) {
            $this->warn(sprintf(
                '%d student%s had no login or address to write to — those fees are still outstanding and '
                .'nobody has been told.',
                $result->skippedNoRecipient,
                $result->skippedNoRecipient === 1 ? '' : 's',
            ));
        }

        activity('fee_reminders')
            ->withProperties([
                'run_uuid' => $result->runUuid,
                'as_of' => ($asOf ?? Carbon::now())->toDateString(),
                'examined' => $result->examined,
                'sent' => $result->sent,
                'skipped_duplicate' => $result->skippedDuplicate,
                'skipped_no_recipient' => $result->skippedNoRecipient,
                'failed' => $result->failed,
            ])
            ->log('fee_reminders.run');

        // A failure to reach some students is not a failure of the run: the rest were told, and the
        // next run will try these again. Returning FAILURE here would make the scheduler shout every
        // morning about a student record somebody needs to fix once.
        return self::SUCCESS;
    }
}
