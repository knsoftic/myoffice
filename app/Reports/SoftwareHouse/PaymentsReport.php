<?php

declare(strict_types=1);

namespace App\Reports\SoftwareHouse;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\PaymentMethod;
use App\Enums\ReportGroup;
use App\Models\Finance\ProjectPayment;
use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `sh.payments` - money received against projects (requirement 99).
 *
 * **Three money columns, and the third is the only one that is safe to add up.** `gross` is what
 * was taken, `refunded` is what went back, and `net` is the generated column Phase 13 maintains as
 * the difference. Totalling gross would overstate income by every refund ever issued - which is
 * exactly the mistake a report is for catching, not for making.
 *
 * Voided payments are excluded. A voided receipt is a receipt that never happened; leaving it in
 * with a zero would still add a row to the count.
 */
final class PaymentsReport extends Report
{
    public function key(): string
    {
        return 'sh.payments';
    }

    public function title(): string
    {
        return 'Project Payments';
    }

    public function description(): string
    {
        return 'Every receipt against a project, what was refunded against it, and the net that actually came in.';
    }

    public function icon(): string
    {
        return 'receipt-percent';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::SoftwareHouse;
    }

    public function module(): string
    {
        return 'project_payments';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('project_payments.paid_on', 'Received on');
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::text('receipt', 'Receipt'),
            ColumnDefinition::text('client', 'Client'),
            ColumnDefinition::text('project', 'Project'),
            ColumnDefinition::text('invoice', 'Invoice'),
            ColumnDefinition::badge('method', 'Method'),
            ColumnDefinition::date('paid_on', 'Received'),
            ColumnDefinition::money('gross', 'Gross', 'project_payments'),
            ColumnDefinition::money('refunded', 'Refunded', 'project_payments'),
            ColumnDefinition::money('net', 'Net', 'project_payments'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::entity('client_id', 'Client', 'clients.view_any'),
            FilterDefinition::entity('project_id', 'Project', 'projects.view_any'),
            FilterDefinition::multiselect('method', 'Method', PaymentMethod::options()),
            FilterDefinition::boolean('has_refund', 'Has a refund'),
        ];
    }

    public function groupBy(): array
    {
        return ['client' => 'Client', 'project' => 'Project', 'method' => 'Method'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $query = ProjectPayment::query()
            ->received()
            ->with(['client:id,name', 'project:id,name'])
            ->whereBetween('project_payments.paid_on', [
                $request->range->start()->toDateString(),
                $request->range->end()->toDateString(),
            ]);

        foreach (['client_id', 'project_id'] as $filter) {
            if ($request->hasFilter($filter)) {
                $query->where('project_payments.'.$filter, (int) $request->filter($filter));
            }
        }

        if ($request->hasFilter('method')) {
            $query->whereIn('project_payments.payment_method', (array) $request->filter('method'));
        }

        $hasRefund = $request->booleanFilter('has_refund');

        if ($hasRefund !== null) {
            $hasRefund
                ? $query->where('project_payments.refunded_amount', '>', 0)
                : $query->where(static fn ($q) => $q->whereNull('project_payments.refunded_amount')->orWhere('project_payments.refunded_amount', '<=', 0));
        }

        $rows = [];
        $totals = ['gross' => Money::ZERO, 'refunded' => Money::ZERO, 'net' => Money::ZERO];

        $query->orderBy('project_payments.id')->chunkById(300, function ($payments) use (&$rows, &$totals, $columns): void {
            foreach ($payments as $payment) {
                $rows[] = $this->row($columns, [
                    'receipt' => $payment->payment_no,
                    'client' => $payment->client?->name,
                    'project' => $payment->project?->name,
                    'invoice' => $payment->invoice_id !== null ? '#'.$payment->invoice_id : null,
                    'method' => $payment->payment_method?->label(),
                    'paid_on' => app_date($payment->paid_on),
                    'gross' => (string) $payment->amount,
                    'refunded' => (string) ($payment->refunded_amount ?? '0.00'),
                    'net' => (string) $payment->net_received_amount,
                ]);

                $totals['gross'] = Money::add($totals['gross'], (string) ($payment->amount ?? '0'));
                $totals['refunded'] = Money::add($totals['refunded'], (string) ($payment->refunded_amount ?? '0'));
                $totals['net'] = Money::add($totals['net'], (string) ($payment->net_received_amount ?? '0'));
            }
        }, 'project_payments.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'received payments, net of refunds'],
        );
    }
}
