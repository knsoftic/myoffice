<?php

declare(strict_types=1);

namespace App\Support\Ops;

/**
 * What a prune actually did (phase-24-25 §6.2, §6.10.3).
 *
 * **`failures` is not an error list, it is the reason the next prune is not a surprise.** A file
 * that could not be deleted — an antivirus holding a handle, a disconnected offsite share, a
 * permission that changed — leaves its row unpruned on purpose: the row still points at a file, the
 * next run tries again, and nothing has been written that claims the bytes are gone when they are
 * not. A prune that swallowed the failure would leave `file_pruned_at` set on an archive still
 * occupying the disk, and the storage ceiling would then be computed from a fiction.
 *
 * The plan that produced the result is carried along, so a caller printing "what happened" can also
 * print "what was going to happen" without recomputing it against a disk that has since changed.
 */
final readonly class RetentionResult
{
    /**
     * @param  list<int>  $prunedIds  `backup_runs.id` whose file was removed — never a deleted row
     * @param  list<array{id: int, path: string, error: string}>  $failures
     * @param  string  $reclaimedBytes  bytes actually released, as an integer string
     */
    public function __construct(
        public RetentionPlan $plan,
        public array $prunedIds,
        public array $failures,
        public string $reclaimedBytes,
    ) {}

    public function count(): int
    {
        return count($this->prunedIds);
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pruned' => $this->prunedIds,
            'pruned_count' => count($this->prunedIds),
            'failures' => $this->failures,
            'reclaimed_bytes' => $this->reclaimedBytes,
            'plan' => $this->plan->toArray(),
        ];
    }

    public function describe(): string
    {
        $lines = [sprintf(
            '%d archive file(s) removed, %s released. No row was deleted.',
            count($this->prunedIds),
            RetentionPlan::humanBytes($this->reclaimedBytes),
        )];

        foreach ($this->failures as $failure) {
            $lines[] = sprintf('  FAILED  #%d  %s  %s', $failure['id'], $failure['path'], $failure['error']);
        }

        return implode("\n", $lines);
    }
}
