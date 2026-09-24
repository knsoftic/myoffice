<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Enums\IntegrityCheckStatus;
use App\Enums\IntegrityCheckSuite;
use App\Models\Ops\IntegrityCheckRun;
use App\Services\Ops\IntegrityCheckService;
use Illuminate\Console\Command;

/**
 * `integrity:verify` — run the proofs, and record that they ran (phase-24-25 §6.6, HD-10).
 *
 * **It proves nothing itself.** Every suite is a command that already exists and was written by the
 * phase that owns the invariant: `financial:verify-constraints` is the spine's,
 * `collaborators:reconcile-wallets` is Phase 12's. A second implementation of "does the ledger
 * balance" would be a second opinion, and when two opinions about the money disagree nobody knows
 * which to believe.
 *
 * What this adds is the **record**. A nightly sweep that quietly stopped running looks exactly like
 * a nightly sweep that keeps finding nothing, and the only difference is a row.
 *
 * **The financial suites run first**, so a wallet that does not reconcile is the first thing on
 * screen rather than the thing after six screens of green.
 *
 * **`wallet` is always `--dry-run`.** A proof that repairs what it finds makes the same problem
 * invisible every night; repairing is a separate, deliberate act.
 */
final class IntegrityVerify extends Command
{
    protected $signature = 'integrity:verify
                            {--suite= : One suite, or omit for all}
                            {--scope= : What was checked — all, collaborator:12, table:student_fees}
                            {--json : Machine-readable output}';

    protected $description = 'Run the integrity suites and write their verdicts to integrity_check_runs.';

    public function handle(IntegrityCheckService $service): int
    {
        $requested = $this->option('suite');

        if ($requested !== null && IntegrityCheckSuite::tryFrom((string) $requested) === null) {
            $this->error(sprintf(
                'Unknown suite [%s]. Known suites: %s.',
                $requested,
                implode(', ', array_map(
                    static fn (IntegrityCheckSuite $suite): string => $suite->value,
                    IntegrityCheckSuite::cases(),
                )),
            ));

            return 2;
        }

        $context = [
            'scope' => $this->option('scope') === null ? 'all' : (string) $this->option('scope'),
            'trigger' => 'manual',
        ];

        $runs = $requested === null
            ? $service->runAll($context)
            : [$service->run(IntegrityCheckSuite::from((string) $requested), $context)];

        $status = $service->worst($runs);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $status->value,
                'run_uuid' => $runs[0]->run_uuid ?? null,
                'suites' => array_map(static fn (IntegrityCheckRun $run): array => [
                    'suite' => $run->suite->value,
                    'status' => $run->status->value,
                    'duration_ms' => $run->duration_ms,
                    'findings' => $run->findings ?? [],
                ], $runs),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $status->exitCode();
        }

        $this->render($runs, $status);

        return $status->exitCode();
    }

    /**
     * @param  list<IntegrityCheckRun>  $runs
     */
    private function render(array $runs, IntegrityCheckStatus $status): void
    {
        $rows = [];

        foreach ($runs as $run) {
            $marker = match ($run->status) {
                IntegrityCheckStatus::Passed => '<fg=green>passed</>',
                IntegrityCheckStatus::Warning => '<fg=yellow>warning</>',
                IntegrityCheckStatus::Failed => '<fg=red>failed</>',
            };

            $rows[] = [
                $marker,
                $run->suite->value,
                $run->suite->isFinancial() ? 'money' : '',
                sprintf('%.1fs', ($run->duration_ms ?? 0) / 1000),
                $run->blocksGoLive() ? '<fg=red>blocks go-live</>' : '',
                mb_substr((string) ($run->findings[0]['actual'] ?? ''), 0, 44),
            ];
        }

        $this->newLine();
        $this->table(['', 'suite', '', 'took', '', 'first finding'], $rows);

        $blocking = array_filter($runs, static fn (IntegrityCheckRun $run): bool => $run->blocksGoLive());

        $this->newLine();

        $line = sprintf(
            'integrity:verify — %s. %d suite(s) run, %d blocking.',
            $status->label(),
            count($runs),
            count($blocking),
        );

        match ($status) {
            IntegrityCheckStatus::Passed => $this->info($line),
            IntegrityCheckStatus::Warning => $this->warn($line),
            IntegrityCheckStatus::Failed => $this->error($line),
        };

        if ($runs !== []) {
            $this->line(sprintf('  run %s — the evidence is in integrity_check_runs.', $runs[0]->run_uuid));
        }
    }
}
