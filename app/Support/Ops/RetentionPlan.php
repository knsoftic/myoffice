<?php

declare(strict_types=1);

namespace App\Support\Ops;

/**
 * What a prune *would* do, with the reason for every row on both sides (phase-24-25 §6.2, §6.10.3).
 *
 * **A plan is a separate object from the act because `--dry-run` has to be the same code path.** A
 * preview that is computed by different code than the prune is a preview of something else, and the
 * one place that matters — the operator reading "these fourteen archives will go" the morning
 * before a deploy — is exactly where being wrong is expensive.
 *
 * **Every entry carries its reason**, `kept` as much as `prune`. "Three archives survived" tells an
 * operator nothing; "three survived because the minimum-copies floor stopped the prune" tells them
 * the retention windows are now shorter than the floor, which is a setting to fix before it becomes
 * a night with no archive at all.
 *
 * Sizes are integer strings and are compared with `bccomp`: the storage ceiling arrives as the
 * decimal setting `backup.max_storage_gb`, and `20.00 × 1024³` is the arithmetic a float rounds.
 */
final readonly class RetentionPlan
{
    /**
     * A row is kept because it is inside its retention window.
     */
    public const REASON_INSIDE_WINDOW = 'inside_window';

    /**
     * A `pre_restore` or `pre_deploy` archive is never pruned automatically (§6.10.3 rule 3).
     */
    public const REASON_PROTECTED_TRIGGER = 'protected_trigger';

    /**
     * The newest usable database archive, which no calendar may take (§6.10.3 rule 2).
     */
    public const REASON_NEWEST_DATABASE = 'newest_database_archive';

    /**
     * The `retention_min_copies` floor pulled this row back out of the prune list (rule 1).
     */
    public const REASON_MINIMUM_COPIES = 'minimum_copies';

    /**
     * The row's retention window has closed.
     */
    public const REASON_WINDOW_EXPIRED = 'window_expired';

    /**
     * @param  list<array{id: int, uuid: string, type: string, trigger: string, status: string, path: string, size_bytes: int, retention_class: string, retention_until: string|null, created_at: string|null, reason: string}>  $prune
     * @param  list<array{id: int, uuid: string, type: string, trigger: string, status: string, path: string, size_bytes: int, retention_class: string, retention_until: string|null, created_at: string|null, reason: string}>  $keep
     * @param  int  $usableDatabaseArchives  usable database archives with a file, before the prune
     * @param  int  $usableDatabaseArchivesAfter  what would be left afterwards — the number the floor guards
     * @param  string  $usedBytes  occupied now
     * @param  string  $reclaimableBytes  what the prune would release
     * @param  string  $ceilingBytes  `backup.max_storage_gb` in bytes
     * @param  bool  $ceilingBreached  true when the ceiling is still exceeded *after* the prune
     */
    public function __construct(
        public array $prune,
        public array $keep,
        public int $usableDatabaseArchives,
        public int $usableDatabaseArchivesAfter,
        public int $minimumCopies,
        public string $usedBytes,
        public string $reclaimableBytes,
        public string $ceilingBytes,
        public bool $ceilingBreached,
    ) {}

    public function isEmpty(): bool
    {
        return $this->prune === [];
    }

    public function count(): int
    {
        return count($this->prune);
    }

    /**
     * Bytes left once the plan has run.
     */
    public function remainingBytes(): string
    {
        return bcsub($this->usedBytes, $this->reclaimableBytes, 0);
    }

    /**
     * Rows the floor or a protection rule pulled back out of the prune list.
     *
     * The list an operator should read first: it is the difference between "retention is working"
     * and "retention would have gone too far and something stopped it".
     *
     * @return list<array<string, mixed>>
     */
    public function rescued(): array
    {
        return array_values(array_filter(
            $this->keep,
            static fn (array $row): bool => in_array($row['reason'], [
                self::REASON_MINIMUM_COPIES,
                self::REASON_NEWEST_DATABASE,
                self::REASON_PROTECTED_TRIGGER,
            ], true),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'prune' => $this->prune,
            'keep' => $this->keep,
            'usable_database_archives' => $this->usableDatabaseArchives,
            'usable_database_archives_after' => $this->usableDatabaseArchivesAfter,
            'minimum_copies' => $this->minimumCopies,
            'used_bytes' => $this->usedBytes,
            'reclaimable_bytes' => $this->reclaimableBytes,
            'remaining_bytes' => $this->remainingBytes(),
            'ceiling_bytes' => $this->ceilingBytes,
            'ceiling_breached' => $this->ceilingBreached,
        ];
    }

    /**
     * A readable account, for `backup:prune --dry-run` and for the "Preview prune" button.
     */
    public function describe(): string
    {
        $lines = [sprintf(
            '%d archive(s) to prune, %d kept; %s would be released of %s used (ceiling %s).',
            count($this->prune),
            count($this->keep),
            self::humanBytes($this->reclaimableBytes),
            self::humanBytes($this->usedBytes),
            self::humanBytes($this->ceilingBytes),
        )];

        $lines[] = sprintf(
            'Usable database archives: %d now, %d after — the floor is %d.',
            $this->usableDatabaseArchives,
            $this->usableDatabaseArchivesAfter,
            $this->minimumCopies,
        );

        foreach ($this->prune as $row) {
            $lines[] = sprintf('  prune  #%d  %s  %s', $row['id'], $row['path'], $row['reason']);
        }

        foreach ($this->rescued() as $row) {
            $lines[] = sprintf('  keep   #%d  %s  %s', $row['id'], $row['path'], $row['reason']);
        }

        if ($this->ceilingBreached) {
            $lines[] = 'The storage ceiling is still exceeded after this plan. The job fails rather '
                .'than pruning past policy — running out of disk is an operations problem, not a '
                .'licence to destroy history.';
        }

        return implode("\n", $lines);
    }

    /**
     * Bytes as something a person reads. Integer division throughout: this is a label, and a label
     * that says "1.9 GB" when the ceiling is 2 GB has told the reader what they needed.
     */
    public static function humanBytes(string $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $value = $bytes;

        while (bccomp($value, '1024', 0) >= 0 && $index < count($units) - 1) {
            $value = bcdiv($value, '1024', 2);
            $index++;
        }

        // Only a value that has a decimal point may lose trailing zeros. `rtrim('500', '0')` is
        // '5', and a storage figure that reports 5 bytes where 500 were used is worse than one
        // that reports 500.00.
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value.' '.$units[$index];
    }
}
