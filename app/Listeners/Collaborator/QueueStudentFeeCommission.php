<?php

declare(strict_types=1);

namespace App\Listeners\Collaborator;

use App\Events\Finance\StudentFeePaymentRecorded;
use App\Jobs\Collaborator\ProcessStudentFeeCommission;

/**
 * The single link between a receipt and the engine (spine §10.1, phase-10-12 §6.1).
 *
 * **Synchronous, and it does exactly one thing: dispatch the job.** Not queued, because a queued
 * listener whose only job is to queue something else is a second place the chain can break. Not the
 * calculation itself, because a cashier's response must never wait on it and a queue outage must
 * degrade to "commission pending" rather than to a failed receipt for money already in the drawer.
 */
final class QueueStudentFeeCommission
{
    public function handle(StudentFeePaymentRecorded $event): void
    {
        ProcessStudentFeeCommission::dispatch((int) $event->payment->getKey());
    }
}
