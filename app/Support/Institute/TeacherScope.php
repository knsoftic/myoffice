<?php

declare(strict_types=1);

namespace App\Support\Institute;

use App\Models\Institute\Batch;
use App\Models\Institute\Teacher;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Which batches are this teacher's?" — asked once, in one place (phase-19-23 §6.6).
 *
 * **A teacher reaches a batch four ways, and leaving one out is a real bug rather than a nuance.**
 * They may be the batch's named teacher, hold a timetable entry on it, have taught one of its
 * sessions, or have been the teacher a session was *moved off* — the substitute case, where
 * `original_teacher_id` is the only record that they were ever involved.
 *
 * The four were previously written out in three controllers with three different answers: the batch
 * screen counted three of them, the progress screen counted two (a substitute who covered a class could
 * see it on attendance and not on progress), and the attendance screen counted a fourth the others did
 * not. That is the D107 shape — one question with several answers in several files — and Phase 19
 * needs the same query for materials, so it is written here instead of a fourth time.
 *
 * Deliberately **not** a global scope: a global scope would silently narrow admin queries too, and staff
 * reading every batch is the normal case. This is opt-in, applied by the caller that means it.
 */
final class TeacherScope
{
    /**
     * Constrain a `batches` query to this teacher's.
     *
     * @param  string|null  $column  when the query is not on `batches` itself, the column holding the
     *                               batch id — e.g. `assignments.batch_id`
     */
    public static function apply(Builder $query, Teacher|int $teacher, ?string $column = null): Builder
    {
        $teacherId = $teacher instanceof Teacher ? (int) $teacher->getKey() : $teacher;

        if ($column !== null) {
            return $query->whereIn($column, self::batchIds($teacherId));
        }

        return $query->where(static function (Builder $q) use ($teacherId): void {
            $q->where('teacher_id', $teacherId)
                ->orWhereHas('timetableEntries', static fn (Builder $e): Builder => $e->where('teacher_id', $teacherId))
                ->orWhereHas('sessions', static fn (Builder $s): Builder => $s
                    ->where('teacher_id', $teacherId)
                    ->orWhere('original_teacher_id', $teacherId));
        });
    }

    /**
     * The ids, for a query that cannot join — the `whereIn` form §6.6's teacher variant uses.
     *
     * @return list<int>
     */
    public static function batchIds(Teacher|int $teacher): array
    {
        $teacherId = $teacher instanceof Teacher ? (int) $teacher->getKey() : $teacher;

        return self::apply(Batch::query(), $teacherId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** Is this one batch theirs? The question every teacher-panel `show()` asks before rendering. */
    public static function owns(Teacher|int $teacher, Batch|int $batch): bool
    {
        $batchId = $batch instanceof Batch ? (int) $batch->getKey() : $batch;

        return self::apply(Batch::query(), $teacher)->whereKey($batchId)->exists();
    }
}
