<?php

declare(strict_types=1);

namespace App\Console\Commands\Collaborator;

use App\Models\Collaborator\Collaborator;
use App\Services\Collaborator\CommissionReconciliationService;
use App\Support\Money;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `collaborators:reconcile-wallets` — proves every wallet still equals its ledger (spine §6.5.6).
 *
 * §50 asks that no total rely on a stored balance. The wallet is a stored balance, so the promise is
 * kept a different way: the figure is re-derivable from the ledger, and this is the dated record that
 * it was re-derived and agreed. A reconciliation table with rows only on the bad days would prove
 * nothing about the good ones, so a row is written every run either way.
 *
 * **It does not repair unless asked** (§6.5.4). A silent auto-repair would erase the evidence of
 * whatever caused the drift, and the cause is the interesting part — a cache that fell behind once
 * will fall behind again. Drift is reported, the screens switch to the derived figures behind a
 * banner, and a human presses Recalculate. Structural failures (R2–R7) are never repaired by anything:
 * the ledger itself does not hold together, and the only honest fix is a person posting a
 * `manual_adjustment` with a written reason.
 *
 * The scheduled run is skipped entirely when `finance.wallet_reconcile_enabled` is false; `--force`
 * ignores that, because somebody typing this at a prompt has already decided.
 */
#[AsCommand(name: 'collaborators:reconcile-wallets')]
final class ReconcileWallets extends Command
{
    protected $signature = 'collaborators:reconcile-wallets
        {--collaborator= : Reconcile one collaborator by id, instead of every one}
        {--repair : Rewrite a drifted cache from the ledger. Never touches a ledger row, and refuses on a structural failure}
        {--run-type=scheduled : What to record this run as}
        {--force : Run even when finance.wallet_reconcile_enabled is off}';

    protected $description = 'Check every collaborator wallet against its ledger and record the proof';

    public function handle(CommissionReconciliationService $reconciler): int
    {
        if (! $this->option('force') && ! (bool) setting('finance.wallet_reconcile_enabled', true)) {
            $this->line('finance.wallet_reconcile_enabled is off — nothing was checked.');

            return self::SUCCESS;
        }

        $collaborator = null;

        if ($this->option('collaborator') !== null) {
            $collaborator = Collaborator::withTrashed()->find((int) $this->option('collaborator'));

            if ($collaborator === null) {
                $this->error(sprintf('There is no collaborator #%s.', (string) $this->option('collaborator')));

                return self::FAILURE;
            }
        }

        $reports = $reconciler->run(
            $collaborator,
            (string) $this->option('run-type'),
            (bool) $this->option('repair'),
        );

        $drifted = [];
        $failed = [];
        $drift = Money::ZERO;

        foreach ($reports as $report) {
            if ($report->passed()) {
                continue;
            }

            $drift = Money::add($drift, $report->driftTotal);

            if ($report->structural() !== []) {
                $failed[] = $report;

                continue;
            }

            $drifted[] = $report;
        }

        foreach ([...$failed, ...$drifted] as $report) {
            $this->line(($report->structural() !== [] ? '  FAILED  ' : '  drift   ').$report->summary());
        }

        $this->info(sprintf(
            '%d wallet(s) checked, %d clean, %d drifted, %d structurally broken, %s of drift in total%s',
            count($reports),
            count($reports) - count($drifted) - count($failed),
            count($drifted),
            count($failed),
            Money::format($drift),
            $this->option('repair') ? ' (caches repaired where it was safe to)' : '',
        ));

        // A structural failure is the one thing here that should make a scheduler shout: it means the
        // ledger disagrees with itself, which no amount of recomputing will fix.
        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
