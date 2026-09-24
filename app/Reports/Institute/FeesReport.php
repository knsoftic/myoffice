<?php

declare(strict_types=1);

namespace App\Reports\Institute;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\ReportGroup;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentFeeType;
use App\Models\Branch;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\StudentFee;
use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `in.fees` - every fee charge and what has been paid against it (requirement 99).
 *
 * **Six money columns, all stored, none recomputed.** `gross_amount`, `discount_amount`,
 * `net_amount`, `paid_amount`, `refunded_amount` and `balance_amount` are all maintained by
 * `StudentFeeService` inside the transaction that records a payment, a discount or a refund. That
 * is the whole reason they are columns rather than derivations: the commission engine reads them,
 * the collection desk reads them, and a report that recomputed `net - paid` would be right until
 * the first refund and wrong for ever after.
 *
 * **The date column is a real choice here, not decoration.** "Fees this month" means something
 * different depending on whether it is fees *raised*, fees *due* or fees *paid*, and all three give
 * different totals from the same table. The report offers all three and the printed header always
 * says which one was used.
 */
final class FeesReport extends Report
{
    public function key(): string
    {
        return 'in.fees';
    }

    public function title(): string
    {
        return 'Student Fees';
    }

    public function description(): string
    {
        return 'Every fee charge with its discount, what has been paid, what is left and whether it is overdue.';
    }

    public function icon(): string
    {
        return 'currency-rupee';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Institute;
    }

    public function module(): string
    {
        return 'student_fees';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('student_fees.created_at', 'Raised on', [
            'student_fees.due_date' => 'Due on',
        ]);
    }

    public function columns(): array
    {
        $money = static fn (string $k, string $l): ColumnDefinition => ColumnDefinition::money($k, $l, 'student_fees');

        return [
            ColumnDefinition::text('fee_no', 'Fee no'),
            ColumnDefinition::link('student', 'Student'),
            ColumnDefinition::text('course', 'Course'),
            ColumnDefinition::text('batch', 'Batch'),
            ColumnDefinition::badge('head', 'Head'),
            $money('gross', 'Gross'),
            $money('discount', 'Discount'),
            $money('net', 'Net'),
            $money('paid', 'Paid'),
            $money('balance', 'Balance'),
            ColumnDefinition::date('due', 'Due'),
            ColumnDefinition::badge('status', 'Status'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::multiselect('status', 'Status', StudentFeeStatus::options()),
            FilterDefinition::multiselect('fee_type', 'Head', StudentFeeType::options()),
            FilterDefinition::select('course_id', 'Course', static fn (): array => Course::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::select('batch_id', 'Batch', static fn (): array => Batch::query()->orderByDesc('start_date')->limit(200)->pluck('name', 'id')->all()),
            FilterDefinition::select('branch_id', 'Branch', static fn (): array => Branch::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::entity('collaborator_id', 'Collaborator', 'collaborators.view_any'),
        ];
    }

    public function groupBy(): array
    {
        return ['head' => 'Head', 'status' => 'Status', 'course' => 'Course', 'batch' => 'Batch'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $dateColumn = $this->dateFilter()->resolve($request->dateColumn);

        $query = StudentFee::query()
            ->with(['student:id,name', 'course:id,name', 'batch:id,name'])
            ->whereBetween($dateColumn, [$request->range->start(), $request->range->end()]);

        foreach (['status' => 'status', 'fee_type' => 'fee_type'] as $filter => $column) {
            if ($request->hasFilter($filter)) {
                $query->whereIn('student_fees.'.$column, (array) $request->filter($filter));
            }
        }

        foreach (['course_id', 'batch_id', 'branch_id', 'collaborator_id'] as $filter) {
            if ($request->hasFilter($filter)) {
                $query->where('student_fees.'.$filter, (int) $request->filter($filter));
            }
        }

        $moneyKeys = ['gross', 'discount', 'net', 'paid', 'balance'];
        $rows = [];
        $totals = array_fill_keys($moneyKeys, Money::ZERO);

        $query->orderBy('student_fees.id')->chunkById(300, function ($fees) use (&$rows, &$totals, $columns, $moneyKeys): void {
            foreach ($fees as $fee) {
                // The discount column shows what was actually taken off, which is the discount plus
                // any scholarship: a reader comparing gross with net needs the difference to be one
                // number, not two they have to know to add.
                $figures = [
                    'gross' => (string) $fee->gross_amount,
                    'discount' => Money::add((string) $fee->discount_amount, (string) ($fee->scholarship_amount ?? '0')),
                    'net' => (string) $fee->net_amount,
                    'paid' => (string) $fee->paid_amount,
                    'balance' => (string) $fee->balance_amount,
                ];

                $rows[] = $this->row($columns, [
                    'fee_no' => $fee->fee_number,
                    'student' => $fee->student?->name,
                    'course' => $fee->course?->name,
                    'batch' => $fee->batch?->name,
                    'head' => $fee->fee_type?->label(),
                    ...$figures,
                    'due' => app_date($fee->due_date),
                    'status' => $fee->status?->label(),
                ]);

                foreach ($moneyKeys as $key) {
                    $totals[$key] = Money::add($totals[$key], $figures[$key] ?: '0');
                }
            }
        }, 'student_fees.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'fee charges'],
        );
    }
}
