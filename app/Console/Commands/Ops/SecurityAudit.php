<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Enums\IntegrityCheckStatus;
use App\Enums\IntegrityCheckSuite;
use App\Services\Ops\IntegrityCheckService;
use App\Support\Ops\SecurityAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * `security:audit` — everything about this application's security that a machine can decide
 * (phase-24-25 §6.6).
 *
 * **Static checks and runtime probes, and the split matters.** The static half reads the code and
 * needs nothing else, so it runs in CI on a checkout with no database and no server — where a
 * finding is a failing pull request rather than a line in tomorrow's report. The runtime half needs
 * a booted application and answers questions the source cannot: does a response actually carry the
 * headers, does `composer audit` know about a package published last week.
 *
 * **Severity decides the exit code, and the exit code is the contract CI reads.** 0 clean, 1
 * warnings, 2 findings. A warning is something a person should look at; a finding is a door that is
 * open. Collapsing the two would mean either waking somebody for a file mode or shipping with an
 * unguarded write route.
 *
 * Every run writes an `integrity_check_runs` row, because "the nightly audit stopped finding
 * things" and "the nightly audit stopped running" look identical without one.
 */
final class SecurityAudit extends Command
{
    protected $signature = 'security:audit
                            {--suite=all : static, runtime, dependencies, or all}
                            {--quiet-run : Print only the summary line}
                            {--no-record : Do not write an integrity_check_runs row}
                            {--json : Machine-readable output}';

    protected $description = 'Audit routes, models, queries, views, headers, the environment and the dependency tree.';

    public function handle(SecurityAuditor $auditor, IntegrityCheckService $integrity): int
    {
        $suite = (string) $this->option('suite');

        $findings = [];

        if (in_array($suite, ['all', 'static'], true)) {
            $findings = array_merge($findings, $auditor->audit());
        }

        if (in_array($suite, ['all', 'runtime', 'isolation'], true)) {
            $findings = array_merge($findings, $this->auditRuntime());
        }

        if (in_array($suite, ['all', 'dependencies'], true)) {
            $findings = array_merge($findings, $this->auditDependencies());
        }

        $failed = $this->countBy($findings, 'failed');
        $warned = $this->countBy($findings, 'warning');
        $status = IntegrityCheckStatus::fromCounts($failed, $warned);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $status->value,
                'failed' => $failed,
                'warned' => $warned,
                'findings' => $findings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->report($findings, $status, $failed, $warned);
        }

        if (! $this->option('no-record')) {
            $this->record($integrity, $status, $findings, $failed, $warned);
        }

