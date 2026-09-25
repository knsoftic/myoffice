<?php

declare(strict_types=1);

namespace App\Support\Ops;

/**
 * What a run of {@see QueryBudget::measure()} saw (phase-24-25 §6.4).
 *
 * **`duplicates` is the number that matters, not `count`.** A screen making forty queries to render
 * forty different things is doing its job. A screen making forty queries that are the *same* query
 * with a different id is an N+1, and the fix is one `with()`. The two look identical in a total, so
 * they are counted apart.
 *
 * SQL is kept with its placeholders and never with its bindings: a profile is printed into a test
 * failure and into a log line, and the bindings are the row data the query was about.
 */
final readonly class QueryProfile
{
    /**
     * @param  int  $count  queries run
     * @param  float  $durationMs  total time spent in the database
     * @param  array{sql: string, ms: float}|null  $slowest
     * @param  array<string, int>  $duplicates  SQL => how many times it ran, for SQL that ran more than once
     */
    public function __construct(
        public int $count,
        public float $durationMs,
        public ?array $slowest,
        public array $duplicates,
    ) {}

    /**
     * How many queries were repeats of one another.
     *
     * A statement that ran five times contributes four: the first run was work, the other four were
     * the N+1.
     */
    public function duplicateCount(): int
    {
        $total = 0;

        foreach ($this->duplicates as $times) {
            $total += $times - 1;
        }

        return $total;
    }

    /**
     * How many times the **most repeated single statement** ran, or 0 when nothing repeated.
     *
     * **This is the number phase-24-25 section 11.7 sets the limit on** — "no single SQL string is
     * executed more than 3 times in one request" — and {@see self::duplicateCount()} is not, because
     * it sums repeats across statements that have nothing to do with each other. The two agree
     * wherever one statement repeats and part company on a screen holding several legitimately
     * repeated statements: admin.dashboard summed to nine over eight statements while no statement
     * ran more than three times, which is the shape {@see QueryBudget::duplicateTolerance()} documents
     * as allowed — two panels of the same shape, a polymorphic load, a paginator's count — and not a
     * loop. It lives here, once, so the budget guard and the PRF-02 sweep cannot drift into reading the
     * clause two ways.
     */
    public function worstRepeat(): int
    {
        return $this->duplicates === [] ? 0 : max($this->duplicates);
    }

    /**
     * A readable account of what happened, for a test failure or a log line.
     */
    public function describe(int $limit = 5): string
    {
        $lines = [sprintf('%d queries in %.1f ms', $this->count, $this->durationMs)];

        if ($this->slowest !== null) {
            $lines[] = sprintf('  slowest (%.1f ms): %s', $this->slowest['ms'], $this->truncate($this->slowest['sql']));
        }

        if ($this->duplicates === []) {
            return implode("\n", $lines);
        }

        // Both numbers, because only one of them is the one the limit is set on. Saying "9 duplicate
        // queries — this is what an N+1 looks like" over eight unrelated statements is what sent a
        // reader looking for a loop on admin.dashboard that was never there; the worst single
        // statement is the figure {@see self::worstRepeat()} compares, so it is printed beside it.
        $lines[] = sprintf('  %d duplicate quer%s, worst single statement ×%d:',
            $this->duplicateCount(),
            $this->duplicateCount() === 1 ? 'y' : 'ies',
            $this->worstRepeat(),
        );

        $shown = 0;

        foreach ($this->duplicates as $sql => $times) {
            if ($shown++ >= $limit) {
                $lines[] = sprintf('    … and %d more', count($this->duplicates) - $limit);

                break;
            }

            $lines[] = sprintf('    ×%d  %s', $times, $this->truncate($sql));
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{count: int, duration_ms: float, slowest: array{sql: string, ms: float}|null, duplicates: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'duration_ms' => round($this->durationMs, 2),
            'slowest' => $this->slowest,
            'duplicates' => $this->duplicates,
        ];
    }

    private function truncate(string $sql, int $length = 160): string
    {
        $sql = (string) preg_replace('/\s+/', ' ', trim($sql));

        return mb_strlen($sql) <= $length ? $sql : mb_substr($sql, 0, $length).'…';
    }
}
