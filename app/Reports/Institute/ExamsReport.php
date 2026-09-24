<?php

declare(strict_types=1);

namespace App\Reports\Institute;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\ExamStatus;
use App\Enums\ExamType;
use App\Enums\ReportGroup;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\Exam;
use App\Models\Institute\Teacher;
use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `in.exams` - every exam with how it went (requirement 99).
 *
 * **The statistics are read from the exam row, not recounted.** `expected_count`, `appeared_count`,
 * `absent_count`, `passed_count`, `average_percentage` and the rest are maintained by `ExamService`
 * and `ResultCalculator` whenever a result is entered, amended or published - which is precisely the
 * INV-23-1 arrangement. Recounting `exam_results` here would be a second pass rate, and the one on
 * the exam's own page is the one a teacher has already seen.
 *
 * **Pass rate is derived from the two stored counts rather than stored itself**, because it is the
 * ratio of numbers that are stored and a third column would be a third thing to keep in step. It is
 * computed against `appeared_count`, never `expected_count`: a student who did not sit the exam has
 * not failed it, and dividing by the expected count would quietly penalise a batch for absences.
 */
final class ExamsReport extends Report
{
    public function key(): string
    {
        return 'in.exams';
    }

    public function title(): string
    {
        return 'Exams';
    }

    public function description(): string
    {
        return 'Every exam with how many were expected, how many sat it, the pass rate and the average.';
    }

    public function icon(): string
    {
        return 'clipboard-document-list';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Institute;
    }

    public function module(): string
    {
        return 'exams';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('exams.scheduled_date', 'Held on');
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::link('exam', 'Exam'),
            ColumnDefinition::badge('type', 'Type'),
            ColumnDefinition::text('course', 'Course'),
            ColumnDefinition::text('batch', 'Batch'),
            ColumnDefinition::date('date', 'Date'),
            ColumnDefinition::number('total_marks', 'Total marks'),
            ColumnDefinition::number('passing_marks', 'Passing marks'),
            ColumnDefinition::number('expected', 'Expected', 'sum'),
            ColumnDefinition::number('appeared', 'Appeared', 'sum'),
            ColumnDefinition::number('absent', 'Absent', 'sum'),
            ColumnDefinition::percent('pass_rate', 'Pass rate %'),
            ColumnDefinition::percent('average', 'Average %'),
            ColumnDefinition::badge('status', 'Status'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::multiselect('exam_type', 'Type', ExamType::options()),
            FilterDefinition::multiselect('status', 'Status', ExamStatus::options()),
            FilterDefinition::select('course_id', 'Course', static fn (): array => Course::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::select('batch_id', 'Batch', static fn (): array => Batch::query()->orderByDesc('start_date')->limit(200)->pluck('name', 'id')->all()),
            FilterDefinition::select('teacher_id', 'Teacher', static fn (): array => Teacher::query()->orderBy('name')->pluck('name', 'id')->all()),
        ];
    }

    public function groupBy(): array
    {
        return ['type' => 'Type', 'course' => 'Course', 'batch' => 'Batch', 'status' => 'Status'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $query = Exam::query()
            ->with(['course:id,name', 'batch:id,name'])
            ->whereBetween('exams.scheduled_date', [
                $request->range->start()->toDateString(),
                $request->range->end()->toDateString(),
            ]);

        foreach (['exam_type' => 'exam_type', 'status' => 'status'] as $filter => $column) {
            if ($request->hasFilter($filter)) {
                $query->whereIn('exams.'.$column, (array) $request->filter($filter));
            }
        }

        foreach (['course_id', 'batch_id', 'teacher_id'] as $filter) {
            if ($request->hasFilter($filter)) {
                $query->where('exams.'.$filter, (int) $request->filter($filter));
            }
        }

        $rows = [];
        $totals = ['expected' => 0, 'appeared' => 0, 'absent' => 0];

        $query->orderBy('exams.id')->chunkById(200, function ($exams) use (&$rows, &$totals, $columns): void {
            foreach ($exams as $exam) {
                $appeared = (int) ($exam->appeared_count ?? 0);
                $passed = (int) ($exam->passed_count ?? 0);

                // Against those who sat it - see the class note. No appearances means no rate, not
                // a zero: 0% pass on an exam nobody took is a statement about nothing.
                $passRate = $appeared > 0
                    ? Money::round(Money::mul(Money::div((string) $passed, (string) $appeared), '100'), 2)
                    : null;

                $rows[] = $this->row($columns, [
                    'exam' => $exam->name,
                    'type' => $exam->exam_type?->label(),
                    'course' => $exam->course?->name,
                    'batch' => $exam->batch?->name,
                    'date' => app_date($exam->scheduled_date),
                    'total_marks' => $exam->total_marks,
                    'passing_marks' => $exam->passing_marks,
                    'expected' => $exam->expected_count,
                    'appeared' => $appeared,
                    'absent' => $exam->absent_count,
                    'pass_rate' => $passRate,
                    'average' => $exam->average_percentage,
                    'status' => $exam->status?->label(),
                ]);

                $totals['expected'] += (int) ($exam->expected_count ?? 0);
                $totals['appeared'] += $appeared;
                $totals['absent'] += (int) ($exam->absent_count ?? 0);
            }
        }, 'exams.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'exam statistics, maintained by ExamService'],
        );
    }
}
