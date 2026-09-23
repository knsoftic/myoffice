<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

use App\Services\Support\TicketService;
use App\Support\Modules;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `tickets:auto-close` — daily (phase-19-23 §10.5).
 *
 * A resolved ticket closes itself after `support.ticket_auto_close_resolved_days`, because a queue
 * full of resolved-but-open tickets is one nobody can read at a glance.
 *
 * **Never a ticket whose requester has replied since it was resolved.** That reply is somebody
 * saying "it is not fixed", and closing it would be the system answering them with silence.
 */
#[AsCommand(name: 'tickets:auto-close')]
final class AutoCloseResolvedTickets extends Command
{
    protected $signature = 'tickets:auto-close {--limit=500 : Maximum tickets closed in this run}';

    protected $description = 'Close tickets that have sat resolved for long enough';

    public function handle(TicketService $tickets): int
    {
        if (! Modules::enabled('support_tickets')) {
            $this->info('The support tickets module is disabled: nothing closed.');

            return self::SUCCESS;
        }

        $closed = $tickets->autoCloseResolved(Carbon::now(), (int) $this->option('limit'));

        $this->info(sprintf('%d ticket(s) closed.', $closed));

        return self::SUCCESS;
    }
}
