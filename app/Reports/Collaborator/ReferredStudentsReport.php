<?php

declare(strict_types=1);

namespace App\Reports\Collaborator;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\ReferralSubject;
use App\Enums\ReportGroup;
use App\Enums\StudentStatus;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorReferral;
use App\Models\Institute\Course;
use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `co.referred_students` - which students came from which collaborator (requirement 99).
 *
 * **Driven from `collaborator_referrals`, not from `students.collaborator_id`.** The referral row is
 * the attribution of record: it is append-only, it carries the visit that won it, and it has a
 * `superseded_by_id` chain when attribution changes hands. The column on the student is the current
 * answer; this table is the evidence for it. A report about who referred whom should read the
 * evidence.
 *
 * **"Total paid" is what the student has actually paid**, and "commission earned" is what the
 * collaborator got for it. Showing both is the point: the ratio between them is the rate actually
 * realised, which is not always the rate on the rule once discounts and reversals have happened.
 */
final class ReferredStudentsReport extends Report
{
    public function key(): string
    {
        return 'co.referred_students';
    }

    public function title(): string
    {
        return 'Referred Students';
    }

    public function description(): string
    {
        return 'Students brought in by each collaborator, what they have paid and what was earned on them.';
    }

    public function icon(): string
    {
        return 'user-plus';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Collaborator;
    }

    public function module(): string
    {
        return 'collaborator_referrals';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('collaborator_referrals.referral_date', 'Referred on');
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::text('collaborator', 'Collaborator'),
            ColumnDefinition::link('student', 'Student'),
            ColumnDefinition::text('course', 'Course'),
            ColumnDefinition::text('batch', 'Batch'),
            ColumnDefinition::date('referred', 'Referred'),
            ColumnDefinition::badge('status', 'Student status'),
            ColumnDefinition::money('total_paid', 'Total paid', 'student_fees'),
            ColumnDefinition::money('commission', 'Commission earned', 'collaborator_commissions'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::select('collaborator_id', 'Collaborator', static fn (): array => Collaborator::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all()),
            FilterDefinition::select('course_id', 'Course', static fn (): array => Course::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::multiselect('status', 'Student status', StudentStatus::options()),
        ];
    }

    public function groupBy(): array
    {
        return ['collaborator' => 'Collaborator', 'course' => 'Course', 'status' => 'Student status'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $query = CollaboratorReferral::query()
            ->where('collaborator_referrals.subject_type', ReferralSubject::Student->value)
            ->whereNotNull('collaborator_referrals.student_id')
            ->with(['collaborator:id,name', 'student:id,name,status'])
            ->whereBetween('collaborator_referrals.referral_date', [
                $request->range->start()->toDateString(),
                $request->range->end()->toDateString(),
            ]);

        if ($request->hasFilter('collaborator_id')) {
            $query->where('collaborator_referrals.collaborator_id', (int) $request->filter('collaborator_id'));
        }

        if ($request->hasFilter('status')) {
            $statuses = (array) $request->filter('status');
            $query->whereHas('student', static fn ($q) => $q->whereIn('status', $statuses));
        }

        $wantsPaid = in_array('total_paid', $columns, true);
        $wantsCommission = in_array('commission', $columns, true);

        $rows = [];
        $totals = ['total_paid' => Money::ZERO, 'commission' => Money::ZERO];

        $query->orderBy('collaborator_referrals.id')->chunkById(200, function ($referrals) use (
            &$rows,
            &$totals,
            $columns,
            $wantsPaid,
            $wantsCommission,
        ): void {
            foreach ($referrals as $referral) {
                $studentId = (int) $referral->student_id;

                $paid = $wantsPaid
                    ? (string) (\Illuminate\Support\Facades\DB::table('student_fees')
                        ->whereNull('deleted_at')
                        ->where('student_id', $studentId)
                        ->sum('paid_amount') ?? '0')
                    : '0';

                // signed_amount, never amount: a clawback on this student must reduce the figure.
                $commission = $wantsCommission
                    ? (string) (\Illuminate\Support\Facades\DB::table('collaborator_commission_ledger_entries')
                        ->where('collaborator_id', $referral->collaborator_id)
                        ->where('student_id', $studentId)
                        ->sum('signed_amount') ?? '0')
                    : '0';

                $rows[] = $this->row($columns, [
                    'collaborator' => $referral->collaborator?->name,
                    'student' => $referral->student?->name,
                    'course' => null,
                    'batch' => null,
                    'referred' => app_date($referral->referral_date),
                    'status' => $referral->student?->status?->label(),
                    'total_paid' => Money::of($paid),
                    'commission' => Money::of($commission),
                ]);

                $totals['total_paid'] = Money::add($totals['total_paid'], $paid ?: '0');
                $totals['commission'] = Money::add($totals['commission'], $commission ?: '0');
            }
        }, 'collaborator_referrals.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'referral rows, the attribution of record'],
        );
    }
}
