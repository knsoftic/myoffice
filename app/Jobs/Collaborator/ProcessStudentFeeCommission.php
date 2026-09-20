<?php

declare(strict_types=1);

namespace App\Jobs\Collaborator;

use App\Enums\CommissionProcessingState;
use App\Models\Institute\StudentFeePayment;
use App\Services\Collaborator\StudentCommissionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Evaluate one student receipt for commission (spine §10.2, phase-10-12 §6.1, §10.3).
 *
 * **The uniqueness lock is an optimisation, never the guarantee.** A cache lock can expire, a worker
 * can be SIGKILLed mid-flight, and an operator can replay a `failed_jobs` row weeks later — under all
 * three, `uq_cle_dedupe` still yields exactly one ledger row. This job simply avoids the wasted work.
 *
 * With `QUEUE_CONNECTION=sync` — the test suite, and a fresh install before a worker is running — this
 * runs inline after the commit, through the same code path. That is deliberate: the suite proves the
 * production behaviour rather than a simplified version of it.
 */
final class ProcessStudentFeeCommission implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $paymentId,
    ) {
        // **Never dispatched from inside a transaction that might roll back** (INV-20): a receipt
        // that never existed must not earn anybody anything. `afterCommit()` rather than a
        // property, because `Queueable` already declares one and redeclaring it is a fatal.
        $this->afterCommit();
        $this->onQueue('financial');
    }

    public function uniqueId(): string
    {
        return 'student-fee-payment:'.$this->paymentId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(StudentCommissionService $engine): void
    {
        $payment = StudentFeePayment::query()->find($this->paymentId);

        if ($payment === null) {
            // A receipt cannot be deleted (D16, `trg_sfp_no_delete`), so this only happens when a
            // transaction rolled back after the job was queued by something that ignored $afterCommit.
            // Nothing to do, and nothing worth alerting anybody about.
            return;
        }

        $engine->handlePayment($payment);
    }

    /**
     * The receipt records its own failure, so the skip report shows it beside every other receipt that
     * earned nothing — rather than the failure living only in `failed_jobs`, where nobody looking at
     * the money would ever find it.
     */
    public function failed(Throwable $e): void
    {
        $payment = StudentFeePayment::query()->find($this->paymentId);

        if ($payment === null) {
            return;
        }

        StudentFeePayment::allowDirectWrites(function () use ($payment, $e): void {
            $payment->forceFill([
                'commission_state' => CommissionProcessingState::Failed->value,
                'commission_skip_detail' => mb_substr($e::class.': '.$e->getMessage(), 0, 191),
                'commission_attempts' => (int) $payment->commission_attempts + 1,
                'commission_processed_at' => now(),
            ])->save();
        });
    }
}
