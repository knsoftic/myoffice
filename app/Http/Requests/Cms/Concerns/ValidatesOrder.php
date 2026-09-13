<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

/**
 * The drag-to-reorder payload of phase-03 §8.2: the **full** ordered id list (INV-5).
 *
 * Only the shape is checked here — integers, no duplicates, a sane ceiling. Whether the list is
 * *exactly* the current set (no additions, no omissions, so a stale tab cannot drop a row) is decided by
 * the service inside its transaction, under a row lock, because only there is "the current set" true.
 */
trait ValidatesOrder
{
    /** A placement, repeater, category or menu never legitimately holds more rows than this. */
    public const ORDER_MAX = 500;

    /**
     * @return array<string, list<string>>
     */
    protected function orderRules(string $key = 'order'): array
    {
        return [
            $key => ['bail', 'required', 'array', 'min:1', 'max:'.self::ORDER_MAX],
            $key.'.*' => ['bail', 'required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * The ordered ids as integers.
     *
     * @return list<int>
     */
    public function orderedIds(string $key = 'order'): array
    {
        $ids = $this->validated($key);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }
}
