<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

use App\Services\Support\TicketSlaService;
use App\Support\Modules;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `tickets:sla-sweep` — every ten minutes (phase-19-23 §10.5, F-13.5).
 *
 * Stamps what has passed its target and fires `ticket.sla_breach` **once per kind per ticket**. The
 * breach booleans are the idempotency key: the sweep selects only rows where they are still false
 * and sets them in the same transaction, so running every ten minutes does not page the assignee
 * 144 times a day about the same ticket.
 *
 * **A logged no-op when `support.sla_enabled` is off**, and when the module is disabled. Neither is
 * an error: an institute that does not promise response times has no targets to miss.
 */
#[AsCommand(name: 'tickets:sla-sweep')]
final class SweepTicketSla extends Command
{
    protected $signature = 'tickets:sla-sweep {--limit=500 : Maximum tickets stamped per kind in this run}';

    protected $description = 'Stamp support tickets that have passed their response or resolution target';

    public function handle(TicketSlaService $sla): int
    {
        if (! Modules::enabled('support_tickets')) {
            $this->info('The support tickets module is disabled: nothing swept.');

            return self::SUCCESS;
        }

        $result = $sla->sweep(CarbonImmutable::now(), (int) $this->option('limit'));

        $this->info(sprintf(
            '%d first-response breach(es), %d resolution breach(es).',
            $result['first_response'],
            $result['resolution'],
        ));

        return self::SUCCESS;
    }
}
