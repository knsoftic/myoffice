<?php

declare(strict_types=1);

namespace App\Jobs\Collaborator;

use App\Enums\CommissionProcessingState;
use App\Models\Finance\PaymentReversal;
use App\Services\Collaborator\CommissionReversalService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Undo the commission a refund requires (spine §10.2, §6.6, phase-10-12 §10.3).
 *
 * **It may legitimately run before the earning it undoes.** On a busy queue a refund recorded seconds
 * after a receipt can overtake it. The service handles that by leaving the reversal `queued` with no
 * stamp, so `commissions:sweep` brings it back once the earning has committed — rather than deciding
 * that a refunded receipt keeps its commission because two jobs arrived in an unlucky order.
 */
final class ProcessCommissionReversal implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $reversalId,
    ) {
        // **Never dispatched from inside a transaction that might roll back** (INV-20): a receipt
        // that never existed must not earn anybody anything. `afterCommit()` rather than a
        // property, because `Queueable` already declares one and redeclaring it is a fatal.
        $this->afterCommit();
        $this->onQueue('financial');
    }

    public function uniqueId(): string
    {
        return 'payment-reversal:'.$this->reversalId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(CommissionReversalService $reversals): void
    {
        $reversal = PaymentReversal::query()->find($this->reversalId);

        if ($reversal === null) {
            return;
        }

        $reversals->handleReversal($reversal);
    }

    public function failed(Throwable $e): void
    {
        $reversal = PaymentReversal::query()->find($this->reversalId);

        if ($reversal === null) {
            return;
        }

        PaymentReversal::allowDirectWrites(function () use ($reversal, $e): void {
            $reversal->forceFill([
                'commission_state' => CommissionProcessingState::Failed->value,
                'commission_skip_detail' => mb_substr($e::class.': '.$e->getMessage(), 0, 191),
                'commission_attempts' => (int) $reversal->commission_attempts + 1,
                'commission_processed_at' => now(),
            ])->save();
        });
    }
}
