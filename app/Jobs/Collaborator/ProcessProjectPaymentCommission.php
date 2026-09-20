<?php

declare(strict_types=1);

namespace App\Jobs\Collaborator;

use App\Enums\CommissionProcessingState;
use App\Models\Finance\ProjectPayment;
use App\Services\Collaborator\ProjectCommissionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Evaluate one project payment for commission (spine §10.2, phase-11).
 *
 * Identical in every respect to its student twin except the table it reads — which is the point: the
 * two sides share one engine, one queue contract and one failure path, so a fix to either is a fix to
 * both.
 *
 * **The uniqueness lock is an optimisation, never the guarantee.** `uq_cle_dedupe` still yields
 * exactly one ledger row under an expired lock, a SIGKILLed worker, or a `failed_jobs` row replayed
 * weeks later.
 */
final class ProcessProjectPaymentCommission implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $paymentId,
    ) {
        // Never dispatched from inside a transaction that might roll back (INV-20). `afterCommit()`
        // rather than a property, because `Queueable` already declares one.
        $this->afterCommit();
        $this->onQueue('financial');
    }

    public function uniqueId(): string
    {
        return 'project-payment:'.$this->paymentId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(ProjectCommissionService $engine): void
    {
        $payment = ProjectPayment::query()->find($this->paymentId);

        if ($payment === null) {
            return;
        }

        $engine->handlePayment($payment);
    }

    public function failed(Throwable $e): void
    {
        $payment = ProjectPayment::query()->find($this->paymentId);

        if ($payment === null) {
            return;
        }

        ProjectPayment::allowDirectWrites(function () use ($payment, $e): void {
            $payment->forceFill([
                'commission_state' => CommissionProcessingState::Failed->value,
                'commission_skip_detail' => mb_substr($e::class.': '.$e->getMessage(), 0, 191),
                'commission_attempts' => (int) $payment->commission_attempts + 1,
                'commission_processed_at' => now(),
            ])->save();
        });
    }
}
