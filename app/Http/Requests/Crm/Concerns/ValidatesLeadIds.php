<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm\Concerns;

use App\Http\Requests\Crm\CrmFormRequest;

/**
 * The explicit id list of a bulk lead operation (phase-05 §6.1 `bulkAssign()` / `bulkChangeStatus()`, §8.1).
 *
 * Explicit ids only — never "everything matching the filter". The list is capped at `crm.bulk_max_ids` **by the
 * request**, so an oversized call is a 422 before the service locks a single row (test 36). Ids the actor cannot see
 * are not rejected here (that would reveal which ids exist): the service excludes them and reports them `forbidden`.
 *
 * @mixin CrmFormRequest
 */
trait ValidatesLeadIds
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function idRules(): array
    {
        $max = $this->bulkMaxIds();

        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.$max],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * `crm.bulk_max_ids` (§5, default 200).
     */
    public function bulkMaxIds(): int
    {
        return $this->crmInt('bulk_max_ids', 200);
    }

    /**
     * The validated ids, as integers, in ascending order (the lock order of §6.1).
     *
     * @return list<int>
     */
    public function ids(): array
    {
        $ids = $this->validated('ids');
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    /**
     * @return array<string, string>
     */
    protected function idMessages(): array
    {
        return [
            'ids.required' => 'Select at least one lead.',
            'ids.min' => 'Select at least one lead.',
            'ids.max' => sprintf('A bulk action is limited to %d leads at a time.', $this->bulkMaxIds()),
        ];
    }
}
