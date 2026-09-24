<?php

declare(strict_types=1);

namespace App\Reports\Institute;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\AgingBucket;
use App\Enums\ReportGroup;
use App\Enums\StudentFeeStatus;
use App\Models\Branch;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\StudentFee;
use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `in.pending_fees` - the collection desk (requirement 99).
 *
 * **One row per student, not per charge**, which is what makes it a collection list rather than a
 * second copy of `in.fees`. Somebody ringing a parent needs "this family owes 32,000 across three
 * charges, the oldest 45 days late" - not three rows to add up while the phone is ringing.
 *
 * **Cancelled and refunded charges are excluded, not shown as zero.** A cancelled fee has no
 * balance to collect, and a row of zeroes on a collection list is a call somebody makes for nothing.
 *
 * **The ageing bucket is `AgingBucket::forDays()`** - the same enum the invoice ageing and the
 * receivables report use. One definition of "31-60 days" across the whole system is what stops a
 * student being chased on a different schedule from a client.
 */
final class PendingFeesReport extends Report
{
    public function key(): string
    {
        return 'in.pending_fees';
    }

    public function title(): string
    {
        return 'Pending Fees';
    }

    public function description(): string
    {
        return 'The collection desk: one row per student showing what is owed, when the next payment is due and how late it already is.';
    }

    public function icon(): string
    {
        return 'exclamation-triangle';
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
        return new DateFilter('student_fees.due_date', 'Due on');
    }

    public function columns(): array
    {
        $money = static fn (string $k, string $l): ColumnDefinition => ColumnDefinition::money($k, $l, 'student_fees');

        return [
            ColumnDefinition::link('student', 'Student'),
            ColumnDefinition::text('course', 'Course'),
            ColumnDefinition::text('batch', 'Batch'),
            ColumnDefinition::date('next_due', 'Next due'),
            $money('amount_due', 'Amount due'),
            $money('balance', 'Total balance'),
            ColumnDefinition::number('days_late', 'Days late'),
            ColumnDefinition::badge('bucket', 'Ageing'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::multiselect('bucket', 'Ageing', AgingBucket::options()),
            FilterDefinition::select('course_id', 'Course', static fn (): array => Course::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::select('batch_id', 'Batch', static fn (): array => Batch::query()->orderByDesc('start_date')->limit(200)->pluck('name', 'id')->all()),
            FilterDefinition::select('branch_id', 'Branch', static fn (): array => Branch::query()->orderBy('name')->pluck('name', 'id')->all()),
            new FilterDefinition(
                key: 'amount_range',
                label: 'Balance between',
                type: \App\Enums\ReportFilterType::NumberRange,
                // The whole report is money, so this filter is gated the same way its columns are.
                permission: 'student_fees.view_financial',
                span: 4,
            ),
        ];
    }

    public function groupBy(): array
    {
        return ['course' => 'Course', 'batch' => 'Batch', 'bucket' => 'Ageing'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $query = StudentFee::query()
            ->with(['student:id,name', 'course:id,name', 'batch:id,name'])
            ->where('student_fees.balance_amount', '>', 0)
            // Cancelled and refunded have no balance to collect - see the class note.
            ->whereNotIn('student_fees.status', [
                StudentFeeStatus::Cancelled->value,
                StudentFeeStatus::Refunded->value,
            ])
            // **A charge with no due date is still owed.** Restricting to the range alone would
            // drop every undated charge off the collection desk - and a balance that is invisible
            // because nobody set a date is the one nobody chases. `student_fees.due_date` is
            // nullable by design (an ad-hoc charge does not always have a date), so the undated
            // ones are always in scope and the range narrows only the dated ones.
            ->where(static fn ($q) => $q
                ->whereNull('student_fees.due_date')
                ->orWhereBetween('student_fees.due_date', [
                    $request->range->start()->toDateString(),
                    $request->range->end()->toDateString(),
                ]));

        foreach (['course_id', 'batch_id', 'branch_id'] as $filter) {
            if ($request->hasFilter($filter)) {
                $query->where('student_fees.'.$filter, (int) $request->filter($filter));
            }
        }

        // Collapsed by student, so one family is one call.
        $byStudent = [];

        $query->orderBy('student_fees.id')->chunkById(300, function ($fees) use (&$byStudent): void {
            foreach ($fees as $fee) {
                $key = (int) $fee->student_id;

                $byStudent[$key] ??= [
                    'student' => $fee->student?->name,
                    'course' => $fee->course?->name,
                    'batch' => $fee->batch?->name,
                    'next_due' => null,
                    'amount_due' => Money::ZERO,
                    'balance' => Money::ZERO,
                ];

                $balance = (string) $fee->balance_amount;
                $byStudent[$key]['balance'] = Money::add($byStudent[$key]['balance'], $balance);

                // "Next due" is the earliest unpaid due date, and `amount_due` is what that one
                // charge asks for - the number to quote on the phone, not the whole balance.
                $due = $fee->due_date;

                if ($due !== null && ($byStudent[$key]['next_due'] === null || $due->lessThan($byStudent[$key]['next_due']))) {
                    $byStudent[$key]['next_due'] = $due;
                    $byStudent[$key]['amount_due'] = $balance;
                }
            }
        }, 'student_fees.id', 'id');

        $buckets = (array) $request->filter('bucket', []);
        $range = (array) $request->filter('amount_range', []);

        $rows = [];
        $totals = ['amount_due' => Money::ZERO, 'balance' => Money::ZERO];

        foreach ($byStudent as $entry) {
            // Every charge this student has is undated, so there is no "next" one to quote - the
            // whole balance is what is owed. Leaving `amount_due` at zero here would have put a
            // student on the collection desk with nothing to ask them for, which is the same bug as
            // leaving them off it.
            if ($entry['next_due'] === null) {
                $entry['amount_due'] = $entry['balance'];
            }

            $daysLate = $entry['next_due'] !== null && $entry['next_due']->isPast()
                ? (int) $entry['next_due']->diffInDays(now(), absolute: true)
                : 0;

            $bucket = AgingBucket::forDays($daysLate);

            if ($buckets !== [] && ! in_array($bucket->value, $buckets, true)) {
                continue;
            }

            if (($range['min'] ?? '') !== '' && Money::compare($entry['balance'], (string) $range['min']) < 0) {
                continue;
            }

            if (($range['max'] ?? '') !== '' && Money::compare($entry['balance'], (string) $range['max']) > 0) {
                continue;
            }

            $rows[] = $this->row($columns, [
                'student' => $entry['student'],
                'course' => $entry['course'],
                'batch' => $entry['batch'],
                'next_due' => app_date($entry['next_due']),
                'amount_due' => $entry['amount_due'],
                'balance' => $entry['balance'],
                'days_late' => $daysLate,
                'bucket' => $bucket->label(),
            ]);

            $totals['amount_due'] = Money::add($totals['amount_due'], $entry['amount_due']);
            $totals['balance'] = Money::add($totals['balance'], $entry['balance']);
        }

        // Oldest debt first: a collection list is a work queue, and the top of it should be the
        // call that has been waiting longest.
        usort($rows, static fn (array $a, array $b): int => ($b['days_late'] ?? 0) <=> ($a['days_late'] ?? 0));

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: [
                'basis' => 'outstanding fee balances, one row per student',
                'includes_undated' => true,
            ],
        );
    }
}
