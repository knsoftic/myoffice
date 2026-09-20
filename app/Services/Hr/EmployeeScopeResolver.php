<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Hr\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Who a user may see in HR (phase-07 §6.1, §9).
 *
 * **Every HR list query starts here**, so "a manager sees their reports" is decided once rather than
 * re-derived on eleven screens — and so the one screen somebody forgets cannot be the one that leaks a
 * salary.
 *
 * Four answers, and they are exclusive:
 *
 * - **all** — Super Admin, or a holder of `employees.view_any` (HR, Admin, Accountant).
 * - **team** — somebody who approves leave or attendance but cannot see everybody: themselves plus every
 *   descendant of their reporting tree, resolved iteratively with a **depth cap of 5**. The cap is not a
 *   guess about org charts; it stops a cycle in `reports_to_id` from becoming an infinite loop, and five
 *   levels is deeper than any structure this system is for.
 * - **own** — an employee with only self-service permissions.
 * - **none** — no employee record and no HR permission. Every HR route 403s.
 *
 * The branch rule of D11 is applied on top by {@see applyBranch()}: a user pinned to a branch sees that
 * branch's rows and the unassigned ones, never another branch's.
 */
class EmployeeScopeResolver
{
    /** Deep enough for any real org chart, shallow enough that a cycle cannot spin (§9). */
    private const MAX_DEPTH = 5;

    /** @var array<int, array{mode: string, ids: list<int>}> */
    private array $cache = [];

    /** Which tables carry which column — a schema fact, so it is shared for the process. */
    private static array $columns = [];

    /**
     * @return array{mode: 'all'|'team'|'own'|'none', ids: list<int>}
     */
    public function visibility(?User $user): array
    {
        if ($user === null) {
            return ['mode' => 'none', 'ids' => []];
        }

        $key = (int) $user->getKey();

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        if ($user->can('employees.view_any')) {
            return $this->cache[$key] = ['mode' => 'all', 'ids' => []];
        }

        $self = Employee::query()->where('user_id', $user->getKey())->value('id');

        if ($self === null) {
            return $this->cache[$key] = ['mode' => 'none', 'ids' => []];
        }

        $self = (int) $self;

        if ($user->can('leaves.approve') || $user->can('attendance.approve')) {
            return $this->cache[$key] = ['mode' => 'team', 'ids' => $this->treeFrom($self)];
        }

        return $this->cache[$key] = ['mode' => 'own', 'ids' => [$self]];
    }

    /**
     * Narrow any query that has an `employee_id` column (or, for `employees` itself, an `id`).
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public function apply(Builder $query, ?User $user, string $column = 'employee_id'): Builder
    {
        $visibility = $this->visibility($user);

        return match ($visibility['mode']) {
            'all' => $this->applyBranch($query, $user),
            'none' => $query->whereRaw('1 = 0'),
            default => $this->applyBranch($query->whereIn($column, $visibility['ids'] ?: [0]), $user),
        };
    }

    /**
     * D11's branch rule, applied on top of every other answer: a user pinned to a branch sees that
     * branch's rows and the unassigned ones.
     *
     * **Only where the table actually carries a branch.** Several HR tables deliberately do not — a
     * correction and a leave-balance row belong to a person, and the person already carries the branch.
     * Asking for a column that is not there produced a 500 on the correction queue the first time this
     * shipped, so the column is checked rather than assumed, once per table per process.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public function applyBranch(Builder $query, ?User $user, string $column = 'branch_id'): Builder
    {
        $branchId = $user?->branch_id;

        if ($branchId === null || ! $this->hasColumn($query, $column)) {
            return $query;
        }

        return $query->where(fn (Builder $scoped) => $scoped
            ->whereNull($column)
            ->orWhere($column, $branchId));
    }

    /**
     * May this user see this one employee? The question a policy asks.
     */
    public function canSee(?User $user, Employee $employee): bool
    {
        $visibility = $this->visibility($user);

        return match ($visibility['mode']) {
            'all' => true,
            'none' => false,
            default => in_array((int) $employee->getKey(), $visibility['ids'], true),
        };
    }

    /**
     * The employee record behind a login, or null. Self-service resolves everything through this and
     * never through an id in the request (§9).
     */
    public function selfFor(?User $user): ?Employee
    {
        if ($user === null) {
            return null;
        }

        return Employee::query()->where('user_id', $user->getKey())->first();
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /**
     * Does the table behind this query have the column? Cached per table for the life of the process —
     * a schema lookup per row would be absurd, and the schema does not change under a running request.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function hasColumn(Builder $query, string $column): bool
    {
        $table = $query->getModel()->getTable();
        $key = $table.'.'.$column;

        return self::$columns[$key] ??= Schema::hasColumn($table, $column);
    }

    /**
     * An employee and every descendant of their reporting tree, breadth-first, capped.
     *
     * One query per level rather than one per node: a forty-person company is five queries, not forty.
     *
     * @return list<int>
     */
    private function treeFrom(int $rootId): array
    {
        $found = [$rootId];
        $frontier = [$rootId];

        for ($depth = 0; $depth < self::MAX_DEPTH && $frontier !== []; $depth++) {
            $next = Employee::query()
                ->whereIn('reports_to_id', $frontier)
                ->whereNotIn('id', $found)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($next === []) {
                break;
            }

            $found = array_merge($found, $next);
            $frontier = $next;
        }

        return array_values(array_unique($found));
    }
}
