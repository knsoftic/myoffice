<?php

declare(strict_types=1);

namespace App\Console\Commands\Collaborator;

use App\Enums\CommissionProcessingState;
use App\Jobs\Collaborator\ProcessCommissionReversal;
use App\Jobs\Collaborator\ProcessStudentFeeCommission;
use App\Models\Finance\PaymentReversal;
use App\Models\Institute\StudentFeePayment;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `commissions:sweep` — re-queue the work a worker never finished (spine §10.4, phase-10-12 §6.1).
 *
 * **This is the recovery path, and it exists because the queue is not a guarantee.** A worker can be
 * SIGKILLed between the commit and the stamp; a cache lock can expire; a redelivery can arrive out of
 * order; the queue can simply have been down when the receipt was taken. Every one of those leaves a
 * receipt sitting at `queued` with money already in the drawer and no commission against it, and
 * nothing else in the system would ever notice.
 *
 * **It never touches a `skipped` row** ([D-IMP-4]). A skip is a *decision* the engine recorded with a
 * reason — "COL-1024 was suspended on 4 March" — and re-running it would produce the same decision
 * every ten minutes for ever. Undoing one is an explicit, permissioned, audited act:
 * `commissions:evaluate`. That separation is what stops a reinstated collaborator being silently
 * back-paid by a background job nobody was watching.
 *
 * **Two minutes of grace**, because a receipt recorded seconds ago is probably in a worker's hands
 * right now, and re-queueing it would be work for nothing. `ShouldBeUnique` would collapse the
 * duplicate anyway; the delay means the question rarely arises.
 *
 * **Ordered `paid_on` then id, earnings before reversals**, so that when a backlog is cleared in one
 * pass an earning is evaluated before the refund that undoes it. Order is not required for
 * correctness — a reversal that arrives first defers itself and waits for the next sweep — but doing
 * it in the natural order means the common case resolves in one pass instead of two.
 */
#[AsCommand(name: 'commissions:sweep')]
final class SweepCommissions extends Command
{
    protected $signature = 'commissions:sweep
                            {--limit=500 : Rows re-queued in one pass}
                            {--minutes=2 : How long a row must have been waiting}';

    protected $description = 'Re-queue receipts and reversals the commission engine never finished';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $cutoff = Carbon::now()->subMinutes(max(0, (int) $this->option('minutes')));

        $receipts = $this->sweepReceipts($limit, $cutoff);
        $reversals = $this->sweepReversals(max(0, $limit - $receipts), $cutoff);

        $this->info(sprintf('re-queued %d receipt(s) and %d reversal(s)', $receipts, $reversals));

        return self::SUCCESS;
    }

    private function sweepReceipts(int $limit, Carbon $cutoff): int
    {
        if ($limit < 1) {
            return 0;
        }

        $rows = StudentFeePayment::query()
            ->whereIn('commission_state', $this->retryable())
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('paid_on')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

        foreach ($rows as $row) {
            ProcessStudentFeeCommission::dispatch((int) $row->id);
        }

        return $rows->count();
    }

    private function sweepReversals(int $limit, Carbon $cutoff): int
    {
        if ($limit < 1) {
            return 0;
        }

        $rows = PaymentReversal::query()
            ->whereIn('commission_state', $this->retryable())
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

        foreach ($rows as $row) {
            ProcessCommissionReversal::dispatch((int) $row->id);
        }

        return $rows->count();
    }

    /**
     * The two states worth retrying, named by the enum rather than written out — so a state added
     * later cannot be retried or skipped by accident.
     *
     * @return list<string>
     */
    private function retryable(): array
    {
        return array_values(array_map(
            static fn (CommissionProcessingState $state): string => $state->value,
            array_filter(
                CommissionProcessingState::cases(),
                static fn (CommissionProcessingState $state): bool => $state->isRetryable(),
            ),
        ));
    }
}
