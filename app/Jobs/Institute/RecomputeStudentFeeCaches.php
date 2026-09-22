<?php

declare(strict_types=1);

namespace App\Jobs\Institute;

use App\Models\Institute\StudentFee;
use App\Services\Institute\StudentFeeService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-derive one charge's caches from its rows (phase-18 §10.2).
 *
 * **The repair, run deliberately by somebody who has looked** — never automatically.
 * `fees:verify-plan-integrity` finds drift and reports it; this is what fixes it afterwards. The
 * separation is the spine's reconciliation discipline: a cache the nightly job silently repairs is a
 * bug that never gets found, because the drift *is* the evidence of whatever wrote past the service.
 *
 * **It rewrites caches only.** Not a discount, not a receipt, not an installment amount, not a ledger
 * row. Every value it writes is a function of rows that already exist, so running it twice changes
 * nothing and running it on a healthy charge is a no-op.
 */
final class RecomputeStudentFeeCaches implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $feeId,
    ) {
        $this->afterCommit();
        $this->onQueue('financial');
    }

    public function uniqueId(): string
    {
        return 'fee-caches:'.$this->feeId;
    }

    public function handle(StudentFeeService $fees): void
    {
        $charge = StudentFee::query()->whereKey($this->feeId)->first();

        if ($charge === null) {
            // A charge deleted between the report and the repair is not an error: there is nothing
            // left whose caches could be wrong.
            return;
        }

        $fees->recomputeCaches($charge);

        foreach ($charge->installments()->get() as $line) {
            $fees->recomputeInstallment($line);
        }
    }
}
