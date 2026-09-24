<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Ops\SystemHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `ops:heartbeat` — proof that the minute scheduler is still alive (phase-24-25 §6.6, §10.4).
 *
 * **A scheduler that dies does not report an error; it stops reporting at all.** Every nightly
 * proof in this system — the wallet reconciliation, the constraint verification, the backup, the
 * integrity sweep — is started by `schedule:run`. When the Windows task or the cron entry stops,
 * none of them fails: they simply never run, and a sweep that never ran looks exactly like a sweep
 * that found nothing. This command exists so that silence has a timestamp attached to it.
 *
 * Three things happen here, and they prove three different layers:
 *
 *  1. **The scheduler stamp** proves `schedule:run` is being invoked at all.
 *  2. **The queue ping, every fifth run**, is stamped by a *worker* rather than by this process —
 *     which is the only way to prove a worker is consuming jobs. A scheduler can be perfectly
 *     healthy while the queue is dead, and every notification, export and digest is queued.
 *  3. **The uptime ping** reaches an external watchdog. A machine that has gone away entirely
 *     cannot report that it has gone away; only something off the machine notices a ping that
 *     stopped arriving.
 *
 * **It always exits 0, and that is deliberate.** A failing heartbeat command would mark the
 * scheduler entry as failed and, with `onFailure` handlers attached, send a notification every
 * minute for as long as the watchdog URL is unreachable. Everything that can go wrong here is
 * logged and swallowed: the *absence* of a stamp is the alarm, and `ops:check-heartbeats` raises
 * it.
 */
final class OpsHeartbeat extends Command
{
    protected $signature = 'ops:heartbeat';

    protected $description = 'Stamp the scheduler heartbeat, ping the queue every fifth run, and ping the external watchdog.';

    /**
     * The job the queue stamp goes through when the jobs slice has shipped it.
     *
     * Named as a string rather than imported: this command must keep stamping the scheduler
     * heartbeat whether or not that class exists yet, and a `use` of a missing class would be a
     * fatal error every minute.
     */
    private const QUEUE_PING_JOB = \App\Jobs\Ops\QueuePingJob::class;

    public function handle(): int
    {
        $this->stampScheduler();
        $this->pingQueueEveryFifthRun();
        $this->pingWatchdog();

        return self::SUCCESS;
    }

    /**
     * The stamp itself.
     *
     * `forever`, not a TTL. A heartbeat that expired would make a scheduler which stopped two weeks
     * ago indistinguishable from one that has never run — and `SystemHealthService` treats those
     * two as different verdicts (amber for never, red for stopped) precisely because one of them
     * means something used to work.
     */
    private function stampScheduler(): void
    {
        try {
            Cache::forever(SystemHealthService::SCHEDULER_HEARTBEAT_KEY, time());
        } catch (Throwable $exception) {
            Log::warning('ops:heartbeat could not stamp the scheduler heartbeat.', [
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * Dispatch the queue ping on every fifth tick.
     *
     * Every fifth rather than every minute because the point is to prove a worker is consuming, not
     * to measure latency: at the default five-minute ceiling one ping per five minutes is the
     * lowest rate that can still be fresh, and a job queued every minute for ever is a table that
     * grows for no reason on the database queue driver.
     */
    private function pingQueueEveryFifthRun(): void
    {
        try {
            $tick = (int) Cache::get(SystemHealthService::HEARTBEAT_TICK_KEY, 0) + 1;
            Cache::forever(SystemHealthService::HEARTBEAT_TICK_KEY, $tick);

            if ($tick % SystemHealthService::QUEUE_PING_EVERY !== 0) {
                return;
            }

            $this->dispatchQueuePing();
        } catch (Throwable $exception) {
            Log::warning('ops:heartbeat could not dispatch the queue ping.', [
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * Put the stamp on the `high` queue.
     *
     * **`high`, not `default`.** A backlog on `default` would make the queue look dead while the
     * worker was in fact busy — and the alarm this feeds is "the worker has stopped", not "the
     * worker is behind". The queue depth is a separate probe.
     *
     * The closure is the fallback for the window in which `QueuePingJob` has not yet been merged.
     * It stamps the same key through the same worker, so the probe is never blind waiting for a
     * class; when the job lands this branch simply stops being taken.
     */
    private function dispatchQueuePing(): void
    {
        $job = self::QUEUE_PING_JOB;

        if (class_exists($job)) {
            dispatch(new $job);

            return;
        }

        dispatch(static function (): void {
            Cache::forever(SystemHealthService::QUEUE_HEARTBEAT_KEY, time());
        })->onQueue('high');
    }

    /**
     * Tell the external watchdog the machine is still here.
     *
     * Short timeouts and a swallowed failure: a watchdog that is down must never hold up the
     * scheduler, and a five-second connect attempt repeated every minute is a minute-long hang
     * waiting to happen. The URL itself is never logged — it is a secret in the sense that anybody
     * holding it can silence the alarm by pinging it themselves.
     */
    private function pingWatchdog(): void
    {
        try {
            $url = setting('ops.uptime_ping_url');
        } catch (Throwable) {
            return;
        }

        if (! is_string($url) || $url === '') {
            return;
        }

        try {
            Http::timeout(5)->connectTimeout(3)->get($url);
        } catch (Throwable $exception) {
            Log::warning('ops:heartbeat could not reach the uptime watchdog.', [
                'exception' => $exception::class,
            ]);
        }
    }
}
