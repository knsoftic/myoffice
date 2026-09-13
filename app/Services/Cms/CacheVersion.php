<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Support\SettingsRepository;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * The public cache **version stamp** — the second half of decision **D22** (phase-03 §6.7,
 * [D-W3-14], INV-8).
 *
 * The project runs the `database` cache store, which has no tag support, so the public site is never
 * invalidated by deleting entries. Instead every public cache key embeds one integer:
 *
 *     cms:v{version}:{namespace}:{sha1(parts)}
 *
 * and any publish increments it. Every page, menu tree, statistics block and sitemap cached under the
 * old number becomes unreachable at once — O(1), on any store — and simply dies of its own TTL.
 *
 * Invariants:
 *
 *   · `version()` is never below 1, and a missing stamp reads as 1.
 *   · `bump()` increments by **exactly one** per call (FT-25), atomically on stores that support an
 *     atomic `add` + `increment` (the database store locks the row).
 *   · `bumpAfterCommit()` never bumps for a transaction that rolls back: the increment is registered
 *     with `DB::afterCommit()`, so a failed save can never flush the site (§10.1).
 *   · Inside `batch()` any number of bump requests collapse into **one** increment when the outermost
 *     batch ends — `cms:publish-scheduled` promotes ten pages and bumps once (§10.4). The collapsed
 *     bump still happens when the batch throws after some work committed.
 *   · A cache store failure is reported, never thrown into a request whose content already committed.
 *
 * Bind it `scoped` (see `docs-pending/phase-03-handover.md`) so the memoised version and the batch
 * state are shared by every service in one request or job; unbound it is still correct, only the
 * memo is per instance.
 */
final class CacheVersion
{
    /** The cache key holding the stamp (§6.7). */
    public const KEY = 'cms.version';

    /** Every key this class builds starts with it. */
    public const PREFIX = 'cms';

    /** Module slug the bump audit rows are filed under. */
    private const MODULE = 'website_sections';

    /** The stamp outlives any content TTL; ten years, so the store's atomic `add()` is used. */
    private const STAMP_TTL_SECONDS = 315_360_000;

    /** Job dispatched after a bump when `website.cache_warm_enabled` is on (§10.2). */
    private const WARM_JOB = 'App\\Jobs\\Cms\\WarmPublicPageCache';

    private ?int $memo = null;

    private int $batchDepth = 0;

    /** @var list<string> */
    private array $pendingReasons = [];

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly DatabaseManager $db,
        private readonly CmsAuditor $auditor,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * The current stamp. Falls back to 1 when nothing is stored or the store is unreachable.
     */
    public function version(): int
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            $stored = $this->cache->get(self::KEY);
        } catch (Throwable $exception) {
            report($exception);

            return 1;
        }

        return $this->memo = is_numeric($stored) && (int) $stored >= 1 ? (int) $stored : 1;
    }

    /**
     * A version-stamped key: `cms:v12:menu:3f2a...`.
     *
     * @param  array<int|string, mixed>|string  $parts
     */
    public function key(string $namespace, array|string $parts = []): string
    {
        $parts = is_array($parts) ? $parts : [$parts];

        return sprintf(
            '%s:v%d:%s:%s',
            self::PREFIX,
            $this->version(),
            $namespace,
            sha1((string) json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        );
    }

    /**
     * Remember a value under a version-stamped key. A publish makes the entry unreachable.
     *
     * @template TValue
     *
     * @param  array<int|string, mixed>|string  $parts
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function remember(string $namespace, array|string $parts, int $seconds, Closure $callback): mixed
    {
        $key = $this->key($namespace, $parts);

        try {
            return $this->cache->remember($key, max(1, $seconds), $callback);
        } catch (Throwable $exception) {
            report($exception);

            return $callback();
        }
    }

    /**
     * Increment the stamp now. Returns the new version (or the current one when collapsed into a
     * running batch).
     */
    public function bump(string $reason): int
    {
        if ($this->batchDepth > 0) {
            $this->pendingReasons[] = $reason;

            return $this->version();
        }

        try {
            $previous = $this->version();

            // `add` is atomic on the database store: it inserts the row only when it is absent.
            $this->cache->add(self::KEY, 1, self::STAMP_TTL_SECONDS);
            $next = $this->cache->increment(self::KEY);

            if ($next === false || ! is_numeric($next)) {
                $next = max(2, $previous + 1);
                $this->cache->put(self::KEY, $next, self::STAMP_TTL_SECONDS);
            }

            $this->memo = (int) $next;
        } catch (Throwable $exception) {
            report($exception);

            return $this->memo ?? 1;
        }

        $this->auditor->record(
            module: self::MODULE,
            description: sprintf('Public cache version bumped to v%d', $this->memo),
            properties: ['old' => ['version' => $previous], 'attributes' => ['version' => $this->memo]],
            reason: $reason,
            event: 'cache_bumped',
        );

        $this->dispatchWarm();

        return $this->memo;
    }

    /**
     * Increment the stamp once the surrounding transaction commits — immediately when there is none.
     * A rolled-back transaction never bumps.
     */
    public function bumpAfterCommit(string $reason): void
    {
        $this->db->connection()->afterCommit(function () use ($reason): void {
            $this->bump($reason);
        });
    }

    /**
     * The admin "Flush" button (§8.3). There is nothing to delete — this is `bump()`.
     */
    public function flush(string $reason = 'Public cache flushed manually'): int
    {
        return $this->bump($reason);
    }

    /**
     * Run a unit of work in which every bump request collapses into one increment at the end.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function batch(Closure $callback, string $reason): mixed
    {
        $this->batchDepth++;

        try {
            return $callback();
        } finally {
            $this->batchDepth--;

            if ($this->batchDepth === 0 && $this->pendingReasons !== []) {
                $count = count($this->pendingReasons);
                $this->pendingReasons = [];

                $this->bump(sprintf('%s (%d %s)', $reason, $count, $count === 1 ? 'change' : 'changes'));
            }
        }
    }

    /**
     * Forget the memoised stamp, so the next read goes to the store (long-running workers).
     */
    public function refresh(): int
    {
        $this->memo = null;

        return $this->version();
    }

    private function dispatchWarm(): void
    {
        if (! class_exists(self::WARM_JOB)) {
            return;
        }

        try {
            if (! (bool) $this->settings->get('website.cache_warm_enabled', true)) {
                return;
            }

            $job = self::WARM_JOB;
            dispatch(new $job);
        } catch (Throwable $exception) {
            // Warming is an optimisation; the first visitor simply renders a cold page.
            report($exception);
        }
    }
}
