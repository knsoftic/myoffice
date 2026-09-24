<?php

declare(strict_types=1);

namespace App\Search\Providers;

use App\DataObjects\Search\SearchHit;
use App\Enums\SearchEntityType;
use App\Models\Hr\Employee;
use App\Models\User;
use App\Search\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Employees (phase-19-23 6.23).
 *
 * Scoped by `Employee::visibleTo()` - Phase 7's own rule, which already knows that a line manager
 * sees their reports and an HR officer sees a branch. Reusing it rather than restating it is the
 * point of [D-23-3]: when Phase 7 changes who may see whom, the palette changes with it.
 */
final class EmployeeSearchProvider extends Provider
{
    public function type(): SearchEntityType
    {
        return SearchEntityType::Employee;
    }

    public function columns(): array
    {
        return ['employees.name', 'employees.employee_code', 'employees.email', 'employees.phone'];
    }

    public function exactColumns(): array
    {
        return ['employee_code'];
    }

    public function query(string $term, User $viewer, int $limit): Collection
    {
        $query = Employee::query()
            ->visibleTo($viewer)
            ->with('designation:id,title')
            ->select(['id', 'name', 'employee_code', 'email', 'phone', 'status', 'designation_id', 'department_id']);

        return $this->match($query, $term)->limit($limit)->get();
    }

    public function present(Model $model, User $viewer): SearchHit
    {
        /** @var Employee $model */
        return new SearchHit(
            type: $this->type(),
            id: $model->getKey(),
            title: (string) $model->name,
            subtitle: $model->designation?->title ?: $model->employee_code,
            badge: $model->status?->label(),
            badgeColor: $model->status?->color(),
            meta: ['Code' => $model->employee_code, 'Phone' => $model->phone],
            url: $this->urlFor('admin.employees.show', [$model->getKey()], $viewer),
        );
    }
}
