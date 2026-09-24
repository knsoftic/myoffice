<?php

declare(strict_types=1);

namespace App\Search\Providers;

use App\DataObjects\Search\SearchHit;
use App\Enums\SearchEntityType;
use App\Models\Institute\Student;
use App\Models\User;
use App\Search\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Students (phase-19-23 6.23, 9.5).
 *
 * **This is the provider 108 is really about.** "A student searching Ahmed can never surface
 * another student" - so the student case is not a narrower list, it is exactly one row, matched by
 * `user_id`. A teacher sees the students of their own batches. Everybody else needs
 * `students.view_any`, and without it sees nothing rather than everything.
 *
 * **The default is deny.** A viewer who is neither staff with the permission, nor a teacher, nor
 * the student themselves gets an empty collection. Written as an explicit final `else` rather than
 * left to fall through, because a missing branch in a scope like this is a disclosure.
 */
final class StudentSearchProvider extends Provider
{
    public function type(): SearchEntityType
    {
        return SearchEntityType::Student;
    }

    public function columns(): array
    {
        return [
            'students.name',
            'students.student_code',
            'students.registration_number',
            'students.phone',
            'students.cnic',
            'students.email',
        ];
    }

    public function exactColumns(): array
    {
        return ['student_code', 'registration_number'];
    }

    public function query(string $term, User $viewer, int $limit): Collection
    {
        $query = Student::query()->select([
            'id', 'name', 'student_code', 'registration_number', 'phone', 'email', 'cnic', 'status', 'user_id', 'branch_id',
        ]);

        if ($this->can($viewer, 'view_any')) {
            // Staff with the register. Branch scope still applies where the model declares one.
            $branchId = $viewer->getAttribute('branch_id');

            if ($branchId !== null && ! $this->can($viewer, 'view_reports')) {
                $query->where(static fn ($q) => $q->whereNull('students.branch_id')->orWhere('students.branch_id', $branchId));
            }
        } elseif ($teacher = $this->teacherFor($viewer)) {
            // Only the students on this teacher's own batches.
            $query->whereExists(static function ($sub) use ($teacher): void {
                $sub->selectRaw('1')
                    ->from('student_batch_enrollments as e')
                    ->join('batches as b', 'b.id', '=', 'e.batch_id')
                    ->whereColumn('e.student_id', 'students.id')
                    ->whereNull('e.deleted_at')
                    ->where('b.teacher_id', $teacher);
            });
        } elseif ($viewer->getAttribute('id') !== null && Student::query()->where('user_id', $viewer->getKey())->exists()) {
            // A student, searching. Their own row, and only their own row.
            $query->where('students.user_id', $viewer->getKey());
        } else {
            // Deny by default - see the class note.
            return collect();
        }

        return $this->match($query, $term)->limit($limit)->get();
    }

    public function present(Model $model, User $viewer): SearchHit
    {
        /** @var Student $model */
        return new SearchHit(
            type: $this->type(),
            id: $model->getKey(),
            title: (string) $model->name,
            subtitle: $model->student_code,
            badge: $model->status?->label(),
            badgeColor: $model->status?->color(),
            meta: ['Registration' => $model->registration_number, 'Phone' => $model->phone],
            url: $this->urlFor('admin.students.show', [$model->getKey()], $viewer),
        );
    }

    /** This user's teacher id, or null. */
    private function teacherFor(User $viewer): ?int
    {
        $id = \Illuminate\Support\Facades\DB::table('teachers')
            ->whereNull('deleted_at')
            ->where('user_id', $viewer->getKey())
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
