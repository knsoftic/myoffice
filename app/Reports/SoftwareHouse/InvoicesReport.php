<?php

declare(strict_types=1);

namespace App\Reports\SoftwareHouse;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\AgingBucket;
use App\Enums\InvoiceStatus;
use App\Enums\ReportGroup;
use App\Models\Finance\Invoice;
use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `sh.invoices` - the invoice register with its ageing (requirement 99).
 *
 * **`balance_amount` is a stored column, not a subtraction done here.** Phase 13 maintains it inside
 * the same transaction that records a payment or a refund, so it can never disagree with the
 * payments. A report that computed `total - paid` would be right until the first refund.
 *
 * **The ageing bucket comes from `AgingBucket::forDays()`**, which is the same enum the receivables
 * report and the dunning sweep use. Three places deciding independently what "31-60 days" means is
 * three chances for a client to be chased on the wrong schedule.
 */
final class InvoicesReport extends Report
{
    public function key(): string
    {
        return 'sh.invoices';
    }

    public function title(): string
    {
        return 'Invoices';
    }

    public function description(): string
    {
        return 'Every invoice with what it was for, what has been paid against it, what is left and how overdue that is.';
    }

    public function icon(): string
    {
        return 'document-text';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::SoftwareHouse;
    }

    public function module(): string
    {
        return 'invoices';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('invoices.issue_date', 'Issued on', [
            'invoices.due_date' => 'Due on',
        ]);
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::link('invoice', 'Invoice'),
            ColumnDefinition::text('client', 'Client'),
            ColumnDefinition::text('project', 'Project'),
            ColumnDefinition::date('issued', 'Issued'),
            ColumnDefinition::date('due', 'Due'),
            ColumnDefinition::money('total', 'Total', 'invoices'),
            ColumnDefinition::money('paid', 'Paid', 'invoices'),
            ColumnDefinition::money('balance', 'Balance', 'invoices'),
            ColumnDefinition::badge('status', 'Status'),
            ColumnDefinition::number('days_overdue', 'Days overdue'),
            ColumnDefinition::badge('bucket', 'Ageing'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::multiselect('status', 'Status', InvoiceStatus::options()),
            FilterDefinition::entity('client_id', 'Client', 'clients.view_any'),
            FilterDefinition::entity('project_id', 'Project', 'projects.view_any'),
            FilterDefinition::multiselect('bucket', 'Ageing', AgingBucket::options()),
        ];
    }

    public function groupBy(): array
    {
        return ['client' => 'Client', 'status' => 'Status', 'bucket' => 'Ageing'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $dateColumn = $this->dateFilter()->resolve($request->dateColumn);

        $query = Invoice::query()
            ->with(['client:id,name', 'project:id,name'])
            ->whereBetween($dateColumn, [
                $request->range->start()->toDateString(),
                $request->range->end()->toDateString(),
            ]);

        if ($request->hasFilter('status')) {
            $query->whereIn('invoices.status', (array) $request->filter('status'));
        }

        foreach (['client_id', 'project_id'] as $filter) {
            if ($request->hasFilter($filter)) {
                $query->where('invoices.'.$filter, (int) $request->filter($filter));
            }
        }

        $buckets = (array) $request->filter('bucket', []);

        $rows = [];
        $totals = ['total' => Money::ZERO, 'paid' => Money::ZERO, 'balance' => Money::ZERO];

        $query->orderBy('invoices.id')->chunkById(200, function ($invoices) use (&$rows, &$totals, $columns, $buckets): void {
            foreach ($invoices as $invoice) {
                // Overdue only counts when something is actually owed: a paid invoice whose due date
                // has passed is not overdue, it is finished.
                $owes = Money::isPositive((string) ($invoice->balance_amount ?? '0'));

                $daysOverdue = $owes && $invoice->due_date !== null && $invoice->due_date->isPast()
                    ? (int) $invoice->due_date->diffInDays(now(), absolute: true)
                    : 0;

                $bucket = AgingBucket::forDays($daysOverdue);

                if ($buckets !== [] && ! in_array($bucket->value, $buckets, true)) {
                    continue;
                }

                $rows[] = $this->row($columns, [
                    'invoice' => $invoice->invoice_number,
                    'client' => $invoice->client?->name,
                    'project' => $invoice->project?->name,
                    'issued' => app_date($invoice->issue_date),
                    'due' => app_date($invoice->due_date),
                    'total' => (string) $invoice->total_amount,
                    'paid' => (string) $invoice->paid_amount,
                    'balance' => (string) $invoice->balance_amount,
                    'status' => $invoice->status?->label(),
                    'days_overdue' => $daysOverdue,
                    'bucket' => $bucket->label(),
                ]);

                $totals['total'] = Money::add($totals['total'], (string) ($invoice->total_amount ?? '0'));
                $totals['paid'] = Money::add($totals['paid'], (string) ($invoice->paid_amount ?? '0'));
                $totals['balance'] = Money::add($totals['balance'], (string) ($invoice->balance_amount ?? '0'));
            }
        }, 'invoices.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'invoice register'],
        );
    }
}
