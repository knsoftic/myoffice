<?php

declare(strict_types=1);

namespace App\Listeners\Collaborator;

use App\Events\Finance\ProjectPaymentRecorded;
use App\Jobs\Collaborator\ProcessProjectPaymentCommission;

/**
 * The single link between a client payment and the engine (spine §10.1, phase-11).
 *
 * Synchronous, and it does exactly one thing: dispatch the job. The same shape as the student side,
 * for the same reason — whoever recorded the money must never wait on the commission engine.
 */
final class QueueProjectPaymentCommission
{
    public function handle(ProjectPaymentRecorded $event): void
    {
        ProcessProjectPaymentCommission::dispatch((int) $event->payment->getKey());
    }
}
