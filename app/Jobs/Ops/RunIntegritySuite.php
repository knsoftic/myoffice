<?php

declare(strict_types=1);

namespace App\Jobs\Ops;

use App\Enums\IntegrityCheckSuite;
use App\Services\Ops\IntegrityCheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

/**
 * One integrity suite, run by the queue worker instead of inside the request that asked for it.
 *
 * `perf:budget` sends every screen through the HTTP kernel and signs in to do it. Inside a web request —
 * the integrity screen's "Run check" — those nested requests share the real request's session store and
 * auth guard, so the suite refuses to measure there and recorded a warning on every run from the screen.
 * The worker is a console process with neither, so a suite that needs one (`IntegrityCheckSuite::
 * requiresConsole()`) is handed to it, and its verdict is recorded like any other run.
 *
 * The person who pressed the button is put back on the guard for the run — without a session — so the
 * record's `created_by` (Blameable) still names them rather than reading as the scheduler.
 */
final class RunIntegritySuite implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A suite that fails is a finding, not a job to retry: it records its own failed run. */
    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param  array{run_uuid?: string, scope?: string|null, trigger?: string, command?: string|null}  $context
     */
    public function __construct(
        public readonly string $suite,
        public readonly array $context = [],
        public readonly ?int $requestedBy = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(IntegrityCheckService $service): void
    {
        if ($this->requestedBy !== null) {
            Auth::onceUsingId($this->requestedBy);
        }

        try {
            $service->run(IntegrityCheckSuite::from($this->suite), $this->context);
        } finally {
            Auth::forgetUser();
        }
    }
}
