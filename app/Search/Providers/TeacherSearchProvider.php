<?php

declare(strict_types=1);

namespace App\Search\Providers;

use App\DataObjects\Search\SearchHit;
use App\Enums\SearchEntityType;
use App\Models\Institute\Teacher;
use App\Models\User;
use App\Search\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Teachers (phase-19-23 6.23).
 *
 * **A student sees the teachers of their own batches**, not the whole faculty. That is a narrower
 * rule than "teachers are public", and it is the right one for the palette even though the website
 * lists public teacher profiles: a student searching inside the portal is asking "who teaches me",
 * and answering with everybody would make the feature useless as well as leaky.
 */
final class TeacherSearchProvider extends Provider
{
    public function type(): SearchEntityType
    {
        return SearchEntityType::Teacher;
    }

    public function columns(): array
    {
        return ['teachers.name', 'teachers.teacher_code', 'teachers.email', 'teachers.phone', 'teachers.specialization'];
    }

    public function exactColumns(): array
    {
        return ['teacher_code'];
    }

    public function query(string $term, User $viewer, int $limit): Collection
    {
        $query = Teacher::query()->select([
            'id', 'name', 'teacher_code', 'email', 'phone', 'specialization', 'qualification', 'status', 'user_id',
        ]);

        if (! $this->can($viewer, 'view_any')) {
            $studentId = \Illuminate\Support\Facades\DB::table('students')
                ->whereNull('deleted_at')
                ->where('user_id', $viewer->getKey())
                ->value('id');

            if ($studentId === null) {
                return collect();
            }

            $query->whereExists(static function ($sub) use ($studentId): void {
                $sub->selectRaw('1')
                    ->from('batches as b')
                    ->join('student_batch_enrollments as e', 'e.batch_id', '=', 'b.id')
                    ->whereColumn('b.teacher_id', 'teachers.id')
                    ->whereNull('b.deleted_at')
                    ->whereNull('e.deleted_at')
                    ->where('e.student_id', $studentId);
            });
        }

        return $this->match($query, $term)->limit($limit)->get();
    }

    public function present(Model $model, User $viewer): SearchHit
    {
        /** @var Teacher $model */
        return new SearchHit(
            type: $this->type(),
            id: $model->getKey(),
            title: (string) $model->name,
            subtitle: $model->specialization ?: $model->qualification,
            badge: $model->status?->label(),
            badgeColor: $model->status?->color(),
            meta: ['Code' => $model->teacher_code],
            url: $this->urlFor('admin.teachers.show', [$model->getKey()], $viewer),
        );
    }
}
