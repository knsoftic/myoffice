<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Support\SettingsRegistry;
use App\Support\SettingsRepository;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * THE document numbering implementation of the whole system (phase-05 [D-P5-3], §6.10, spine §5, D27, D62, E10).
 *
 * Shipped by Phase 5 as the earliest consumer and reused **unchanged** by phases 6, 7, 8-9, 10, 13, 14-17, 18
 * and 19-23. There is no second `FOR UPDATE` counter anywhere in the codebase: a phase that numbers a document
 * adds only its own `*_prefix` / `*_next_number` settings keys and passes its own pad.
 *
 * `next(prefixKey, counterKey, pad)` —
 *   1. takes `SELECT … FOR UPDATE` on the counter's `settings` row **inside the caller's transaction** (a
 *      missing row is first inserted with the registry default), so two concurrent callers serialise on it;
 *   2. issues the stored value (the next number to hand out) and writes value + 1;
 *   3. returns `prefix . sprintf(pad, value)`.
 * The pad is a default, not a house style: every caller passes its own (`'%06d'` leads/clients, `'%05d'`
 * projects/HR, `'%04d'` collaborators). The UNIQUE index on the number column is the backstop, and `assign()`
 * gives every caller the spine's "exactly one retry on a 1062" without writing its own loop.
 *
 * `reserve(counterKey, periodKey, periodValue)` reserves the next integer under the same lock and, when a period
 * is supplied, resets the counter to 1 the first time the period row (itself a settings key, e.g.
 * `institute.student_id_sequence_period`) differs from `periodValue`, writing both rows in one transaction.
 *
 * A counter is never written through `SettingsService` or `SettingsRepository::set()` — both refuse any
 * `*_next_number` key (D62). The counter rows are written here with the query builder, and the settings cache
 * is flushed after the transaction commits. Numbers may have gaps (a rolled-back caller releases nothing);
 * nothing in the system assumes a gap-free sequence.
 */
final class DocumentNumberService
{
    private const TABLE = 'settings';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * The next formatted document number.
     */
    public function next(string $prefixKey, string $counterKey, string $pad = '%06d'): string
    {
        $prefix = $this->settings->get($prefixKey, SettingsRegistry::field($prefixKey)['default'] ?? '');
        $prefix = is_scalar($prefix) ? (string) $prefix : '';

        return $prefix.$this->format($this->reserve($counterKey), $pad);
    }

    /**
     * Reserve the next integer of a counter, with an optional period reset.
     */
    public function reserve(string $counterKey, ?string $periodKey = null, ?string $periodValue = null): int
    {
        if (($periodKey === null) !== ($periodValue === null)) {
            throw new InvalidArgumentException('DocumentNumberService::reserve(): a period needs both its key and its value.');
        }

        return $this->withinTransaction(function (ConnectionInterface $connection) use ($counterKey, $periodKey, $periodValue): int {
            [$group, $key] = $this->split($counterKey);

            $this->ensureRow($connection, $group, $key, $counterKey);

            $row = $connection->table(self::TABLE)
                ->where('group', $group)
                ->where('key', $key)
                ->lockForUpdate()
                ->first(['id', 'value']);

            if ($row === null) {
                throw new RuntimeException(sprintf('Document counter [%s] could not be locked.', $counterKey));
            }

            $current = $this->counterValue($row->value, $counterKey);

            if ($periodKey !== null && $periodValue !== null) {
                [$periodGroup, $periodName] = $this->split($periodKey);

                $this->ensureRow($connection, $periodGroup, $periodName, $periodKey, '');

                $period = $connection->table(self::TABLE)
                    ->where('group', $periodGroup)
                    ->where('key', $periodName)
                    ->lockForUpdate()
                    ->first(['id', 'value']);

                if ($period === null) {
                    throw new RuntimeException(sprintf('Document counter period [%s] could not be locked.', $periodKey));
                }

                if ((string) $period->value !== $periodValue) {
                    $current = 1;

                    $connection->table(self::TABLE)->where('id', $period->id)->update([
                        'value' => $periodValue,
                        'updated_at' => Carbon::now(),
                    ]);
                }
            }

            $connection->table(self::TABLE)->where('id', $row->id)->update([
                'value' => (string) ($current + 1),
                'updated_at' => Carbon::now(),
            ]);

            $this->flushAfterCommit($connection);

            return $current;
        });
    }

