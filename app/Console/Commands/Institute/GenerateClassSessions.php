<?php

declare(strict_types=1);

namespace App\Console\Commands\Institute;

use App\Models\Institute\Batch;
use App\Services\Institute\ClassSessionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `institute:generate-sessions` — materialise the dated classes the weekly rules produce (§10.4).
 *
 * Daily at 00:20, out to `institute.session_generation_weeks_ahead`. Idempotent by
 * `uq_cs_generated(timetable_entry_id, session_date, active_guard)`, so this running while somebody
 * presses Generate on a screen creates nothing twice — the second insert is a 1062 the service
 * swallows. That is why the horizon can be re-walked from the start every night rather than the
 * command having to remember where it got to.
 */
#[AsCommand(name: 'institute:generate-sessions')]
final class GenerateClassSessions extends Command
{
    protected $signature = 'institute:generate-sessions
        {--batch= : Only this batch id}
        {--weeks= : Override institute.session_generation_weeks_ahead}';

    protected $description = 'Generate class sessions from the timetable, out to the configured horizon';

    public function handle(ClassSessionService $sessions): int
    {
        $weeks = (int) ($this->option('weeks') ?: setting('institute.session_generation_weeks_ahead', 8));
        $weeks = max(1, min(52, $weeks));

        $from = Carbon::today();
        $to = $from->copy()->addWeeks($weeks);

        $batch = $this->option('batch') !== null
            ? Batch::query()->find((int) $this->option('batch'))
            : null;

        if ($this->option('batch') !== null && $batch === null) {
            $this->error('No batch with id '.$this->option('batch').'.');

            return self::FAILURE;
        }

        $created = $sessions->generate($batch, $from, $to);

        $this->info(sprintf(
            '%d class%s generated between %s and %s%s.',
            $created,
            $created === 1 ? '' : 'es',
            $from->toDateString(),
            $to->toDateString(),
            $batch !== null ? ' for '.$batch->code : '',
        ));

        return self::SUCCESS;
    }
}
