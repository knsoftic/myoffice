<?php

declare(strict_types=1);

namespace App\Reports\Institute;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\ClassSessionStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ReportGroup;
use App\Enums\TeacherStatus;
use App\Models\Branch;
use App\Models\Institute\Course;
use App\Models\Institute\Teacher;
use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `in.teachers` - the teaching staff and their load (requirement 99).
 *
 * **`salary` is gated on `teachers.view_financial`**, which 99 marks with its `*`. A teacher's pay
 * next to their batch count is a performance-and-cost table, and the people who schedule classes are
 * not usually the people who set salaries.
 *
 * **Students are counted through active enrolments on the teacher's batches**, not through
 * `batches.current_students` summed. The two normally agree, and when they do not it is because a
 * batch was cancelled with its count left standing - and a teacher's load should follow the students
 * who are actually turning up.
 */
final class TeachersReport extends Report
{
    public function key(): string
    {
        return 'in.teachers';
    }

    public function title(): string
    {
        return 'Teachers';
    }

    public function description(): string
    {
        return 'Teaching staff with their qualification, experience, current load and how many sessions they have held.';
    }

    public function icon(): string
    {
        return 'presentation-chart-line';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Institute;
    }

    public function module(): string
    {
        return 'teachers';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('teachers.joining_date', 'Joined on');
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::link('teacher', 'Teacher'),
            ColumnDefinition::text('code', 'Code'),
            ColumnDefinition::text('qualification', 'Qualification'),
            ColumnDefinition::number('experience', 'Experience (years)'),
            ColumnDefinition::number('courses', 'Courses', 'sum'),
            ColumnDefinition::number('batches', 'Batches', 'sum'),
            ColumnDefinition::number('students', 'Students', 'sum'),
            ColumnDefinition::number('sessions_held', 'Sessions held', 'sum'),
            ColumnDefinition::percent('attendance', 'Avg attendance %'),
            ColumnDefinition::badge('status', 'Status'),
            ColumnDefinition::money('salary', 'Salary', 'teachers', total: null),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::multiselect('status', 'Status', TeacherStatus::options()),
            FilterDefinition::select('course_id', 'Course', static fn (): array => Course::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::select('branch_id', 'Branch', static fn (): array => Branch::query()->orderBy('name')->pluck('name', 'id')->all()),
        ];
    }

    public function groupBy(): array
    {
        return ['status' => 'Status'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $query = Teacher::query()
            ->withCount([
                'courses',
                'batches',
                'sessions as sessions_held_count' => static fn ($q) => $q->where('status', ClassSessionStatus::Held->value),
            ])
            ->whereBetween('teachers.joining_date', [
                $request->range->start()->toDateString(),
                $request->range->end()->toDateString(),
            ]);

        if ($request->hasFilter('status')) {
            $query->whereIn('teachers.status', (array) $request->filter('status'));
        }

        if ($request->hasFilter('branch_id')) {
            $query->where('teachers.branch_id', (int) $request->filter('branch_id'));
        }

        if ($request->hasFilter('course_id')) {
            $course = (int) $request->filter('course_id');
            $query->whereHas('batches', static fn ($q) => $q->where('course_id', $course));
        }

        $rows = [];
        $totals = ['courses' => 0, 'batches' => 0, 'students' => 0, 'sessions_held' => 0];

        $query->orderBy('teachers.id')->chunkById(100, function ($teachers) use (&$rows, &$totals, $columns): void {
            foreach ($teachers as $teacher) {
                // Counted through the enrolments, not summed from the batch counters - see the
                // class note. One aggregate per teacher rather than one per batch.
                $students = $teacher->batches()
                    ->join('student_batch_enrollments as e', 'e.batch_id', '=', 'batches.id')
                    ->whereNull('e.deleted_at')
                    ->where('e.status', EnrollmentStatus::Active->value)
                    ->count();

                $attendance = $teacher->batches()
                    ->join('student_batch_enrollments as e', 'e.batch_id', '=', 'batches.id')
                    ->whereNull('e.deleted_at')
                    ->where('e.status', EnrollmentStatus::Active->value)
                    ->avg('e.attendance_percentage');

                $rows[] = $this->row($columns, [
                    'teacher' => $teacher->name,
                    'code' => $teacher->teacher_code,
                    'qualification' => $teacher->qualification,
                    'experience' => $teacher->experience_years,
                    'courses' => $teacher->courses_count,
                    'batches' => $teacher->batches_count,
                    'students' => $students,
                    'sessions_held' => $teacher->sessions_held_count,
                    'attendance' => $attendance === null ? null : Money::round((string) $attendance, 2),
                    'status' => $teacher->status?->label(),
                    'salary' => (string) ($teacher->salary ?? '0.00'),
                ]);

                $totals['courses'] += (int) $teacher->courses_count;
                $totals['batches'] += (int) $teacher->batches_count;
                $totals['students'] += $students;
                $totals['sessions_held'] += (int) $teacher->sessions_held_count;
            }
        }, 'teachers.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'teaching staff'],
        );
    }
}
