<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

/**
 * Per-id outcome of a bulk lead operation (phase-05 §6.1 `bulkAssign()` / `bulkChangeStatus()`, tests 34-36).
 *
 * Every requested id lands in exactly one bucket:
 *   · `done`      — the row was written;
 *   · `skipped`   — visible, but refused by a rule (an illegal transition, a missing reason), with the reason;
 *   · `forbidden` — the actor may not see it under §9 (reported without any detail about the row);
 *   · `missing`   — no such lead (deleted or never existed).
 */
final class BulkResult
{
    public const DONE = 'done';

    public const SKIPPED = 'skipped';

    public const FORBIDDEN = 'forbidden';

    public const MISSING = 'missing';

    /** @var array<int, array{outcome: string, reason: string|null}> */
    private array $outcomes = [];

    public function __construct(
        public readonly string $operation,
    ) {}

    public function done(int $id): void
    {
        $this->outcomes[$id] = ['outcome' => self::DONE, 'reason' => null];
    }

    public function skipped(int $id, string $reason): void
    {
        $this->outcomes[$id] = ['outcome' => self::SKIPPED, 'reason' => $reason];
    }

    public function forbidden(int $id): void
    {
        $this->outcomes[$id] = ['outcome' => self::FORBIDDEN, 'reason' => null];
    }

    public function missing(int $id): void
    {
        $this->outcomes[$id] = ['outcome' => self::MISSING, 'reason' => null];
    }

    /**
     * @return array<int, array{outcome: string, reason: string|null}>
     */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    public function count(string $outcome): int
    {
        return count(array_filter($this->outcomes, static fn (array $row): bool => $row['outcome'] === $outcome));
    }

    /**
     * @return list<int>
     */
    public function ids(string $outcome): array
    {
        return array_keys(array_filter($this->outcomes, static fn (array $row): bool => $row['outcome'] === $outcome));
    }

    /**
     * "187 assigned, 3 skipped" — the toast.
     */
    public function message(string $verb): string
    {
        $parts = [sprintf('%d %s', $this->count(self::DONE), $verb)];
        $notDone = $this->count(self::SKIPPED) + $this->count(self::FORBIDDEN) + $this->count(self::MISSING);

        if ($notDone > 0) {
            $parts[] = sprintf('%d skipped', $notDone);
        }

        return implode(', ', $parts);
    }

    /**
     * @return array{operation: string, counts: array<string, int>, outcomes: array<int, array{outcome: string, reason: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'counts' => [
                self::DONE => $this->count(self::DONE),
                self::SKIPPED => $this->count(self::SKIPPED),
                self::FORBIDDEN => $this->count(self::FORBIDDEN),
                self::MISSING => $this->count(self::MISSING),
            ],
            'outcomes' => $this->outcomes,
        ];
    }
}
