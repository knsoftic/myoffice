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

        $lines[] = sprintf('  %d duplicate quer%s — this is what an N+1 looks like:',
            $this->duplicateCount(),
            $this->duplicateCount() === 1 ? 'y' : 'ies',
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
