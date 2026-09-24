<?php

declare(strict_types=1);

namespace App\Reports\Institute;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\BatchStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ReportGroup;
use App\Models\Branch;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\Teacher;
use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `in.batches` - every batch with how full it is and how far through (requirement 99).
 *
 * **Seats left is derived here; `current_students` is not.** The count is a column Phase 14
 * maintains inside the enrolment transaction, so it cannot drift from the enrolment rows. Subtracting
 * it from the capacity is arithmetic on two stored numbers, which is a different thing from
 * recomputing either of them.
 *
 * **Seats left can be negative, and is shown that way.** An overbooked batch is a real state
 * (`student_batch_enrollments.is_overbooked`), and clamping the figure at zero would hide exactly
 * the batches somebody needs to look at.
 *
 * **Average attendance comes from the enrolment rows' own maintained percentage**, averaged over
 * active enrolments only. Including a student who left in week two would drag the figure down with
 * sessions they were never expected at.
 */
final class BatchesReport extends Report
{
    public function key(): string
    {
        return 'in.batches';
    }

    public function title(): string
    {
        return 'Batches';
    }

    public function description(): string
    {
        return 'Every batch with its teacher, dates, capacity, how many seats are left, syllabus progress and average attendance.';
    }

    public function icon(): string
    {
        return 'user-group';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Institute;
    }

    public function module(): string
    {
        return 'batches';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('batches.start_date', 'Starts on', [
            'batches.end_date' => 'Ends on',
            'batches.completed_on' => 'Completed on',
        ]);
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::link('batch', 'Batch'),
            ColumnDefinition::text('course', 'Course'),
            ColumnDefinition::text('teacher', 'Teacher'),
            ColumnDefinition::date('start', 'Start'),
            ColumnDefinition::date('end', 'End'),
            ColumnDefinition::number('capacity', 'Capacity', 'sum'),
            ColumnDefinition::number('enrolled', 'Enrolled', 'sum'),
            ColumnDefinition::number('seats_left', 'Seats left', 'sum'),
            ColumnDefinition::badge('status', 'Status'),
            ColumnDefinition::percent('syllabus', 'Syllabus %'),
            ColumnDefinition::percent('attendance', 'Avg attendance %'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::multiselect('status', 'Status', BatchStatus::options()),
            FilterDefinition::select('course_id', 'Course', static fn (): array => Course::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::select('teacher_id', 'Teacher', static fn (): array => Teacher::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::select('branch_id', 'Branch', static fn (): array => Branch::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::boolean('has_seats', 'Has seats left'),
        ];
    }

    public function groupBy(): array
    {
        return ['course' => 'Course', 'teacher' => 'Teacher', 'status' => 'Status'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $dateColumn = $this->dateFilter()->resolve($request->dateColumn);

        $query = Batch::query()
            ->with(['course:id,name', 'teacher:id,name'])
            ->withAvg(
                ['enrollments as average_attendance' => static fn ($q) => $q->where('status', EnrollmentStatus::Active->value)],
                'attendance_percentage',
            )
            ->whereBetween($dateColumn, [
                $request->range->start()->toDateString(),
                $request->range->end()->toDateString(),
            ]);

        if ($request->hasFilter('status')) {
            $query->whereIn('batches.status', (array) $request->filter('status'));
        }

        foreach (['course_id', 'teacher_id', 'branch_id'] as $filter) {
            if ($request->hasFilter($filter)) {
                $query->where('batches.'.$filter, (int) $request->filter($filter));
            }
        }

        $hasSeats = $request->booleanFilter('has_seats');

        if ($hasSeats !== null) {
            // A batch with no capacity set is unlimited, not full - the column is nullable and the
            // two readings are opposites, so the null case is spelled out rather than left to the
            // comparison.
            $hasSeats
                ? $query->where(static fn ($q) => $q->whereNull('batches.student_capacity')
                    ->orWhereColumn('batches.current_students', '<', 'batches.student_capacity'))
                : $query->whereNotNull('batches.student_capacity')
                    ->whereColumn('batches.current_students', '>=', 'batches.student_capacity');
        }

        $rows = [];
        $totals = ['capacity' => 0, 'enrolled' => 0, 'seats_left' => 0];

        $query->orderBy('batches.id')->chunkById(200, function ($batches) use (&$rows, &$totals, $columns): void {
            foreach ($batches as $batch) {
                $capacity = $batch->student_capacity;
                $enrolled = (int) $batch->current_students;

                // Null capacity means unlimited; there is no number of seats left to report.
                // Negative means overbooked, and is shown - see the class note.
                $seatsLeft = $capacity === null ? null : (int) $capacity - $enrolled;

                $rows[] = $this->row($columns, [
                    'batch' => $batch->name,
                    'course' => $batch->course?->name,
                    'teacher' => $batch->teacher?->name,
                    'start' => app_date($batch->start_date),
                    'end' => app_date($batch->end_date),
                    'capacity' => $capacity,
                    'enrolled' => $enrolled,
                    'seats_left' => $seatsLeft,
                    'status' => $batch->status?->label(),
                    'syllabus' => $batch->syllabus_completion_percentage,
                    'attendance' => $batch->average_attendance === null
                        ? null
                        : Money::round((string) $batch->average_attendance, 2),
                ]);

                $totals['capacity'] += (int) ($capacity ?? 0);
                $totals['enrolled'] += $enrolled;
                $totals['seats_left'] += (int) ($seatsLeft ?? 0);
            }
        }, 'batches.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'batch register'],
        );
    }
}
