<?php

declare(strict_types=1);

namespace App\Search\Providers;

use App\DataObjects\Search\SearchHit;
use App\Enums\SearchEntityType;
use App\Models\Institute\Course;
use App\Models\User;
use App\Search\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Courses (phase-19-23 6.23).
 *
 * **The fee is withheld without `courses.view_financial`**, which is the one place in the palette
 * where a column disappears rather than a row. INV-23-2's rule applies here too: the meta entry is
 * absent, not blank, because a "Fee: -" line tells somebody there is a fee and invites them to ask
 * around for it.
 */
final class CourseSearchProvider extends Provider
{
    public function type(): SearchEntityType
    {
        return SearchEntityType::Course;
    }

    public function columns(): array
    {
        return ['courses.name', 'courses.code', 'courses.slug'];
    }

    public function exactColumns(): array
    {
        return ['code'];
    }

    public function query(string $term, User $viewer, int $limit): Collection
    {
        $query = Course::query()
            ->with('category:id,name')
            ->select(['id', 'name', 'code', 'slug', 'status', 'level', 'course_fee', 'course_category_id', 'branch_id']);

        $branchId = $viewer->getAttribute('branch_id');

        if ($branchId !== null && ! $this->can($viewer, 'view_any')) {
            $query->where(static fn ($q) => $q->whereNull('courses.branch_id')->orWhere('courses.branch_id', $branchId));
        }

        return $this->match($query, $term)->limit($limit)->get();
    }

    public function present(Model $model, User $viewer): SearchHit
    {
        /** @var Course $model */
        $meta = ['Code' => $model->code, 'Category' => $model->category?->name];

        // Absent, not blank - see the class note.
        if ($this->can($viewer, 'view_financial')) {
            $meta['Fee'] = money((string) $model->course_fee);
        }

        return new SearchHit(
            type: $this->type(),
            id: $model->getKey(),
            title: (string) $model->name,
            subtitle: $model->category?->name,
            badge: $model->status?->label(),
            badgeColor: $model->status?->color(),
            meta: $meta,
            url: $this->urlFor('admin.courses.show', [$model->getKey()], $viewer),
        );
    }
}
