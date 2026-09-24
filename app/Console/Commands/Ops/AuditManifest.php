<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Support\Ops\ManifestAuditor;
use Illuminate\Console\Command;

/**
 * `audit:manifest` — the drift gate (phase-24-25 §6.1).
 *
 * **This is a required step of every phase's definition of done from Phase 24 onward.** The four
 * manifests are the data providers for every sweep in §11, so a route with no row is not merely
 * untested: it is a hole in a matrix that claims to be complete. HD-4 puts it plainly —
 * authorization is proven by enumeration, not by sampling.
 *
 * **It reports drift in both directions, and the second is the one people forget.** A route that
 * was deleted leaves a manifest row that still passes every sweep, quietly asserting a guarantee
 * about a screen nobody can open. That looks like coverage, which makes it worse than a gap.
 *
 * Exit codes are the contract CI reads: **0** clean, **1** warnings only, **2** drift.
 */
final class AuditManifest extends Command
{
    protected $signature = 'audit:manifest
                            {--check : Fail on drift. The CI gate.}
                            {--coverage : Print per-phase manifest coverage}
                            {--json : Machine-readable output}';

    protected $description = 'Check the four manifests against the live routes, schema and Form Requests.';

    public function handle(ManifestAuditor $auditor): int
    {
        if ($this->option('coverage')) {
            return $this->printCoverage($auditor);
        }

        $findings = $auditor->audit();

        if ($this->option('json')) {
            $this->line((string) json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($findings);
        }

        $drift = 0;
        $warnings = 0;

        foreach ($findings as $finding) {
            $drift += count($finding['missing']) + count($finding['orphaned']);
            $warnings += count($finding['warnings']);
        }

        if (! $this->option('json')) {
            $this->newLine();

            if ($drift === 0 && $warnings === 0) {
                $this->components->info('Every route, index and upload is accounted for.');
            } elseif ($drift === 0) {
                $this->components->warn(sprintf('%d warning(s), no drift.', $warnings));
            } else {
                $this->components->error(sprintf('%d drift finding(s) and %d warning(s).', $drift, $warnings));
            }
        }

        // Without `--check` this is a report, not a gate: somebody running it to see where they
        // stand should not have it exit non-zero and stop their script.
        if (! $this->option('check')) {
            return self::SUCCESS;
        }

        return match (true) {
            $drift > 0 => 2,
            $warnings > 0 => 1,
            default => self::SUCCESS,
        };
    }

    /**
     * @param  array<string, array{missing: list<string>, orphaned: list<string>, warnings: list<string>}>  $findings
     */
    private function render(array $findings): void
    {
        $titles = [
            'screen' => 'Screens (every GET that renders a page)',
            'route' => 'Route guards (every named route)',
            'index' => 'Indexes (the manifest, plus every foreign key)',
            'upload' => 'Uploads (every endpoint that accepts a file)',
        ];

        foreach ($findings as $kind => $finding) {
            $total = count($finding['missing']) + count($finding['orphaned']) + count($finding['warnings']);

            $this->newLine();
            $this->line(sprintf(
                '  <options=bold>%s</> — %s',
                $titles[$kind] ?? $kind,
                $total === 0 ? '<fg=green>clean</>' : sprintf('<fg=yellow>%d finding(s)</>', $total),
            ));

            $this->listOut('no manifest row', $finding['missing'], 'red');
            $this->listOut('row with no live route/table', $finding['orphaned'], 'yellow');
            $this->listOut('worth a look', $finding['warnings'], 'gray');
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function listOut(string $label, array $lines, string $colour): void
    {
        if ($lines === []) {
            return;
        }

        $this->line(sprintf('    <fg=%s>%s (%d):</>', $colour, $label, count($lines)));

        // Capped, because a first run against twenty-three phases can produce hundreds and a wall
        // of them is not a report. The count above is the honest number; `--json` has them all.
        foreach (array_slice($lines, 0, 25) as $line) {
            $this->line('      · '.$line);
        }

        if (count($lines) > 25) {
            $this->line(sprintf('      … and %d more (use --json for the full list)', count($lines) - 25));
        }
    }

    private function printCoverage(ManifestAuditor $auditor): int
    {
        $rows = [];

        foreach ($auditor->coverage() as $phase => $counts) {
            $rows[] = [
                $phase === 0 ? '(unattributed)' : 'Phase '.$phase,
                $counts['screen'] ?? 0,
                $counts['route'] ?? 0,
                $counts['upload'] ?? 0,
            ];
        }

        $this->table(['Phase', 'Screens', 'Routes', 'Uploads'], $rows);

        // A row with no owning phase cannot be chased to anybody, which is the whole point of the
        // column: §13.2 makes the manifest rows an ask of every phase by name.
        $unattributed = collect($rows)->firstWhere(0, '(unattributed)');

        if ($unattributed !== null) {
            $this->components->warn(
                'Some rows name no owner_phase. A manifest row nobody owns is a row nobody updates.'
            );
        }

        return self::SUCCESS;
    }
}
