<?php

declare(strict_types=1);

namespace App\Reports\Institute;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\Gender;
use App\Enums\ReportGroup;
use App\Enums\StudentStatus;
use App\Models\Branch;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\Student;
use App\Models\User;
use App\Reports\Report;
use App\Support\ReportResult;

/**
 * `in.students` - the student register (requirement 99).
 *
 * **Attendance and progress are read from the enrolment row, not recomputed.**
 * `student_batch_enrollments.attendance_percentage` and `student_course_progress.progress_percentage`
 * are maintained by Phase 17 and Phase 20 inside the transactions that change them. Recomputing here
 * would mean this report and the student's own page could differ, and a student told two different
 * attendance figures by the same system has no reason to believe either.
 *
 * **`collaborator_id` on a student is the attribution of record**, unlike the snapshot on a project:
 * the commission engine reads it, so filtering on it here is filtering on the same column the money
 * follows.
 */
final class StudentsReport extends Report
{
    public function key(): string
    {
        return 'in.students';
    }

    public function title(): string
    {
        return 'Students';
    }

    public function description(): string
    {
        return 'The student register with course, batch, status, attendance and progress.';
    }

    public function icon(): string
    {
        return 'academic-cap';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Institute;
    }

    public function module(): string
    {
        return 'students';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('students.joining_date', 'Joined on', [
            'students.created_at' => 'Added on',
        ]);
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::link('student', 'Student'),
            ColumnDefinition::text('code', 'Code'),
            ColumnDefinition::text('registration_no', 'Registration no'),
            ColumnDefinition::text('course', 'Course'),
            ColumnDefinition::text('batch', 'Batch'),
            ColumnDefinition::badge('status', 'Status'),
            ColumnDefinition::date('joining', 'Joined'),
            ColumnDefinition::text('city', 'City'),
            ColumnDefinition::text('collaborator', 'Collaborator'),
            ColumnDefinition::percent('attendance', 'Attendance %'),
            ColumnDefinition::percent('progress', 'Progress %'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::multiselect('status', 'Status', StudentStatus::options()),
            FilterDefinition::select('course_id', 'Course', static fn (): array => Course::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::select('batch_id', 'Batch', static fn (): array => Batch::query()->orderByDesc('start_date')->limit(200)->pluck('name', 'id')->all()),
            FilterDefinition::select('branch_id', 'Branch', static fn (): array => Branch::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::entity('collaborator_id', 'Collaborator', 'collaborators.view_any'),
            FilterDefinition::select('gender', 'Gender', Gender::options()),
            FilterDefinition::text('city', 'City'),
        ];
    }

    public function groupBy(): array
    {
        return ['status' => 'Status', 'course' => 'Course', 'batch' => 'Batch', 'city' => 'City'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $dateColumn = $this->dateFilter()->resolve($request->dateColumn);

        $query = Student::query()
            ->with([
                'enrollments' => static fn ($q) => $q->with(['batch:id,name,course_id', 'batch.course:id,name'])->latest('id')->limit(1),
                'collaborator:id,name',
            ])
            ->whereBetween($dateColumn, [$request->range->start(), $request->range->end()]);

        if ($request->hasFilter('status')) {
            $query->whereIn('students.status', (array) $request->filter('status'));
        }

        if ($request->hasFilter('gender')) {
            $query->where('students.gender', $request->filter('gender'));
        }

        if ($request->hasFilter('city')) {
            $query->where('students.city', 'like', '%'.$request->filter('city').'%');
        }

        foreach (['branch_id', 'collaborator_id'] as $filter) {
            if ($request->hasFilter($filter)) {
                $query->where('students.'.$filter, (int) $request->filter($filter));
            }
        }

        foreach (['course_id' => 'course_id', 'batch_id' => 'batch_id'] as $filter => $column) {
            if ($request->hasFilter($filter)) {
                $value = (int) $request->filter($filter);
                $query->whereHas('enrollments', static fn ($q) => $q->where($column, $value));
            }
        }

        $rows = [];

        $query->orderBy('students.id')->chunkById(200, function ($students) use (&$rows, $columns): void {
            foreach ($students as $student) {
                $enrollment = $student->enrollments->first();

                $rows[] = $this->row($columns, [
                    'student' => $student->name,
                    'code' => $student->student_code,
                    'registration_no' => $student->registration_number,
                    'course' => $enrollment?->batch?->course?->name,
                    'batch' => $enrollment?->batch?->name,
                    'status' => $student->status?->label(),
                    'joining' => app_date($student->joining_date),
                    'city' => $student->city,
                    'collaborator' => $student->collaborator?->name,
                    // Maintained figures, read - see the class note.
                    'attendance' => $enrollment?->attendance_percentage,
                    'progress' => $enrollment?->progress_percentage,
                ]);
            }
        }, 'students.id', 'id');

        return new ReportResult(rows: $rows, meta: ['basis' => 'student register']);
    }
}
