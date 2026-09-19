<?php

declare(strict_types=1);

namespace App\Console\Commands\Crm;

use App\Services\Crm\LeadFollowUpService;
use App\Support\Modules;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `crm:follow-up-reminders` — every five minutes (phase-05 §10.5, tests 28-29).
 *
 * Due reminders are selected under a row lock (SKIP LOCKED where the server has it), `reminder_sent_at` is stamped
 * inside the transaction and each `LeadFollowUpDueReminder` is queued after commit, so neither a second immediate
 * run nor an overlapping one can send a reminder twice. At most `--limit` (200) per run.
 */
#[AsCommand(name: 'crm:follow-up-reminders')]
final class SendFollowUpReminders extends Command
{
    protected $signature = 'crm:follow-up-reminders {--limit=200 : Maximum reminders sent in this run}';

    protected $description = 'Send the follow-up reminders that are due';

    public function handle(LeadFollowUpService $followUps): int
    {
        if (! Modules::enabled('leads')) {
            $this->info('The leads module is disabled: no reminders sent.');

            return self::SUCCESS;
        }

        $limit = max(1, min(1000, (int) $this->option('limit')));
        $sent = $followUps->sendDueReminders(CarbonImmutable::now(), $limit);

        $this->info(sprintf('%d follow-up reminder(s) sent.', $sent));

        return self::SUCCESS;
    }
}
