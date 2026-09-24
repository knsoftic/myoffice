<?php

declare(strict_types=1);

namespace App\Reports\Institute;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\BatchStatus;
use App\Enums\ReportGroup;
use App\Models\Branch;
use App\Models\Institute\Course;
use App\Models\Institute\Teacher;
use App\Models\User;
use App\Reports\Report;
use App\Services\Institute\AttendanceReportService;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `in.attendance` - attendance per batch (requirement 99).
 *
 * **INV-23-1 at its most literal: this delegates to `AttendanceReportService::batchSummary()` and
 * shapes nothing.** 99 says "its four reports, unchanged" and phase-14-17 6.9 already owns the
 * arithmetic - which sessions count as held, how a cancelled session affects the denominator, and
 * what `institute.attendance_minimum_percentage` means. A second implementation here would be a
 * second answer to "did this student meet the attendance requirement", and that question decides
 * whether a certificate is issued.
 *
 * The service is asked for the batch shape because that is the one a report of this grain needs;
 * the daily and monthly shapes belong to the screens that show a register.
 */
final class StudentAttendanceReport extends Report
{
    public function __construct(
        private readonly AttendanceReportService $attendance,
    ) {}

    public function key(): string
    {
        return 'in.attendance';
    }

    public function title(): string
    {
        return 'Student Attendance';
    }

    public function description(): string
    {
        return 'Sessions held and average attendance per batch, with the students falling below the minimum.';
    }

    public function icon(): string
    {
        return 'calendar-days';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Institute;
    }

    public function module(): string
    {
        return 'student_attendance';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('class_sessions.session_date', 'Session held on', required: true);
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::text('batch', 'Batch'),
            ColumnDefinition::text('course', 'Course'),
            ColumnDefinition::text('teacher', 'Teacher'),
            ColumnDefinition::number('sessions_planned', 'Planned', 'sum'),
            ColumnDefinition::number('sessions_held', 'Held', 'sum'),
            ColumnDefinition::number('sessions_cancelled', 'Cancelled', 'sum'),
            ColumnDefinition::number('students', 'Students', 'sum'),
            ColumnDefinition::percent('average', 'Average %'),
            ColumnDefinition::number('below_minimum', 'Below minimum', 'sum'),
            ColumnDefinition::date('last_session', 'Last session'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::select('course_id', 'Course', static fn (): array => Course::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::select('teacher_id', 'Teacher', static fn (): array => Teacher::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::select('status', 'Batch status', BatchStatus::options()),
            FilterDefinition::select('branch_id', 'Branch', static fn (): array => Branch::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::boolean('below_minimum_only', 'Only batches with students below the minimum'),
        ];
    }

    public function groupBy(): array
    {
        return ['course' => 'Course', 'teacher' => 'Teacher'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        // The service takes plain filter keys; only the ones actually set are passed, because it
        // uses `isset()` to decide whether to apply each clause.
        $filters = [];

        foreach (['course_id', 'teacher_id', 'branch_id'] as $key) {
            if ($request->hasFilter($key)) {
                $filters[$key] = (int) $request->filter($key);
            }
        }

        if ($request->hasFilter('status')) {
            $filters['status'] = (string) $request->filter('status');
        }

        $summary = $this->attendance->batchSummary(
            \Illuminate\Support\Carbon::parse($request->range->start()->toDateString()),
            \Illuminate\Support\Carbon::parse($request->range->end()->toDateString()),
            $filters,
        );

        $onlyBelow = $request->booleanFilter('below_minimum_only') === true;

        $rows = [];
        $totals = [
            'sessions_planned' => 0,
            'sessions_held' => 0,
            'sessions_cancelled' => 0,
            'students' => 0,
            'below_minimum' => 0,
        ];

        foreach ($summary['rows'] ?? [] as $batch) {
            $batch = (object) $batch;
            $below = (int) ($batch->below_minimum ?? 0);

            if ($onlyBelow && $below === 0) {
                continue;
            }

            $rows[] = $this->row($columns, [
                'batch' => $batch->name ?? $batch->code ?? null,
                'course' => $batch->course_name ?? null,
                'teacher' => $batch->teacher_name ?? null,
                'sessions_planned' => $batch->sessions_planned ?? 0,
                'sessions_held' => $batch->sessions_held ?? 0,
                'sessions_cancelled' => $batch->sessions_cancelled ?? 0,
                'students' => $batch->students ?? 0,
                'average' => $batch->average ?? null,
                'below_minimum' => $below,
                'last_session' => app_date($batch->last_session_on ?? null),
            ]);

            $totals['sessions_planned'] += (int) ($batch->sessions_planned ?? 0);
            $totals['sessions_held'] += (int) ($batch->sessions_held ?? 0);
            $totals['sessions_cancelled'] += (int) ($batch->sessions_cancelled ?? 0);
            $totals['students'] += (int) ($batch->students ?? 0);
            $totals['below_minimum'] += $below;
        }

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: [
                'basis' => 'sessions held in the period, per batch',
                // Taken from the service's own answer rather than re-read from settings: if the two
                // ever differed, the report would print one threshold and have counted by another.
                'minimum_percentage' => (string) ($summary['minimum'] ?? ''),
            ],
        );
    }
}