        return $status->exitCode();
    }

    /*
    |--------------------------------------------------------------------------
    | Runtime probes
    |--------------------------------------------------------------------------
    */

    /**
     * Questions only a booted application can answer.
     *
     * @return list<array<string, mixed>>
     */
    private function auditRuntime(): array
    {
        $findings = [];

        // The headers, on a real response. A middleware registered in the wrong place still passes
        // a unit test; only a round trip proves it is in the stack.
        $findings = array_merge($findings, $this->auditHeaders());

        // A seeded password still in use is the single most common way a deployed system is
        // entered, and it is invisible to every static check.
        $findings = array_merge($findings, $this->auditSeededPasswords());

        return $findings;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditHeaders(): array
    {
        $expected = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => null,
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Permissions-Policy' => null,
        ];

        try {
            $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
            $response = $kernel->handle(\Illuminate\Http\Request::create('/login', 'GET'));
        } catch (Throwable $exception) {
            return [$this->finding(
                'headers.unreachable',
                'warning',
                '/login',
                'a response to sample',
                get_class($exception).': '.mb_substr($exception->getMessage(), 0, 200),
            )];
        }

        $findings = [];

        foreach ($expected as $header => $value) {
            $actual = $response->headers->get($header);

            if ($actual === null) {
                $findings[] = $this->finding(
                    'headers.missing',
                    'failed',
                    $header,
                    $value ?? 'any value',
                    'absent from a real response',
                );

                continue;
            }

            if ($value !== null && $actual !== $value) {
                $findings[] = $this->finding('headers.wrong', 'failed', $header, $value, $actual);
            }
        }

        // The policy, in whichever form is configured.
        if (! $response->headers->has('Content-Security-Policy')
            && ! $response->headers->has('Content-Security-Policy-Report-Only')) {
            $findings[] = $this->finding(
                'headers.csp',
                'failed',
                'Content-Security-Policy',
                'a policy, enforcing or report-only',
                'no policy is sent',
            );
        }

        // Report-only is correct until the report log is clean, and a reminder rather than a fault.
        if ($response->headers->has('Content-Security-Policy-Report-Only')
            && app()->environment('production')) {
            $findings[] = $this->finding(
                'headers.csp_report_only',
                'warning',
                'security.csp_report_only',
                'enforcing, once the report log is clean',
                'report-only in production — the policy is observing, not blocking',
            );
        }

        return $findings;
    }

    /**
     * Accounts still using a password the seeders set.
     *
     * Checked by hashing the known seed values against each account rather than by looking for a
     * marker column: a marker can be cleared, and what matters is whether the password still works.
     *
     * @return list<array<string, mixed>>
     */
    private function auditSeededPasswords(): array
    {
        $candidates = ['password', 'Password123!', 'secret', 'admin', '12345678'];
        $findings = [];

        try {
            $users = \App\Models\User::query()->get(['id', 'email', 'password']);
        } catch (Throwable) {
            // No database — this is the static half's territory and not a finding.
            return [];
        }

        foreach ($users as $user) {
            foreach ($candidates as $candidate) {
                if (! \Illuminate\Support\Facades\Hash::check($candidate, (string) $user->password)) {
                    continue;
                }

                $findings[] = $this->finding(
                    'password.seeded',
                    app()->environment('production') ? 'failed' : 'warning',
                    // Not the address: a finding travels into a log and a notification, and naming
                    // the account that can be entered is naming the way in.
                    'user #'.$user->getKey(),
                    'a password the operator chose',
                    'still using a value the seeders set',
                );

                break;
            }
        }

        return $findings;
    }

    /*
    |--------------------------------------------------------------------------
    | Dependencies
    |--------------------------------------------------------------------------
    */

    /**
     * `composer audit` and `npm audit`.
     *
     * A failure to *run* either is a warning, not a finding: an offline build machine is not a
     * vulnerability, and reporting one as a security failure is how a team learns to ignore this
     * command.
     *
     * @return list<array<string, mixed>>
     */
    private function auditDependencies(): array
    {
        $findings = [];

        foreach ([
            'composer' => ['composer', 'audit', '--format=json', '--no-interaction'],
            'npm' => ['npm', 'audit', '--omit=dev', '--json'],
        ] as $manager => $command) {
            try {
                $result = Process::path(base_path())->timeout(120)->run($command);
            } catch (Throwable $exception) {
                $findings[] = $this->finding(
                    'deps.unavailable',
                    'warning',
                    $manager,
                    'an audit result',
                    mb_substr($exception->getMessage(), 0, 160),
                );

                continue;
            }

            if ($result->successful()) {
                continue;
            }

            $count = $this->advisoryCount($result->output());

            $findings[] = $this->finding(
                'deps.advisory',
                'failed',
                $manager,
                'no known advisories',
                $count === null
                    ? mb_substr(trim($result->output().$result->errorOutput()), 0, 300)
                    : sprintf('%d advisor%s', $count, $count === 1 ? 'y' : 'ies'),
            );
        }

        return $findings;
    }

    /**
     * How many advisories an audit reported, or null when the output is not the shape expected.
     */
    private function advisoryCount(string $output): ?int
    {
        $decoded = json_decode(trim($output), true);

        if (! is_array($decoded)) {
            return null;
        }

        if (isset($decoded['advisories']) && is_array($decoded['advisories'])) {
            return count($decoded['advisories']);
        }

        // npm's shape.
        if (isset($decoded['metadata']['vulnerabilities']['total'])) {
            return (int) $decoded['metadata']['vulnerabilities']['total'];
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    private function report(array $findings, IntegrityCheckStatus $status, int $failed, int $warned): void
    {
        if (! $this->option('quiet-run') && $findings !== []) {
            $this->newLine();

            $rows = [];

            foreach ($findings as $finding) {
                $rows[] = [
                    $finding['severity'] === 'failed' ? '<fg=red>failed</>' : '<fg=yellow>warning</>',
                    $finding['code'],
                    mb_substr((string) $finding['subject'], 0, 46),
                    mb_substr((string) $finding['actual'], 0, 58),
                ];
            }

            $this->table(['', 'check', 'subject', 'what was found'], $rows);
        }

        $this->newLine();

        $line = sprintf(
            'security:audit — %s: %d finding(s), %d warning(s).',
            $status->label(),
            $failed,
            $warned,
        );

        match ($status) {
            IntegrityCheckStatus::Passed => $this->info($line),
            IntegrityCheckStatus::Warning => $this->warn($line),
            IntegrityCheckStatus::Failed => $this->error($line),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    private function record(
        IntegrityCheckService $integrity,
        IntegrityCheckStatus $status,
        array $findings,
        int $failed,
        int $warned,
    ): void {
        try {
            $integrity->record(
                IntegrityCheckSuite::Security,
                $status,
                $findings,
                [
                    'total' => max(1, count($findings)),
                    'passed' => $findings === [] ? 1 : 0,
                    'warned' => $warned,
                    'failed' => $failed,
                ],
                [
                    'scope' => (string) $this->option('suite'),
                    'trigger' => $this->laravel->runningInConsole() ? 'manual' : 'scheduled',
                    'command' => 'php artisan security:audit --suite='.$this->option('suite'),
                    'exit_code' => $status->exitCode(),
                ],
            );
        } catch (Throwable $exception) {
            // A recording failure must not change the verdict — the point of the command is the
            // answer, and the row is the evidence of it.
            $this->warn('Could not record the run: '.$exception->getMessage());
        }
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    private function countBy(array $findings, string $severity): int
    {
        return count(array_filter(
            $findings,
            static fn (array $finding): bool => ($finding['severity'] ?? '') === $severity,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(string $code, string $severity, string $subject, string $expected, string $actual): array
    {
        return compact('code', 'severity', 'subject', 'expected', 'actual');
    }
}
