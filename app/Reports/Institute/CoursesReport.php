<?php

declare(strict_types=1);

namespace App\Reports\Institute;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\CourseLevel;
use App\Enums\CourseStatus;
use App\Enums\DeliveryMode;
use App\Enums\EnrollmentStatus;
use App\Enums\ReportGroup;
use App\Models\Institute\Course;
use App\Models\Institute\CourseCategory;
use App\Models\User;
use App\Reports\Report;
use App\Support\ReportResult;

/**
 * `in.courses` - the catalogue, with how each course is actually doing (requirement 99).
 *
 * **`course_fee` is a money column and is gated**, which the catalogue screen is not. The public
 * price list is public; a report that lines every fee up next to enrolment counts is a commercial
 * document, and 99 marks the fee column with the `*` that means `view_financial`.
 *
 * The counts are `withCount` subqueries rather than a query per course: a catalogue of sixty
 * courses would otherwise be a hundred and eighty queries.
 */
final class CoursesReport extends Report
{
    public function key(): string
    {
        return 'in.courses';
    }

    public function title(): string
    {
        return 'Courses';
    }

    public function description(): string
    {
        return 'The catalogue with batches, active and completed students, and whether a certificate is offered.';
    }

    public function icon(): string
    {
        return 'book-open';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Institute;
    }

    public function module(): string
    {
        return 'courses';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('courses.created_at', 'Added on', [
            'courses.published_at' => 'Published on',
        ]);
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::link('course', 'Course'),
            ColumnDefinition::text('code', 'Code'),
            ColumnDefinition::text('category', 'Category'),
            ColumnDefinition::badge('level', 'Level'),
            ColumnDefinition::badge('mode', 'Mode'),
            ColumnDefinition::text('duration', 'Duration'),
            ColumnDefinition::money('fee', 'Fee', 'courses', total: null),
            ColumnDefinition::number('batches', 'Batches', 'sum'),
            ColumnDefinition::number('active_students', 'Active students', 'sum'),
            ColumnDefinition::number('completed_students', 'Completed', 'sum'),
            ColumnDefinition::badge('certificate', 'Certificate'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::select('course_category_id', 'Category', static fn (): array => CourseCategory::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::multiselect('level', 'Level', CourseLevel::options()),
            FilterDefinition::multiselect('delivery_mode', 'Mode', DeliveryMode::options()),
            FilterDefinition::multiselect('status', 'Status', CourseStatus::options()),
            new FilterDefinition(
                key: 'fee_range',
                label: 'Fee between',
                type: \App\Enums\ReportFilterType::NumberRange,
                // Gated with the column it filters: a fee band that made rows appear and disappear
                // would let somebody without `view_financial` binary-search the price.
                permission: 'courses.view_financial',
                span: 4,
            ),
        ];
    }

    public function groupBy(): array
    {
        return ['category' => 'Category', 'level' => 'Level', 'mode' => 'Mode'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $dateColumn = $this->dateFilter()->resolve($request->dateColumn);

        $query = Course::query()
            ->with('category:id,name')
            ->withCount([
                'batches',
                'enrollments as active_students_count' => static fn ($q) => $q->where('status', EnrollmentStatus::Active->value),
                'enrollments as completed_students_count' => static fn ($q) => $q->where('status', EnrollmentStatus::Completed->value),
            ])
            ->whereBetween($dateColumn, [$request->range->start(), $request->range->end()]);

        foreach (['level' => 'level', 'delivery_mode' => 'delivery_mode', 'status' => 'status'] as $filter => $column) {
            if ($request->hasFilter($filter)) {
                $query->whereIn('courses.'.$column, (array) $request->filter($filter));
            }
        }

        if ($request->hasFilter('course_category_id')) {
            $query->where('courses.course_category_id', (int) $request->filter('course_category_id'));
        }

        $feeRange = (array) $request->filter('fee_range', []);

        if (($feeRange['min'] ?? null) !== null && $feeRange['min'] !== '') {
            $query->where('courses.course_fee', '>=', $feeRange['min']);
        }

        if (($feeRange['max'] ?? null) !== null && $feeRange['max'] !== '') {
            $query->where('courses.course_fee', '<=', $feeRange['max']);
        }

        $rows = [];
        $totals = ['batches' => 0, 'active_students' => 0, 'completed_students' => 0];

        $query->orderBy('courses.id')->chunkById(100, function ($courses) use (&$rows, &$totals, $columns): void {
            foreach ($courses as $course) {
                $rows[] = $this->row($columns, [
                    'course' => $course->name,
                    'code' => $course->code,
                    'category' => $course->category?->name,
                    'level' => $course->level?->label(),
                    'mode' => $course->delivery_mode?->label(),
                    'duration' => trim(($course->duration_value ?? '').' '.($course->duration_unit?->label() ?? '')),
                    'fee' => (string) $course->course_fee,
                    'batches' => $course->batches_count,
                    'active_students' => $course->active_students_count,
                    'completed_students' => $course->completed_students_count,
                    'certificate' => $course->certificate_available ? 'Yes' : 'No',
                ]);

                $totals['batches'] += (int) $course->batches_count;
                $totals['active_students'] += (int) $course->active_students_count;
                $totals['completed_students'] += (int) $course->completed_students_count;
            }
        }, 'courses.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'course catalogue'],
        );
    }
}
