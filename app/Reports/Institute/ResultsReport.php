<?php

declare(strict_types=1);

namespace App\Reports\Institute;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\ExamType;
use App\Enums\ReportGroup;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\Exam;
use App\Models\Institute\ExamResult;
use App\Models\User;
use App\Reports\Report;
use App\Support\ReportResult;

/**
 * `in.results` - every mark, with its grade and position (requirement 99).
 *
 * **Only published results.** An unpublished mark is a draft: it may still be amended, it has not
 * been verified, and the student has not seen it. Putting drafts on a report that people export and
 * email would leak marks before the institute meant to release them, and would do it in a file
 * nobody can recall.
 *
 * **`percentage`, `grade` and `position_in_batch` are read.** `ResultCalculator` computes them
 * against the exam's own grade scale when the sheet is saved, and the grade scale can be edited
 * afterwards - so recomputing here would silently re-grade a published result against today's
 * bands. The stored grade is the one on the student's result card.
 */
final class ResultsReport extends Report
{
    public function key(): string
    {
        return 'in.results';
    }

    public function title(): string
    {
        return 'Results';
    }

    public function description(): string
    {
        return 'Published marks with their percentage, grade, pass or fail and position in the batch.';
    }

    public function icon(): string
    {
        return 'chart-bar-square';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Institute;
    }

    public function module(): string
    {
        return 'results';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('exam_results.published_at', 'Published on', [
            'exams.scheduled_date' => 'Exam held on',
        ]);
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::link('student', 'Student'),
            ColumnDefinition::text('exam', 'Exam'),
            ColumnDefinition::badge('type', 'Type'),
            ColumnDefinition::text('batch', 'Batch'),
            ColumnDefinition::number('obtained', 'Obtained'),
            ColumnDefinition::number('total', 'Total'),
            ColumnDefinition::percent('percentage', 'Percentage'),
            ColumnDefinition::badge('grade', 'Grade'),
            ColumnDefinition::badge('outcome', 'Outcome'),
            ColumnDefinition::number('position', 'Position'),
            ColumnDefinition::text('remarks', 'Remarks'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::select('exam_id', 'Exam', static fn (): array => Exam::query()->orderByDesc('scheduled_date')->limit(200)->pluck('name', 'id')->all()),
            FilterDefinition::multiselect('exam_type', 'Type', ExamType::options()),
            FilterDefinition::select('course_id', 'Course', static fn (): array => Course::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::select('batch_id', 'Batch', static fn (): array => Batch::query()->orderByDesc('start_date')->limit(200)->pluck('name', 'id')->all()),
            FilterDefinition::text('grade', 'Grade'),
            FilterDefinition::boolean('is_passed', 'Passed'),
            FilterDefinition::numberRange('percentage_range', 'Percentage between'),
        ];
    }

    public function groupBy(): array
    {
        return ['exam' => 'Exam', 'batch' => 'Batch', 'grade' => 'Grade', 'outcome' => 'Outcome'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $dateColumn = $this->dateFilter()->resolve($request->dateColumn);

        $query = ExamResult::query()
            ->join('exams', 'exams.id', '=', 'exam_results.exam_id')
            ->select('exam_results.*')
            ->with(['student:id,name', 'exam:id,name,exam_type', 'batch:id,name'])
            // Published only - see the class note.
            ->whereNotNull('exam_results.published_at')
            ->whereBetween($dateColumn, [$request->range->start(), $request->range->end()]);

        if ($request->hasFilter('exam_id')) {
            $query->where('exam_results.exam_id', (int) $request->filter('exam_id'));
        }

        if ($request->hasFilter('exam_type')) {
            $query->whereIn('exams.exam_type', (array) $request->filter('exam_type'));
        }

        foreach (['course_id', 'batch_id'] as $filter) {
            if ($request->hasFilter($filter)) {
                $query->where('exam_results.'.$filter, (int) $request->filter($filter));
            }
        }

        if ($request->hasFilter('grade')) {
            $query->where('exam_results.grade', $request->filter('grade'));
        }

        $passed = $request->booleanFilter('is_passed');

        if ($passed !== null) {
            $query->where('exam_results.is_passed', $passed);
        }

        $range = (array) $request->filter('percentage_range', []);

        if (($range['min'] ?? '') !== '') {
            $query->where('exam_results.percentage', '>=', $range['min']);
        }

        if (($range['max'] ?? '') !== '') {
            $query->where('exam_results.percentage', '<=', $range['max']);
        }

        $rows = [];

        $query->orderBy('exam_results.id')->chunkById(300, function ($results) use (&$rows, $columns): void {
            foreach ($results as $result) {
                $rows[] = $this->row($columns, [
                    'student' => $result->student?->name,
                    'exam' => $result->exam?->name,
                    'type' => $result->exam?->exam_type?->label(),
                    'batch' => $result->batch?->name,
                    'obtained' => $result->obtained_marks,
                    'total' => $result->total_marks,
                    'percentage' => $result->percentage,
                    'grade' => $result->grade,
                    'outcome' => $result->is_passed === null ? null : ($result->is_passed ? 'Pass' : 'Fail'),
                    'position' => $result->position_in_batch,
                    'remarks' => $result->remarks,
                ]);
            }
        }, 'exam_results.id', 'id');

        return new ReportResult(
            rows: $rows,
            meta: ['basis' => 'published results only'],
        );
    }
}
