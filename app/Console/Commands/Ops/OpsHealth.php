<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Ops\SystemHealthService;
use Illuminate\Console\Command;

/**
 * `ops:health` — the command a monitoring agent calls (phase-24-25 §6.6, §6.11).
 *
 * **The exit code is the contract, not the table.** 0 ok, 1 degraded, 2 failed — three states
 * rather than two, so a cron wrapper can page on a dead queue and merely log an unverified backup.
 * A command that collapsed both into "non-zero" would train whoever gets the page to ignore it.
 *
 * **It computes nothing itself** (HD-3). Every reading comes from {@see SystemHealthService}, which
 * the `/health` endpoint, the admin screen and Phase 2's `SystemHealthWidget` also read. The
 * failure this prevents is the familiar one: the screen says green, the console says red, and the
 * argument about which is right happens in the middle of the incident.
 *
 * **`--deep` belongs to the console and only to the console.** It scans the migrations directory
 * and asks the volume for its size — work nobody wants done on a per-minute HTTP poll, and exactly
 * what the deploy runbook (§6.11 step 1) wants before it starts.
 */
final class OpsHealth extends Command
{
    protected $signature = 'ops:health
                            {--json : Machine-readable output}
                            {--deep : Also run the expensive probes (pending migrations, disk space)}';

    protected $description = 'Report every health probe, and exit 0 / 1 / 2 for ok / degraded / failed.';

    public function handle(SystemHealthService $health): int
    {
        $snapshot = $health->snapshot((bool) $this->option('deep'));

        if ($this->option('json')) {
            // The full snapshot, prose included: this output goes to somebody on the machine, not
            // to the unauthenticated endpoint, which has its own narrower payload.
            $this->line((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $health->exitCodeFor($snapshot['status']);
        }

        $this->render($snapshot);

        return $health->exitCodeFor($snapshot['status']);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function render(array $snapshot): void
    {
        $rows = [];

        /** @var array<string, array<string, mixed>> $checks */
        $checks = $snapshot['checks'];

        foreach ($checks as $check) {
            $rows[] = [
                $this->marker((string) $check['status']),
                (string) $check['key'],
                $this->measurement($check),
                $check['threshold'] === null ? '' : 'max '.$check['threshold'],
                mb_substr((string) ($check['detail'] ?? ''), 0, 60),
            ];
        }

        $this->newLine();
        $this->table(['', 'probe', 'measured', 'threshold', 'detail'], $rows);
        $this->newLine();

        $line = sprintf(
            'ops:health — %s. %d ok, %d degraded, %d failed%s.',
            mb_strtoupper((string) $snapshot['status']),
            $snapshot['counts'][SystemHealthService::STATUS_OK],
            $snapshot['counts'][SystemHealthService::STATUS_DEGRADED],
            $snapshot['counts'][SystemHealthService::STATUS_FAILED],
            $snapshot['deep'] ? ' (deep)' : '',
        );

        match ((string) $snapshot['status']) {
            SystemHealthService::STATUS_OK => $this->info($line),
            SystemHealthService::STATUS_DEGRADED => $this->warn($line),
            default => $this->error($line),
        };

        $this->line(sprintf(
            '  version %s · measured %s',
            $snapshot['app_version'] ?? 'unstamped',
            (string) $snapshot['generated_at'],
        ));

        // The remediation lines are the reason somebody ran this at 3 a.m.; printing them under the
        // table means they never have to go and look up what to do about an amber dot.
        foreach ($checks as $check) {
            if ($check['status'] !== SystemHealthService::STATUS_OK && $check['remediation'] !== null) {
                $this->line(sprintf('  <fg=yellow>%s</> → %s', $check['key'], (string) $check['remediation']));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $check
     */
    private function measurement(array $check): string
    {
        $value = $check['value'];

        if ($value === null) {
            // A dash, never a zero. "Could not be measured" and "measured zero" are different
            // claims and only one of them is good news.
            return '—';
        }

        if (is_bool($value)) {
            $value = $value ? 'yes' : 'no';
        }

        return trim($value.' '.(string) ($check['unit'] ?? ''));
    }

    private function marker(string $status): string
    {
        return match ($status) {
            SystemHealthService::STATUS_OK => '<fg=green>ok</>',
            SystemHealthService::STATUS_DEGRADED => '<fg=yellow>degraded</>',
            default => '<fg=red>failed</>',
        };
    }
}
