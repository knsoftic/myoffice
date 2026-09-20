<?php

declare(strict_types=1);

namespace App\Console\Commands\Collaborator;

use App\Enums\CommissionProcessingState;
use App\Models\Institute\StudentFeePayment;
use App\Services\Collaborator\StudentCommissionService;
use App\Support\DateRange;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `commissions:evaluate` — the audited manual re-evaluation (§6.6 row 1, [D-IMP-4]).
 *
 * **On demand only. It is deliberately not scheduled**, and that is the whole design. The sweeper
 * retries work that never finished; this undoes a *decision*. A collaborator suspended in March and
 * reinstated in April has a month of receipts carrying `collaborator_inactive` with a reason on each
 * one, and turning those into money is a judgement somebody makes and signs for — not something a
 * cron does quietly at 02:00.
 *
 * So it requires a `--reason`, it writes an activity row per receipt naming that reason, and it
 * re-queues the row before running so G0 does not stop it. Paying twice remains impossible whatever
 * anybody types: `uq_cle_dedupe` and `uq_cle_source` are the guarantee, not this command's care.
 *
 * `--force` bypasses **G2 only** — "automatic commission is switched off" — because that is the one
 * guard whose whole purpose is to defer to a human decision. Every other guard is a fact about the
 * receipt, the partner or the rule, and forcing past those would be inventing money.
 */
#[AsCommand(name: 'commissions:evaluate')]
final class EvaluateCommissions extends Command
{
    protected $signature = 'commissions:evaluate
                            {--payment=* : Receipt ids to re-evaluate}
                            {--from= : Start of a value-date range}
                            {--to= : End of a value-date range}
                            {--reason= : Why these are being re-evaluated (required)}
                            {--force : Bypass the automatic-commission switch, and nothing else}
                            {--limit=500 : Receipts evaluated in one run}';

    protected $description = 'Re-evaluate named receipts for commission, with a reason on the record';

    public function handle(StudentCommissionService $engine): int
    {
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('A reason is required. This command undoes a decision somebody recorded, and the '
                .'reason is what the audit trail shows in its place.');

            return self::FAILURE;
        }

        $payments = $this->target();

        if ($payments === null) {
            return self::FAILURE;
        }

        if ($payments->isEmpty()) {
            $this->info('Nothing matched.');

            return self::SUCCESS;
        }

        $processed = 0;
        $skipped = 0;

        foreach ($payments as $payment) {
            $this->requeue($payment, $reason);

            $outcome = $engine->handlePayment($payment->refresh(), (bool) $this->option('force'));

            if ($outcome->isProcessed()) {
                $processed++;
                $this->line(sprintf('  <info>%s</info> earned %s',
                    (string) $payment->receipt_no, (string) $outcome->entry?->amount));
            } else {
                $skipped++;
                $this->line(sprintf('  <comment>%s</comment> still earns nothing: %s',
                    (string) $payment->receipt_no, (string) $outcome->sentence()));
            }
        }

        $this->info(sprintf('%d earned, %d still skipped', $processed, $skipped));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, StudentFeePayment>|null
     */
    private function target()
    {
        /** @var list<string> $ids */
        $ids = (array) $this->option('payment');
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        $limit = max(1, min(5000, (int) $this->option('limit')));

        if ($ids === [] && ($from === '' || $to === '')) {
            $this->error('Name the receipts: --payment=123 --payment=124, or a full --from= --to= range. '
                .'There is deliberately no "everything" form.');

            return null;
        }

        return StudentFeePayment::query()
            ->when($ids !== [], fn (Builder $q) => $q->whereIn('id', array_map('intval', $ids)))
            ->when($ids === [], fn (Builder $q) => DateRange::custom($from, $to)->applyDates($q, 'paid_on'))
            ->orderBy('paid_on')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Put the receipt back in the queue and record who asked.
     *
     * `$force` does not reach past G0, on purpose: undoing a settled outcome is this command's job and
     * this is the line where it happens, with an audit row beside it.
     */
    private function requeue(StudentFeePayment $payment, string $reason): void
    {
        if ($payment->commission_state === CommissionProcessingState::Processed) {
            return;
        }

        StudentFeePayment::allowDirectWrites(static function () use ($payment): void {
            $payment->forceFill([
                'commission_state' => CommissionProcessingState::Queued->value,
                'commission_skip_reason' => null,
                'commission_skip_detail' => null,
            ])->save();
        });

        activity()
            ->performedOn($payment)
            ->withProperties(['receipt_no' => (string) $payment->receipt_no, 'via' => 'commissions:evaluate'])
            ->event('commission.re_evaluated')
            ->log(sprintf('Re-evaluated for commission: %s', $reason))
            ->forceFill(['module' => 'collaborator_commissions', 'reason' => $reason])
            ->save();
    }
}
