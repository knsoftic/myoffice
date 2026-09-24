<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Enums\IntegrityCheckStatus;
use App\Enums\IntegrityCheckSuite;
use App\Models\Ops\IntegrityCheckRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Runs an integrity suite and records that it ran (phase-24-25 §6.6, §110).
 *
 * **It delegates, and that is the design rather than a shortcut.** The two suites that matter most
 * — `constraints` and `wallet` — are already implemented, by the spine and by Phase 12, and they
 * are the implementations the money invariants were written against. Reimplementing them here would
 * produce a second opinion, and a second opinion about whether the ledger balances is worse than
 * none: when the two disagree, nobody knows which to believe.
 *
 * So this service is a **recorder**. It runs the command a suite names, reads its exit code, and
 * writes the row. The verdict belongs to the command; the evidence belongs here.
 *
 * **`wallet` is always run read-only.** `collaborators:reconcile-wallets` can repair, and a proof
 * that silently fixes what it finds is not a proof — it is a process that makes the same problem
 * invisible every night. The `--dry-run` in `IntegrityCheckSuite::commandArguments()` is load
 * bearing, and this service passes it rather than trusting the caller.
 *
 * **A run is recorded even when the command throws.** A suite that crashed is a finding, and the
 * shape of failure this table exists to catch is "the nightly sweep stopped producing rows and
 * nobody noticed".
 */
final class IntegrityCheckService
{
    /**
     * Exit codes a command may return: 0 pass, 1 warning, 2 findings.
     *
     * Anything else — a PHP fatal, a missing command, 255 — is treated as a failure rather than
     * mapped, because an unexpected exit code is precisely the case where a guess would be wrong.
     */
    private const KNOWN_EXIT_CODES = [0, 1, 2];

    /**
     * Run every suite of `--suite=all`, sharing one `run_uuid`.
     *
     * @param  array{scope?: string|null, trigger?: string, command?: string|null}  $context
     * @return list<IntegrityCheckRun>
     */
    public function runAll(array $context = []): array
    {
        $runUuid = (string) Str::ulid();
        $runs = [];

        foreach (IntegrityCheckSuite::all() as $suite) {
            $runs[] = $this->run($suite, $context + ['run_uuid' => $runUuid]);
        }

        return $runs;
    }

    /**
     * Run one suite and record it.
     *
     * @param  array{run_uuid?: string, scope?: string|null, trigger?: string, command?: string|null}  $context
     */
    public function run(IntegrityCheckSuite $suite, array $context = []): IntegrityCheckRun
    {
        $startedAt = Carbon::now();
        $started = microtime(true);

        $output = new BufferedOutput;
        $exitCode = null;
        $threw = null;

        try {
            $exitCode = Artisan::call($suite->command(), $suite->commandArguments(), $output);
        } catch (Throwable $exception) {
            // A suite that crashed is a finding. Recorded rather than rethrown, so `--suite=all`
            // still runs the remaining eight — a broken performance check must not stop the wallet
            // reconciliation from being proved that night.
            $threw = $exception;
        }

        $text = $output->fetch();
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $findings = $threw === null
            ? $this->findingsFrom($suite, $text, (int) $exitCode)
            : [$this->crashFinding($suite, $threw)];

        $status = $threw === null
            ? $this->statusFrom($exitCode)
            : IntegrityCheckStatus::Failed;

        $counts = $this->countsFrom($status, $findings);

        return $this->record($suite, $status, $findings, $counts, [
            'run_uuid' => $context['run_uuid'] ?? (string) Str::ulid(),
            'scope' => $context['scope'] ?? null,
            'trigger' => $context['trigger'] ?? 'scheduled',
            'command' => $context['command'] ?? $this->commandLine($suite),
            'started_at' => $startedAt,
            'duration_ms' => $durationMs,
            'exit_code' => $threw === null ? (int) $exitCode : 2,
        ]);
    }

    /**
     * Write the row.
     *
     * @param  list<array<string, mixed>>  $findings
     * @param  array{total: int, passed: int, warned: int, failed: int}  $counts
     * @param  array<string, mixed>  $context
     */
    public function record(
        IntegrityCheckSuite $suite,
        IntegrityCheckStatus $status,
        array $findings,
        array $counts,
        array $context = [],
    ): IntegrityCheckRun {
        $truncated = count($findings) > IntegrityCheckRun::MAX_FINDINGS;

        return IntegrityCheckRun::create([
            'uuid' => (string) Str::ulid(),
            'run_uuid' => $context['run_uuid'] ?? (string) Str::ulid(),
            'suite' => $suite,
            'status' => $status,
            'scope' => $context['scope'] ?? null,
            'checks_total' => $counts['total'],
            'checks_passed' => $counts['passed'],
            'checks_warned' => $counts['warned'],
            'checks_failed' => $counts['failed'],
            'findings' => $truncated
                ? array_slice($findings, 0, IntegrityCheckRun::MAX_FINDINGS)
                : ($findings === [] ? null : $findings),
            'findings_truncated' => $truncated,
            'started_at' => $context['started_at'] ?? Carbon::now(),
            'finished_at' => Carbon::now(),
            'duration_ms' => $context['duration_ms'] ?? null,
            'triggered_by' => $this->trigger($context['trigger'] ?? null),
            'command' => $context['command'] ?? null,
            'exit_code' => $context['exit_code'] ?? $status->exitCode(),
            'app_version' => $this->appVersion(),
        ]);
    }

