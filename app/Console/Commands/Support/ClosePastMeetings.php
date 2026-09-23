<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

use App\Services\Support\MeetingService;
use App\Support\Modules;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `meetings:close-past` — hourly (phase-19-23 §10.5).
 *
 * A meeting whose time passed more than two hours ago is over, whatever the diary still says.
 * **Attendance decides which ending it gets**: somebody marked the register, so it happened
 * (`completed`); nobody did and nobody now will (`missed`).
 *
 * Two hours rather than zero, because a meeting that overruns is still a meeting and marking it
 * missed while people are in the room teaches everybody to ignore the status column.
 */
#[AsCommand(name: 'meetings:close-past')]
final class ClosePastMeetings extends Command
{
    protected $signature = 'meetings:close-past {--limit=500 : Maximum meetings closed in this run}';

    protected $description = 'Close meetings whose time has passed';

    public function handle(MeetingService $meetings): int
    {
        if (! Modules::enabled('meetings')) {
            $this->info('The meetings module is disabled: nothing closed.');

            return self::SUCCESS;
        }

        $result = $meetings->closePast(CarbonImmutable::now(), (int) $this->option('limit'));

        $this->info(sprintf('%d completed, %d missed.', $result['completed'], $result['missed']));

        return self::SUCCESS;
    }
}
