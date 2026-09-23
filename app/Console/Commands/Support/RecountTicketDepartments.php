<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

use App\Services\Support\TicketService;
use App\Support\Modules;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `tickets:recount-departments` — daily (phase-19-23 §10.5).
 *
 * `open_tickets_count` is a cache, and §5's rule for every cache in this system applies: it has to
 * be re-derivable by counting. This is that repair, run nightly rather than trusted — a figure that
 * only ever drifted upward would look plausible for months.
 */
#[AsCommand(name: 'tickets:recount-departments')]
final class RecountTicketDepartments extends Command
{
    protected $signature = 'tickets:recount-departments';

    protected $description = 'Repair the open-ticket count on every support desk';

    public function handle(TicketService $tickets): int
    {
        if (! Modules::enabled('support_tickets')) {
            $this->info('The support tickets module is disabled: nothing recounted.');

            return self::SUCCESS;
        }

        $result = $tickets->recountAllDepartments();

        $this->info(sprintf(
            '%d desk(s) checked, %d repaired.',
            $result['departments'],
            $result['repaired'],
        ));

        return self::SUCCESS;
    }
}
