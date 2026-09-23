<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

use App\Services\Support\MeetingService;
use App\Support\Modules;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `meetings:send-reminders` — every five minutes (phase-19-23 §10.5).
 *
 * Meetings whose `scheduled_at - reminder_minutes_before` has arrived and that have never been
 * reminded about. `reminder_sent_at` is stamped **inside** the transaction and the notification is
 * queued after it commits, so a crash between the two loses one reminder and never sends two.
 */
#[AsCommand(name: 'meetings:send-reminders')]
final class SendMeetingReminders extends Command
{
    protected $signature = 'meetings:send-reminders {--limit=200 : Maximum meetings reminded about in this run}';

    protected $description = 'Remind participants about meetings that are about to start';

    public function handle(MeetingService $meetings): int
    {
        if (! Modules::enabled('meetings')) {
            $this->info('The meetings module is disabled: no reminders sent.');

            return self::SUCCESS;
        }

        $sent = $meetings->sendDueReminders(CarbonImmutable::now(), (int) $this->option('limit'));

        $this->info(sprintf('%d meeting reminder(s) sent.', $sent));

        return self::SUCCESS;
    }
}