    /**
     * Number a document and write it, with the spine's single retry on a 1062 of the number's own index.
     *
     * The counter is advanced in the caller's transaction; `$write` runs inside a savepoint and receives the
     * formatted number. When that write fails on `$uniqueIndex` (the number column's UNIQUE index — a row created
     * outside this service took the number), the savepoint rolls back, the next number is reserved and `$write`
     * runs **once** more. Any other exception — a 1062 on a different index included — propagates untouched.
     *
     * @template TResult
     *
     * @param  Closure(string): TResult  $write
     * @return TResult
     */
    public function assign(string $prefixKey, string $counterKey, string $pad, Closure $write, ?string $uniqueIndex = null): mixed
    {
        return $this->withinTransaction(function (ConnectionInterface $connection) use ($prefixKey, $counterKey, $pad, $write, $uniqueIndex): mixed {
            $attempt = 0;

            while (true) {
                $number = $this->next($prefixKey, $counterKey, $pad);

                try {
                    return $connection->transaction(static fn (): mixed => $write($number));
                } catch (UniqueConstraintViolationException $exception) {
                    $attempt++;

                    $onNumberIndex = $uniqueIndex === null || str_contains($exception->getMessage(), $uniqueIndex);

                    if (! $onNumberIndex || $attempt > 1) {
                        throw $exception;
                    }
                }
            }
        });
    }

    /**
     * `sprintf($pad, $value)`, refusing a pad that is not a single integer conversion.
     */
    public function format(int $value, string $pad = '%06d'): string
    {
        if (preg_match('/^%0?\d*d$/', $pad) !== 1) {
            throw new InvalidArgumentException(sprintf('DocumentNumberService: "%s" is not an integer pad such as %%06d.', $pad));
        }

        return sprintf($pad, $value);
    }

    /**
     * `'%0'.$digits.'d'` — for a caller that stores its padding as a number of digits.
     */
    public static function padFor(int $digits): string
    {
        return sprintf('%%0%dd', max(1, min(20, $digits)));
    }

    /**
     * @template TResult
     *
     * @param  Closure(ConnectionInterface): TResult  $callback
     * @return TResult
     */
    private function withinTransaction(Closure $callback): mixed
    {
        $connection = $this->db->connection();

        // Inside the caller's transaction, as the spine requires. A caller that opened none still gets a
        // correct, unique number: the lock is held by this short transaction instead.
        if ($connection->transactionLevel() > 0) {
            return $callback($connection);
        }

        return $connection->transaction(static fn (): mixed => $callback($connection));
    }

    private function ensureRow(ConnectionInterface $connection, string $group, string $key, string $dotted, ?string $default = null): void
    {
        $exists = $connection->table(self::TABLE)->where('group', $group)->where('key', $key)->exists();

        if ($exists) {
            return;
        }

        $field = SettingsRegistry::field($dotted);
        $value = $default ?? (string) (is_numeric($field['default'] ?? null) ? (int) $field['default'] : 1);
        $type = $field === null ? ($default === null ? 'integer' : 'string') : SettingsRegistry::storageType((string) ($field['type'] ?? ''));
        $now = Carbon::now();

        // insertOrIgnore: two callers racing to create the same missing row both continue to the lock below.
        $connection->table(self::TABLE)->insertOrIgnore([
            'group' => $group,
            'key' => $key,
            'value' => $value,
            'type' => $type === '' ? 'integer' : $type,
            'is_encrypted' => false,
            'is_public' => false,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function counterValue(mixed $raw, string $counterKey): int
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return 1;
        }

        if (preg_match('/^\d+$/', $raw) !== 1) {
            throw new LogicException(sprintf('Document counter [%s] holds "%s", which is not a whole number.', $counterKey, $raw));
        }

        return max(1, (int) $raw);
    }

    private function flushAfterCommit(ConnectionInterface $connection): void
    {
        $settings = $this->settings;

        if (method_exists($connection, 'afterCommit') && $connection->transactionLevel() > 0) {
            $connection->afterCommit(static fn () => $settings->flush());

            return;
        }

        $settings->flush();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function split(string $dotted): array
    {
        $dotted = trim($dotted);

        if (! str_contains($dotted, '.')) {
            throw new InvalidArgumentException(sprintf('DocumentNumberService: "%s" is not a dotted settings key.', $dotted));
        }

        [$group, $key] = explode('.', $dotted, 2);

        if ($group === '' || $key === '') {
            throw new InvalidArgumentException(sprintf('DocumentNumberService: "%s" is not a dotted settings key.', $dotted));
        }

        return [$group, $key];
    }
}
