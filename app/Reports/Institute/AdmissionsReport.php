<?php

declare(strict_types=1);

namespace App\Reports\Institute;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\AdmissionStage;
use App\Enums\ReportGroup;
use App\Models\Branch;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\StudentAdmission;
use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `in.admissions` - the admission funnel (requirement 99).
 *
 * **The three money columns are the admission's own locked figures**, not a sum over the fee
 * charges. `charged_amount`, `paid_amount` and `balance_amount` are written by `AdmissionService`
 * and frozen at `figures_locked_at`, which is exactly the point: what was agreed at admission is a
 * different question from what has been charged since, and a funnel report is asking the first one.
 *
 * **Stage is the funnel, and a cancelled admission stays in it.** Dropping cancelled rows would
 * make the conversion rate flatter than it is, and the one number a funnel exists to show is where
 * people stop.
 */
final class AdmissionsReport extends Report
{
    public function key(): string
    {
        return 'in.admissions';
    }

    public function title(): string
    {
        return 'Admissions';
    }

    public function description(): string
    {
        return 'The admission funnel: what stage each admission reached, who counselled it and what was agreed.';
    }

    public function icon(): string
    {
        return 'clipboard-document-check';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Institute;
    }

    public function module(): string
    {
        return 'admissions';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('student_admissions.admission_date', 'Admitted on', [
            'student_admissions.registration_date' => 'Registered on',
            'student_admissions.completed_on' => 'Completed on',
        ]);
    }

    public function columns(): array
    {
        $money = static fn (string $k, string $l): ColumnDefinition => ColumnDefinition::money($k, $l, 'admissions');

        return [
            ColumnDefinition::text('admission_no', 'Admission no'),
            ColumnDefinition::link('student', 'Student'),
            ColumnDefinition::text('course', 'Course'),
            ColumnDefinition::text('batch', 'Batch'),
            ColumnDefinition::badge('stage', 'Stage'),
            ColumnDefinition::date('date', 'Date'),
            ColumnDefinition::text('counsellor', 'Counsellor'),
            ColumnDefinition::text('collaborator', 'Collaborator'),
            $money('agreed_total', 'Agreed total'),
            $money('paid', 'Paid'),
            $money('pending', 'Pending'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::multiselect('stage', 'Stage', AdmissionStage::options()),
            FilterDefinition::select('course_id', 'Course', static fn (): array => Course::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::select('batch_id', 'Batch', static fn (): array => Batch::query()->orderByDesc('start_date')->limit(200)->pluck('name', 'id')->all()),
            FilterDefinition::entity('counselor_id', 'Counsellor', 'users.view_any'),
            FilterDefinition::entity('collaborator_id', 'Collaborator', 'collaborators.view_any'),
            FilterDefinition::select('branch_id', 'Branch', static fn (): array => Branch::query()->orderBy('name')->pluck('name', 'id')->all()),
        ];
    }

    public function groupBy(): array
    {
        return ['stage' => 'Stage', 'course' => 'Course', 'counsellor' => 'Counsellor', 'collaborator' => 'Collaborator'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $dateColumn = $this->dateFilter()->resolve($request->dateColumn);

        $query = StudentAdmission::query()
            ->with(['student:id,name', 'course:id,name', 'counselor:id,name', 'collaborator:id,name'])
            ->whereBetween($dateColumn, [
                $request->range->start()->toDateString(),
                $request->range->end()->toDateString(),
            ]);

        if ($request->hasFilter('stage')) {
            $query->whereIn('student_admissions.stage', (array) $request->filter('stage'));
        }

        foreach (['course_id', 'batch_id', 'counselor_id', 'collaborator_id', 'branch_id'] as $filter) {
            if ($request->hasFilter($filter)) {
                $query->where('student_admissions.'.$filter, (int) $request->filter($filter));
            }
        }

        $rows = [];
        $totals = ['agreed_total' => Money::ZERO, 'paid' => Money::ZERO, 'pending' => Money::ZERO];

        $query->orderBy('student_admissions.id')->chunkById(300, function ($admissions) use (&$rows, &$totals, $columns): void {
            foreach ($admissions as $admission) {
                $figures = [
                    'agreed_total' => (string) ($admission->net_payable ?? $admission->total_amount ?? '0.00'),
                    'paid' => (string) ($admission->paid_amount ?? '0.00'),
                    'pending' => (string) ($admission->balance_amount ?? '0.00'),
                ];

                $rows[] = $this->row($columns, [
                    'admission_no' => $admission->admission_number,
                    'student' => $admission->student?->name,
                    'course' => $admission->course?->name,
                    'batch' => $admission->batch_id !== null ? '#'.$admission->batch_id : null,
                    'stage' => $admission->stage?->label(),
                    'date' => app_date($admission->admission_date),
                    'counsellor' => $admission->counselor?->name,
                    'collaborator' => $admission->collaborator?->name,
                    ...$figures,
                ]);

                foreach ($figures as $key => $value) {
                    $totals[$key] = Money::add($totals[$key], $value ?: '0');
                }
            }
        }, 'student_admissions.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'admission funnel'],
        );
    }
}
