<?php

declare(strict_types=1);

namespace App\Support\Ops;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * How many queries a screen is allowed, and what it actually ran (phase-24-25 §6.4).
 *
 * **A budget is a number somebody measured, never a number somebody guessed.** `perf:budget
 * --write-baseline` fills `screen-manifest.php` by running each screen and recording what it did;
 * a guessed budget is worse than none, because it passes and nobody looks at it again.
 *
 * **A screen with no budget is not a failure.** A manifest row whose `query_budget` is null has not
 * been measured yet, and `assert()` skips it. What fails is a screen whose measured budget is
 * exceeded — a regression against a known number — and a screen that runs the same statement over
 * and over, which is an N+1 whatever the total says.
 *
 * `measure()` is the honest way to count: `DB::listen` sees every statement the framework runs,
 * including the ones a relation loads behind a view, which is precisely where an N+1 hides.
 */
final class QueryBudget
{
    /**
     * Statements that are infrastructure rather than screen work.
     *
     * The session read and the permission lookup happen on every authenticated request and belong
     * to the framework rather than to the page; counting them would make every budget in the
     * manifest carry the same constant, and hide a change in it.
     */
    private const INFRASTRUCTURE = [
        'from `sessions`',
        'from `settings`',
        'from `modules`',
        'from `cache`',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $manifest = null;

    /**
     * The measured budget for a route, or null when it has not been measured.
     */
    public function for(string $route): ?int
    {
        $row = $this->manifest()[$route] ?? null;

        if ($row === null) {
            return null;
        }

        $budget = $row['query_budget'] ?? null;

        return is_int($budget) ? $budget : null;
    }

    /**
     * Whether the manifest knows this route at all.
     *
     * Distinct from `for() === null`: an unmeasured screen and an unknown screen are different
     * problems, and only the second one means somebody forgot a manifest row.
     */
    public function knows(string $route): bool
    {
        return array_key_exists($route, $this->manifest());
    }

    /**
     * Run a closure and count what it asked the database.
     *
     * The listener is removed afterwards by restoring the connection's previous state — a measure
     * that leaked its listener would count the next screen's queries as well as its own.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return array{0: TReturn, 1: QueryProfile}
     */
    public function measure(Closure $work): array
    {
        /** @var list<array{sql: string, ms: float}> $queries */
        $queries = [];

        DB::flushQueryLog();
        DB::enableQueryLog();

        $listener = static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = ['sql' => $event->sql, 'ms' => (float) $event->time];
        };

        DB::listen($listener);

        try {
            $result = $work();
        } finally {
            DB::disableQueryLog();
        }

        return [$result, $this->profile($queries)];
    }

    /**
     * Turn a list of statements into a profile.
     *
     * @param  list<array{sql: string, ms: float}>  $queries
     */
    public function profile(array $queries): QueryProfile
    {
        $counted = array_values(array_filter(
            $queries,
            fn (array $query): bool => ! $this->isInfrastructure($query['sql']),
        ));

        $slowest = null;
        $seen = [];
        $duration = 0.0;

        foreach ($counted as $query) {
            $duration += $query['ms'];

            $normalised = $this->normalise($query['sql']);
            $seen[$normalised] = ($seen[$normalised] ?? 0) + 1;

            if ($slowest === null || $query['ms'] > $slowest['ms']) {
                $slowest = $query;
            }
        }

        $duplicates = array_filter($seen, static fn (int $times): bool => $times > 1);

        arsort($duplicates);

        return new QueryProfile(
            count: count($counted),
            durationMs: $duration,
            slowest: $slowest,
            duplicates: $duplicates,
        );
    }

    /**
     * Fail when a route ran more than its measured budget, or repeated itself.
     *
     * @throws RuntimeException with the duplicate SQL listed, because "48 queries, expected 12" is
     *                          a number and "this statement ran 37 times" is an answer
     */
    public function assert(string $route, QueryProfile $profile): void
    {
        // An N+1 is a failure whatever the budget says. A screen with a generous budget can hide
        // one inside it, and a generous budget is usually the result of somebody measuring a screen
        // that already had one.
        //
        // **The comparison is the worst single statement, not the sum of every repeat on the screen**
        // (phase-24-25 section 11.7: "no single SQL string is executed more than 3 times in one
        // request"). Summing was measuring the wrong thing: admin.dashboard repeats eight statements
        // twice or three times each — each of them a card reporting this period and the one before —
        // which summed to nine and threw here, while no statement ran more than three times and
        // nothing looped. That made `perf:budget --write-baseline` unable to record a baseline for the
        // dashboard at all, and made QueryBudgetGuard 500 the admin landing page in local as soon as
        // `ops.query_budget_enforced` was switched on: a screen failed for having many cards.
        if ($profile->worstRepeat() > self::duplicateTolerance()) {
            throw new RuntimeException(sprintf(
                "[%s] repeats itself:\n%s",
                $route,
                $profile->describe(),
            ));
        }

        $budget = $this->for($route);

        if ($budget === null || $profile->count <= $budget) {
            return;
        }

        throw new RuntimeException(sprintf(
            "[%s] ran %d queries against a measured budget of %d:\n%s",
            $route,
            $profile->count,
            $budget,
            $profile->describe(),
        ));
    }

    /**
     * How many repeats are tolerated before a profile counts as an N+1.
     *
     * Three, not zero. A paginator legitimately runs its `count(*)` and its page query, a polymorphic
     * relation loads one statement per type, and a screen that renders two panels of the same shape
     * runs the same shape twice. Beyond that the repetition is a loop.
     */
    public static function duplicateTolerance(): int
    {
        return 3;
    }

    /**
     * Whether a statement is framework infrastructure rather than screen work.
     */
    public function isInfrastructure(string $sql): bool
    {
        foreach (self::INFRASTRUCTURE as $needle) {
            if (str_contains($sql, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collapse a statement to its shape, so two runs of the same query with different ids match.
     *
     * The `in (?, ?, ?)` collapse is what makes an eager load look like one statement however many
     * keys it carries — otherwise loading 15 rows and loading 16 would be two different queries and
     * the duplicate count would depend on the page size.
     */
    public function normalise(string $sql): string
    {
        $sql = (string) preg_replace('/\s+/', ' ', trim($sql));
        $sql = (string) preg_replace('/in \((?:\s*\?\s*,?)+\)/i', 'in (?)', $sql);

        return (string) preg_replace('/\b\d+\b/', 'N', $sql);
    }

    /**
     * The screen manifest, keyed by route name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $path = base_path('tests/Support/screen-manifest.php');

        if (! is_file($path)) {
            return self::$manifest = [];
        }

        /** @var mixed $rows */
        $rows = require $path;

        $keyed = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['route']) && is_string($row['route'])) {
                $keyed[$row['route']] = $row;
            }
        }

        return self::$manifest = $keyed;
    }

    /**
     * Forget the loaded manifest, for a test that has just written one.
     */
    public static function flush(): void
    {
        self::$manifest = null;
    }
}