    /**
     * The worst verdict among a set of runs.
     *
     * What `integrity:verify --suite=all` exits with: one failed suite makes the whole invocation a
     * failure, whatever the other eight said.
     *
     * @param  iterable<IntegrityCheckRun>  $runs
     */
    public function worst(iterable $runs): IntegrityCheckStatus
    {
        $statuses = [];

        foreach ($runs as $run) {
            $statuses[] = $run->status;
        }

        return IntegrityCheckStatus::worst($statuses);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function statusFrom(?int $exitCode): IntegrityCheckStatus
    {
        if (! in_array($exitCode, self::KNOWN_EXIT_CODES, true)) {
            return IntegrityCheckStatus::Failed;
        }

        return match ($exitCode) {
            0 => IntegrityCheckStatus::Passed,
            1 => IntegrityCheckStatus::Warning,
            default => IntegrityCheckStatus::Failed,
        };
    }

    /**
     * Findings parsed out of a command's output.
     *
     * Commands that speak JSON are read as JSON; the rest contribute their output as a single
     * finding when they did not pass, because a wall of text a human wrote is still the best
     * description of what went wrong and discarding it would leave a bare exit code.
     *
     * @return list<array<string, mixed>>
     */
    private function findingsFrom(IntegrityCheckSuite $suite, string $output, int $exitCode): array
    {
        if ($exitCode === 0) {
            return [];
        }

        $decoded = json_decode(trim($output), true);

        if (is_array($decoded) && isset($decoded['findings']) && is_array($decoded['findings'])) {
            /** @var list<array<string, mixed>> $findings */
            $findings = array_values(array_filter($decoded['findings'], 'is_array'));

            return $findings;
        }

        return [[
            'code' => $suite->value.'.output',
            'severity' => $exitCode >= 2 ? 'failed' : 'warning',
            'subject' => $suite->command(),
            'expected' => 'exit code 0',
            // Clamped: a command that printed a megabyte of diff must not put a megabyte in a JSON
            // column that a screen then tries to render.
            'actual' => mb_substr(trim($output), 0, 4000),
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function crashFinding(IntegrityCheckSuite $suite, Throwable $exception): array
    {
        return [
            'code' => $suite->value.'.crashed',
            'severity' => 'failed',
            'subject' => $suite->command(),
            'expected' => 'the suite to run',
            'actual' => get_class($exception).': '.mb_substr($exception->getMessage(), 0, 500),
        ];
    }

    /**
     * Counts for a run whose command reported only a verdict.
     *
     * A delegated suite is one check from this table's point of view — it either proved what it set
     * out to prove or it did not. Inventing a count from the number of findings would make
     * `checks_total` mean something different per suite, and the CHECK constraint on the row
     * (passed + warned + failed = total) exists so that cannot happen quietly.
     *
     * @param  list<array<string, mixed>>  $findings
     * @return array{total: int, passed: int, warned: int, failed: int}
     */
    private function countsFrom(IntegrityCheckStatus $status, array $findings): array
    {
        return match ($status) {
            IntegrityCheckStatus::Passed => ['total' => 1, 'passed' => 1, 'warned' => 0, 'failed' => 0],
            IntegrityCheckStatus::Warning => ['total' => 1, 'passed' => 0, 'warned' => 1, 'failed' => 0],
            IntegrityCheckStatus::Failed => ['total' => 1, 'passed' => 0, 'warned' => 0, 'failed' => 1],
        };
    }

    private function trigger(?string $trigger): string
    {
        return in_array($trigger, IntegrityCheckRun::TRIGGERS, true) ? $trigger : 'scheduled';
    }

    /**
     * The command line, for somebody reproducing this run a month later.
     */
    private function commandLine(IntegrityCheckSuite $suite): string
    {
        $arguments = [];

        foreach ($suite->commandArguments() as $name => $value) {
            $arguments[] = $value === true ? $name : $name.'='.$value;
        }

        return mb_substr(
            trim('php artisan '.$suite->command().' '.implode(' ', $arguments)),
            0,
            128,
        );
    }

    /**
     * The deployed version, so a restored archive says which release produced the run.
     */
    private function appVersion(): ?string
    {
        try {
            $version = setting('ops.app_version');
        } catch (Throwable) {
            return null;
        }

        return is_string($version) && $version !== '' ? mb_substr($version, 0, 32) : null;
    }

    /**
     * Whether a person is behind this run.
     *
     * Unused by `record()` — `Blameable` fills `created_by` — but kept as the honest answer to "was
     * this scheduled", which `triggered_by` defaults to and a caller may want to check.
     */
    public function isInteractive(): bool
    {
        return Auth::hasUser();
    }
}
