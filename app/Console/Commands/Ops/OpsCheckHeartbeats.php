<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Ops\SystemHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `ops:check-heartbeats` — the alarm for the three silences (phase-24-25 §6.6, §10.4).
 *
 * **Every failure this watches for is a silence rather than an error.** A dead queue worker throws
 * nothing: jobs pile up and every notification, export and digest simply never arrives. A stopped
 * scheduler throws nothing: the nightly proofs never run, and an absent finding reads exactly like
 * a clean one. A growing `failed_jobs` table throws nothing either — each job already failed, hours
 * ago, in a process nobody was watching. Nothing in this system notices any of the three unless
 * something asks every five minutes, which is what this command is.
 *
 * **It asks {@see SystemHealthService}, it does not measure** (HD-3). The thresholds, the cache
 * keys and the amber/red rules live in one place, so this command and the health screen can never
 * disagree about whether the worker is alive — which is the argument nobody wants to be having
 * while deciding whether to restart it.
 *
 * **Exit 1 when anything is stalled, 0 otherwise.** A monitoring wrapper reads the exit code; the
 * events and the log line reach the people who are not watching a terminal.
 */
final class OpsCheckHeartbeats extends Command
{
    protected $signature = 'ops:check-heartbeats';

    protected $description = 'Compare the queue and scheduler heartbeats and the failed-job count against their thresholds.';

    /**
     * Probe key → the event class the events slice raises for it (§10.1).
     *
     * Strings rather than imports, and dispatched only when the class exists: this alarm has to
     * work in the window before those classes are merged, and an alarm that fatals because its
     * notification class is missing is the worst possible failure — it is silent, and it silences
     * the thing that was watching for silence.
     */
    private const EVENTS = [
        'queue' => \App\Events\Ops\QueueWorkerStalled::class,
        'scheduler' => \App\Events\Ops\SchedulerStalled::class,
        'failed_jobs' => \App\Events\Ops\FailedJobThresholdExceeded::class,
    ];

    public function handle(SystemHealthService $health): int
    {
        $stalled = 0;

        foreach (self::EVENTS as $key => $event) {
            $probe = $health->probe($key);

            $this->line(sprintf(
                '  %-12s %-9s %s',
                $key,
                (string) $probe['status'],
                (string) ($probe['detail'] ?? ''),
            ));

            /*
            | Amber does not raise. A queue heartbeat that has never been stamped is a worker nobody
            | has started yet — on a fresh installation, on a laptop, in CI — and paging for it
            | every five minutes is how a team learns to mute the channel. Red means a heartbeat
            | that was arriving and stopped, or a failed-job count over the threshold somebody set.
            */
            if ($probe['status'] !== SystemHealthService::STATUS_FAILED) {
                continue;
            }

            $stalled++;

            Log::error('Ops heartbeat check failed: '.$key, [
                'probe' => $key,
                'value' => $probe['value'],
                'threshold' => $probe['threshold'],
                'detail' => $probe['detail'],
            ]);

            $this->raise($event, [
                'value' => $probe['value'],
                'threshold' => $probe['threshold'],
            ]);
        }

        if ($stalled === 0) {
            $this->info('ops:check-heartbeats — queue, scheduler and failed jobs are all inside their thresholds.');

            return self::SUCCESS;
        }

        $this->error(sprintf('ops:check-heartbeats — %d of %d checks are stalled.', $stalled, count(self::EVENTS)));

        return self::FAILURE;
    }

    /**
     * Fire the event when its class is available, and never let firing it break the check.
     *
     * The payload is spread as **named** arguments, so the events slice decides the parameter names
     * and this command does not have to guess an order. A signature mismatch throws an `Error`,
     * which is caught and logged: the finding has already reached the log above, and losing the
     * notification is far better than losing the check.
     *
     * @param  array<string, mixed>  $payload
     */
    private function raise(string $event, array $payload): void
    {
        if (! class_exists($event)) {
            return;
        }

        try {
            event(new $event(...$payload));
        } catch (Throwable $exception) {
            Log::warning('ops:check-heartbeats could not dispatch '.$event, [
                'exception' => $exception::class,
            ]);
        }
    }
}
