<?php

declare(strict_types=1);

namespace App\Reports\SoftwareHouse;

use App\DataObjects\Crm\ClientFilters;
use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\ClientStatus;
use App\Enums\ReportGroup;
use App\Models\Crm\Client;
use App\Models\User;
use App\Reports\Report;
use App\Services\Crm\ClientExporter;
use App\Services\Crm\ClientService;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `sh.clients` — the client register with its money (requirement §99).
 *
 * **INV-23-1 in practice.** The rows come from `ClientExporter::query()`, which is the same query
 * the CRM export already uses and which carries `ClientFilters`' own scope; the money comes from
 * `ClientService::financialSummary()`, which is the same method the client detail screen prints. A
 * report that built its own `SUM(invoices.total)` would be a second answer to "how much does this
 * client owe", and the two would drift — quietly, and in the direction of whichever nobody checks.
 *
 * **The financial summary is fetched per row and only when a money column survived.** It is the
 * expensive part, and a viewer without `clients.view_financial` has had every money column stripped
 * before `run()` is called, so there is nothing to fetch for them. That is the whole reason the
 * engine passes the surviving keys *in* rather than filtering the result afterwards.
 */
final class ClientsReport extends Report
{
    public function __construct(
        private readonly ClientExporter $exporter,
        private readonly ClientService $clients,
    ) {}

    public function key(): string
    {
        return 'sh.clients';
    }

    public function title(): string
    {
        return 'Clients';
    }

    public function description(): string
    {
        return 'Every client with their projects, what has been invoiced, what has been paid and what is still outstanding.';
    }

    public function icon(): string
    {
        return 'building-office-2';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::SoftwareHouse;
    }

    public function module(): string
    {
        return 'clients';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('clients.created_at', 'Added on');
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::link('client', 'Client'),
            ColumnDefinition::text('company', 'Company'),
            ColumnDefinition::text('country', 'Country'),
            ColumnDefinition::badge('status', 'Status'),
            ColumnDefinition::number('projects', 'Projects', 'sum'),
            ColumnDefinition::money('invoiced', 'Invoiced', 'clients'),
            ColumnDefinition::money('paid', 'Paid', 'clients'),
            ColumnDefinition::money('outstanding', 'Outstanding', 'clients'),
            ColumnDefinition::text('account_manager', 'Account manager'),
            ColumnDefinition::date('created', 'Added'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::multiselect('status', 'Status', ClientStatus::options()),
            FilterDefinition::select('country', 'Country', static fn (): array => self::countries()),
            FilterDefinition::entity('account_manager_id', 'Account manager', 'users.view_any'),
            FilterDefinition::entity('collaborator_id', 'Collaborator', 'collaborators.view_any'),
            // Tri-state on purpose: unset means every client, "no" means only the ones who owe
            // nothing. Collapsing the two would silently halve the report.
            //
            // **Gated on the financial permission**, which §99's filter list does not say and which
            // is nevertheless right: whether a client owes money is a financial fact, and a filter
            // that answers it by making rows appear and disappear tells somebody without
            // `view_financial` exactly what the withheld column would have. INV-23-2 takes the
            // column away; leaving this filter would hand most of it back.
            new FilterDefinition(
                key: 'has_outstanding',
                label: 'Has outstanding',
                type: \App\Enums\ReportFilterType::Boolean,
                permission: 'clients.view_financial',
                span: 2,
            ),
        ];
    }

    public function groupBy(): array
    {
        return ['status' => 'Status', 'country' => 'Country', 'account_manager' => 'Account manager'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $filters = ClientFilters::fromArray([
            'status' => (array) $request->filter('status', []),
            'country' => $request->filter('country'),
            'account_manager_id' => $request->filter('account_manager_id'),
            'created_from' => $request->range->start()->toDateString(),
            'created_to' => $request->range->end()->toDateString(),
        ]);

        $query = $this->exporter->query($filters)->withCount('projects');

        if ($request->hasFilter('collaborator_id')) {
            // **`clients` has no `collaborator_id`** (D37, §11 test 55): a client is attributed
            // through the spine's `collaborator_referrals` rows, never by a column on the client.
            // Filtering on a column that does not exist would have been a fatal; filtering on one
            // that did exist as a snapshot would have been worse, because it would have answered
            // with a stale display value rather than the attribution of record.
            $query->whereHas(
                'collaboratorReferrals',
                static fn ($referrals) => $referrals->where('collaborator_id', (int) $request->filter('collaborator_id')),
            );
        }

        // Only when a money column survived: see the class note.
        $wantsMoney = array_intersect(['invoiced', 'paid', 'outstanding'], $columns) !== [];
        $wantsOutstanding = $request->booleanFilter('has_outstanding');

        $rows = [];
        $totals = ['projects' => 0, 'invoiced' => '0.00', 'paid' => '0.00', 'outstanding' => '0.00'];

        // `chunkById` over a joined query has to be told which `id` it means, or MariaDB refuses the
        // keyset clause as ambiguous. The fourth argument is the alias the cursor is carried under;
        // without it the chunk works on the first page and dies on the second.
        $query->orderBy('clients.name')->chunkById(200, function ($clients) use (
            &$rows,
            &$totals,
            $columns,
            $wantsMoney,
            $wantsOutstanding,
            $viewer,
        ): void {
            foreach ($clients as $client) {
                // The viewer is passed through, so the service applies its own withholding rather
                // than this report trusting that the engine already stripped the column. Two gates
                // agreeing is cheap; one gate that turns out to have been the only one is not.
                $summary = $wantsMoney || $wantsOutstanding
                    ? $this->clients->financialSummary($client, $viewer)
                    : null;

                $outstanding = (string) ($summary?->outstanding ?? '0.00');

                // The filter runs after the summary because "outstanding" is a derived figure, not
                // a column — there is nothing to put in a WHERE clause.
                if ($wantsOutstanding !== null) {
                    $owes = Money::isPositive($outstanding);

                    if ($owes !== $wantsOutstanding) {
                        continue;
                    }
                }

                $rows[] = $this->row($columns, [
                    'client' => $client->name,
                    'company' => $client->company_name,
                    'country' => $client->country,
                    'status' => $client->status?->label(),
                    'projects' => $client->projects_count,
                    'invoiced' => (string) ($summary?->invoiced ?? '0.00'),
                    'paid' => (string) ($summary?->paid ?? '0.00'),
                    'outstanding' => $outstanding,
                    'account_manager' => $client->getAttribute('account_manager_name'),
                    'created' => app_date($client->created_at),
                ]);

                $totals['projects'] += (int) $client->projects_count;

                if ($summary !== null) {
                    $totals['invoiced'] = Money::add($totals['invoiced'], (string) $summary->invoiced);
                    $totals['paid'] = Money::add($totals['paid'], (string) $summary->paid);
                    $totals['outstanding'] = Money::add($totals['outstanding'], $outstanding);
                }
            }
        }, 'clients.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'client register'],
        );
    }

    /**
     * The countries actually on file, so the dropdown never offers one that matches nothing.
     *
     * @return array<string, string>
     */
    private static function countries(): array
    {
        return Client::query()
            ->whereNotNull('country')
            ->where('country', '<>', '')
            ->distinct()
            ->orderBy('country')
            ->pluck('country')
            ->mapWithKeys(static fn (mixed $c): array => [(string) $c => (string) $c])
            ->all();
    }
}
