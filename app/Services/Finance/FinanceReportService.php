<?php

declare(strict_types=1);

namespace App\Services\Finance;

use InvalidArgumentException;
use App\Enums\AgingBucket;
use App\Enums\ExpenseStatus;
use App\Enums\FinanceContext;
use App\Enums\FinanceReportType;
use App\Enums\IncomeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ReceivedPaymentStatus;
use App\Models\Finance\FinanceCategory;
use App\Models\User;
use App\Services\Collaborator\CollaboratorStatementService;
use App\Services\Collaborator\CollaboratorWalletService;
use App\Support\DateRange;
use App\Support\Format;
use App\Support\Money;
use App\Support\ReportResult;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * The four software-house finance reports (§99, phase-13 §6.7).
 *
 * **The basis is cash, always.** Every figure is money that actually moved, on its value date —
 * `paid_on`, `expense_date`, `received_on`. An invoice is a claim, not cash, so it appears only in
 * receivables aging and never as income. `meta.basis` says so on every result, because a profit figure
 * whose basis is ambiguous is a profit figure two people will read differently.
 *
 * **A source the reader may not see is omitted and named.** Silently dropping it would turn a partial
 * total into something that reads as a full one, which is worse than refusing outright;
 * `meta.omitted_sources` is what the screen prints beside the figure.
 *
 * **Collaborator commission is never summed here.** Block C of the profit-and-loss statement and its
 * memo come from `CollaboratorWalletService::payoutsPaidTotal(null, $range)` and
 * `CollaboratorStatementService::commissionAccruedTotal(null, $range)` — the spine's own company-wide
 * form. Nothing in this phase runs a `SUM()` over the ledger, the payouts or the allocations (INV-26).
 */
class FinanceReportService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CollaboratorWalletService $wallets,
        private readonly CollaboratorStatementService $statements,
    ) {}

    public function report(FinanceReportType $type, DateRange $range, ?User $viewer = null, array $filters = []): ReportResult
    {
        return match ($type) {
            FinanceReportType::Income => $this->income($range, $viewer, $filters),
            FinanceReportType::Expenses => $this->expenses($range, $viewer, $filters),
            FinanceReportType::ProfitLoss => $this->profitAndLoss($range, $viewer, $filters),
            FinanceReportType::ReceivablesAging => $this->receivablesAging($range, $viewer, $filters),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | §6.7.1 Income
    |--------------------------------------------------------------------------
    */

    /**
     * Three sources, each one aggregate, combined in PHP with `Money::sum()`.
     *
     * Invoices are **not** a source: an invoice is a claim, and counting it as income would book money
     * the business has not received. Collaborator payouts are not income either — they are an outflow,
     * and they reach the P&L as block C.
     */
    private function income(DateRange $range, ?User $viewer, array $filters): ReportResult
    {
        $rows = [];
        $omitted = [];
        $includeInstitute = (bool) setting('finance.reports_include_institute', true);

        if ($this->may($viewer, 'project_payments')) {
            $project = $this->db->table('project_payments')
                ->whereNot('status', ReceivedPaymentStatus::Voided->value)
                ->tap(fn ($q) => $range->applyDates($q, 'paid_on'))
                ->selectRaw('COUNT(*) as entries, COALESCE(SUM(net_received_amount), 0) as total')
                ->first();

            $rows[] = [
                'source' => 'Project payments',
                'entries' => (int) ($project->entries ?? 0),
                'amount' => Money::of((string) ($project->total ?? Money::ZERO)),
            ];
        } else {
            $omitted[] = 'Project payments';
        }

        if ($includeInstitute) {
            if ($this->may($viewer, 'student_fee_payments')) {
                // Broken out by `fee_type`, which is how §29's list of fee kinds is satisfied without a
                // fourth source and without counting the same receipt twice.
                $fees = $this->db->table('student_fee_payments as p')
                    ->leftJoin('student_fees as f', 'f.id', '=', 'p.student_fee_id')
                    ->whereNot('p.status', ReceivedPaymentStatus::Voided->value)
                    ->tap(fn ($q) => $range->applyDates($q, 'p.paid_on'))
                    ->groupBy('f.fee_type')
                    ->selectRaw('f.fee_type, COUNT(*) as entries, COALESCE(SUM(p.net_received_amount), 0) as total')
                    ->get();

                foreach ($fees as $fee) {
                    $rows[] = [
                        'source' => 'Student fees — '.($fee->fee_type ?? 'unclassified'),
                        'entries' => (int) $fee->entries,
                        'amount' => Money::of((string) $fee->total),
                    ];
                }
            } else {
                $omitted[] = 'Student fees';
            }
        }

        if ($this->may($viewer, 'income')) {
            $other = $this->db->table('incomes')
                ->where('status', IncomeStatus::Recorded->value)
                ->when(! $includeInstitute, fn ($q) => $q->whereNot('context', FinanceContext::Institute->value))
                ->tap(fn ($q) => $range->applyDates($q, 'received_on'))
                ->selectRaw('COUNT(*) as entries, COALESCE(SUM(net_amount), 0) as total')
                ->first();

            $rows[] = [
                'source' => 'Other income',
                'entries' => (int) ($other->entries ?? 0),
                'amount' => Money::of((string) ($other->total ?? Money::ZERO)),
            ];
        } else {
            $omitted[] = 'Other income';
        }

        return new ReportResult(
            rows: $rows,
            totals: [
                'entries' => array_sum(array_column($rows, 'entries')),
                'amount' => Money::sum(array_column($rows, 'amount') ?: [Money::ZERO]),
            ],
            meta: $this->meta($range, 'paid_on / received_on', $filters, $omitted, [
                'includes_institute' => $includeInstitute,
                'note' => 'An invoice is a claim, not cash. Invoices appear in receivables aging, never here.',
            ]),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | §6.7.2 Expenses
    |--------------------------------------------------------------------------
    */

    /**
     * Approved expenses only, on `net_amount` so a refund is already taken off.
     *
     * Pending claims are counted separately and shown as their own line. Money awaiting a decision is
     * real and somebody should see it; silently including it in the total would make a profit figure
     * move every time an employee typed a number.
     */
    private function expenses(DateRange $range, ?User $viewer, array $filters): ReportResult
    {
        $includeInstitute = (bool) setting('finance.reports_include_institute', true);

        $base = fn () => $this->db->table('expenses as e')
            ->leftJoin('finance_categories as c', 'c.id', '=', 'e.finance_category_id')
            ->when(! $includeInstitute, fn ($q) => $q->whereNot('e.context', FinanceContext::Institute->value))
            ->when(isset($filters['category']), fn ($q) => $q->where('e.finance_category_id', (int) $filters['category']))
            ->when(isset($filters['context']), fn ($q) => $q->where('e.context', (string) $filters['context']))
            ->when(isset($filters['project']), fn ($q) => $q->where('e.project_id', (int) $filters['project']))
            ->tap(fn ($q) => $range->applyDates($q, 'e.expense_date'));

        $rows = $base()
            ->where('e.status', ExpenseStatus::Approved->value)
            ->groupBy('e.finance_category_id', 'c.name', 'c.code')
            ->selectRaw('c.name as category, c.code as category_code, COUNT(*) as entries, COALESCE(SUM(e.net_amount), 0) as total')
            ->orderByRaw('COALESCE(SUM(e.net_amount), 0) DESC')
            ->get()
            ->map(static fn (object $row): array => [
                'category' => (string) ($row->category ?? 'Uncategorised'),
                'category_code' => (string) ($row->category_code ?? ''),
                'entries' => (int) $row->entries,
                'amount' => Money::of((string) $row->total),
            ])
            ->all();

        $pending = $base()
            ->where('e.status', ExpenseStatus::Pending->value)
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(e.net_amount), 0) as total')
            ->first();

        return new ReportResult(
            rows: $rows,
            totals: [
                'entries' => array_sum(array_column($rows, 'entries')),
                'amount' => Money::sum(array_column($rows, 'amount') ?: [Money::ZERO]),
            ],
            meta: $this->meta($range, 'expense_date', $filters, [], [
                'includes_institute' => $includeInstitute,
                'pending_count' => (int) ($pending->entries ?? 0),
                'pending_amount' => Money::of((string) ($pending->total ?? Money::ZERO)),
                'note' => 'Approved expenses only. Pending, rejected and voided rows are excluded from the total.',
            ]),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | §6.7.3 Profit and loss
    |--------------------------------------------------------------------------
    */

    /**
     * Three blocks and two bottom lines, so nothing is counted twice.
     *
     * **Collaborator commission is a cost, and only block C.** §29's phrase "collaborator payments"
     * means money leaving the business, and it never appears as income. It is also never an expense
     * row: recording a payout as an expense as well would double the cost, which is exactly what the
     * reserved-category rule and the standing note on the screen exist to prevent.
     */
    private function profitAndLoss(DateRange $range, ?User $viewer, array $filters): ReportResult
    {
        $income = $this->income($range, $viewer, $filters);
        $expenses = $this->expenses($range, $viewer, $filters);

        $incomeTotal = Money::of((string) ($income->totals['amount'] ?? Money::ZERO));
        $expenseTotal = Money::of((string) ($expenses->totals['amount'] ?? Money::ZERO));

        $beforeCommission = Money::sub($incomeTotal, $expenseTotal);

        // The spine's own company-wide form. Nothing here sums a payout or a ledger row (INV-26).
        $commissionPaid = $this->wallets->payoutsPaidTotal(null, $range);
        $commissionAccrued = $this->statements->commissionAccruedTotal(null, $range);

        $salaries = Money::of((string) (collect($expenses->rows)
            ->firstWhere('category_code', FinanceCategory::RESERVED_EXPENSE_CODE)['amount'] ?? Money::ZERO));

        return new ReportResult(
            rows: [
                ['block' => 'A', 'label' => 'Income (cash)', 'amount' => $incomeTotal],
                ['block' => 'B', 'label' => 'Expenses (approved, cash)', 'amount' => $expenseTotal],
                ['block' => 'B', 'label' => '… of which salaries', 'amount' => $salaries, 'is_memo' => true],
                ['block' => '', 'label' => 'Net before collaborator commission', 'amount' => $beforeCommission],
                ['block' => 'C', 'label' => 'Collaborator commission (cost)', 'amount' => $commissionPaid],
            ],
            groups: [
                'income' => $income->rows,
                'expenses' => $expenses->rows,
            ],
            totals: [
                'income' => $incomeTotal,
                'expenses' => $expenseTotal,
                'before_commission' => $beforeCommission,
                'commission_paid' => $commissionPaid,
                'net_profit' => Money::sub($beforeCommission, $commissionPaid),
            ],
            meta: $this->meta($range, 'value dates (paid_on / expense_date / received_on)', $filters,
                $income->omittedSources(), [
                    // Not in either line: accrued commission is what the business has *become* liable
                    // for, which is a different question from what it paid out this period.
                    'commission_accrued_memo' => $commissionAccrued,
                    'note' => 'Collaborator payouts come from the payout register. Do not also record them as expenses.',
                    'pending_expense_count' => $expenses->meta['pending_count'] ?? 0,
                    'pending_expense_amount' => $expenses->meta['pending_amount'] ?? Money::ZERO,
                ]),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | §6.7.4 Receivables aging
    |--------------------------------------------------------------------------
    */

    /**
     * What clients owe, by how long it has been owed — and what they have already paid that nobody has
     * attached to an invoice yet.
     *
     * Showing the unapplied credits beside the debt is what stops the report overstating exposure when
     * a client has paid in advance, and it is the screen from which `applyPayment()` is usually
     * reached.
     */
    private function receivablesAging(DateRange $range, ?User $viewer, array $filters): ReportResult
    {
        $today = Carbon::now(Format::timezone())->startOfDay();

        $invoices = $this->db->table('invoices as i')
            ->leftJoin('clients as c', 'c.id', '=', 'i.client_id')
            ->whereIn('i.status', [
                InvoiceStatus::Sent->value, InvoiceStatus::Partial->value, InvoiceStatus::Overdue->value,
            ])
            ->where('i.balance_amount', '>', 0)
            ->whereNull('i.deleted_at')
            ->when(isset($filters['client']), fn ($q) => $q->where('i.client_id', (int) $filters['client']))
            ->select('i.id', 'i.invoice_number', 'i.client_id', 'i.due_date', 'i.balance_amount',
                'c.name as client_name', 'c.company_name')
            ->orderBy('i.due_date')
            ->get();

        // Credits: money from this client that nobody has attached to an invoice. One query for all
        // clients rather than one per row.
        $credits = $this->db->table('project_payments')
            ->whereNull('invoice_id')
            ->whereNot('status', ReceivedPaymentStatus::Voided->value)
            ->groupBy('client_id')
            ->selectRaw('client_id, COALESCE(SUM(net_received_amount), 0) as credit')
            ->pluck('credit', 'client_id');

        $lastPayments = $this->db->table('project_payments')
            ->whereNot('status', ReceivedPaymentStatus::Voided->value)
            ->groupBy('client_id')
            ->selectRaw('client_id, MAX(paid_on) as last_paid_on')
            ->pluck('last_paid_on', 'client_id');

        $byClient = [];

        foreach ($invoices as $invoice) {
            $clientId = (int) $invoice->client_id;
            $balance = Money::of((string) $invoice->balance_amount);
            $due = Carbon::parse((string) $invoice->due_date, Format::timezone())->startOfDay();
            $bucket = AgingBucket::forDays((int) $due->diffInDays($today, false));

            $byClient[$clientId] ??= array_merge([
                'client_id' => $clientId,
                'client' => (string) ($invoice->company_name ?: $invoice->client_name),
                'outstanding' => Money::ZERO,
                'invoices' => 0,
                'oldest_due' => (string) $invoice->due_date,
            ], array_fill_keys(array_map(
                static fn (AgingBucket $b): string => $b->value, AgingBucket::cases(),
            ), Money::ZERO));

            $byClient[$clientId]['outstanding'] = Money::add($byClient[$clientId]['outstanding'], $balance);
            $byClient[$clientId][$bucket->value] = Money::add($byClient[$clientId][$bucket->value], $balance);
            $byClient[$clientId]['invoices']++;
        }

        $rows = [];

        foreach ($byClient as $clientId => $row) {
            $credit = Money::of((string) ($credits[$clientId] ?? Money::ZERO));

            $row['unapplied_credits'] = $credit;
            // What the business is genuinely owed once the money it is already holding is taken off.
            $row['net_exposure'] = Money::sub($row['outstanding'], $credit);
            $row['last_payment'] = $lastPayments[$clientId] ?? null;

            $rows[] = $row;
        }

        usort($rows, static fn (array $a, array $b): int => Money::compare($b['net_exposure'], $a['net_exposure']));

        $totals = ['outstanding' => Money::ZERO, 'unapplied_credits' => Money::ZERO, 'net_exposure' => Money::ZERO];

        foreach (AgingBucket::cases() as $bucket) {
            $totals[$bucket->value] = Money::ZERO;
        }

        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] = Money::add($totals[$key], (string) $row[$key]);
            }
        }

        $totals['invoices'] = array_sum(array_column($rows, 'invoices'));

        return new ReportResult(
            rows: $rows,
            totals: $totals,
            meta: $this->meta($range, 'due_date (as at today)', $filters, [], [
                'as_at' => $today->toDateString(),
                'note' => 'Outstanding invoices only. Drafts and cancelled invoices are excluded by definition.',
            ]),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The narrow readers the dashboard cards use (§8.15)
    |--------------------------------------------------------------------------
    |
    | The four reports answer the questions a report asks. A card asks smaller ones — "how many are
    | overdue", "what is waiting for approval" — and each is still a SUM over money. They live here
    | because **no widget contains its own SUM**: a card that ran its own query would eventually
    | disagree with the report beside it, and nobody would know which was right.
    |
    */

    /**
     * Outstanding receivables as at today, net of what the client has already paid.
     *
     * @return array{available: bool, invoices: int, outstanding: string, credits: string, net: string}
     */
    public function outstandingReceivables(?User $viewer = null): array
    {
        if (! $this->may($viewer, 'invoices')) {
            return ['available' => false, 'invoices' => 0, 'outstanding' => Money::ZERO,
                'credits' => Money::ZERO, 'net' => Money::ZERO];
        }

        $row = $this->db->table('invoices')
            ->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::Partial->value, InvoiceStatus::Overdue->value])
            ->where('balance_amount', '>', 0)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as invoices, COALESCE(SUM(balance_amount), 0) as outstanding')
            ->first();

        // Money already in hand that nobody has attached to an invoice. Showing the debt without it
        // would overstate what the business is actually owed.
        $credits = $this->may($viewer, 'project_payments')
            ? Money::of((string) ($this->db->table('project_payments')
                ->whereNull('invoice_id')
                ->whereNot('status', ReceivedPaymentStatus::Voided->value)
                ->sum('net_received_amount') ?: Money::ZERO))
            : Money::ZERO;

        $outstanding = Money::of((string) ($row->outstanding ?? Money::ZERO));

        return [
            'available' => true,
            'invoices' => (int) ($row->invoices ?? 0),
            'outstanding' => $outstanding,
            'credits' => $credits,
            'net' => Money::sub($outstanding, $credits),
        ];
    }

    /**
     * What is past its due date and still unpaid, with the oldest one named.
     *
     * @return array{available: bool, count: int, amount: string, oldest_due: ?string, worst: ?object}
     */
    public function overdueInvoices(?User $viewer = null): array
    {
        if (! $this->may($viewer, 'invoices')) {
            return ['available' => false, 'count' => 0, 'amount' => Money::ZERO, 'oldest_due' => null, 'worst' => null];
        }

        $today = Carbon::now(Format::timezone())->startOfDay()->toDateString();

        $base = fn () => $this->db->table('invoices as i')
            ->leftJoin('clients as c', 'c.id', '=', 'i.client_id')
            ->whereIn('i.status', [InvoiceStatus::Sent->value, InvoiceStatus::Partial->value, InvoiceStatus::Overdue->value])
            ->where('i.balance_amount', '>', 0)
            ->where('i.due_date', '<', $today)
            ->whereNull('i.deleted_at');

        $totals = $base()->selectRaw('COUNT(*) as entries, COALESCE(SUM(i.balance_amount), 0) as amount,
            MIN(i.due_date) as oldest_due')->first();

        $worst = $base()
            ->select('i.id', 'i.invoice_number', 'i.due_date', 'i.balance_amount',
                'c.name as client_name', 'c.company_name')
            ->orderBy('i.due_date')
            ->first();

        return [
            'available' => true,
            'count' => (int) ($totals->entries ?? 0),
            'amount' => Money::of((string) ($totals->amount ?? Money::ZERO)),
            'oldest_due' => $totals->oldest_due ?? null,
            'worst' => $worst,
        ];
    }

    /**
     * Every invoice status in the range, counted and totalled.
     *
     * @return array{available: bool, rows: list<array{status: InvoiceStatus, count: int, amount: string}>, total: int}
     */
    public function invoiceStatusBreakdown(DateRange $range, ?User $viewer = null): array
    {
        if (! $this->may($viewer, 'invoices')) {
            return ['available' => false, 'rows' => [], 'total' => 0];
        }

        $counts = $this->db->table('invoices')
            ->whereNull('deleted_at')
            ->tap(fn ($q) => $range->applyDates($q, 'issue_date'))
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as entries, COALESCE(SUM(total_amount), 0) as amount')
            ->get()
            ->keyBy('status');

        $rows = [];

        // Every case, in the enum's own order, so the card does not reshuffle itself as statuses come
        // and go — and a zero row still says "none of these".
        foreach (InvoiceStatus::cases() as $status) {
            $row = $counts->get($status->value);

            $rows[] = [
                'status' => $status,
                'count' => (int) ($row->entries ?? 0),
                'amount' => Money::of((string) ($row->amount ?? Money::ZERO)),
            ];
        }

        return [
            'available' => true,
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'count')),
        ];
    }

    /**
     * Claims waiting for somebody to agree them — money the business may be about to owe.
     *
     * @return array{available: bool, count: int, amount: string, oldest: ?string}
     */
    public function pendingExpenseApprovals(?User $viewer = null): array
    {
        if (! $this->may($viewer, 'expenses')) {
            return ['available' => false, 'count' => 0, 'amount' => Money::ZERO, 'oldest' => null];
        }

        $row = $this->db->table('expenses')
            ->where('status', ExpenseStatus::Pending->value)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(net_amount), 0) as amount, MIN(expense_date) as oldest')
            ->first();

        return [
            'available' => true,
            'count' => (int) ($row->entries ?? 0),
            'amount' => Money::of((string) ($row->amount ?? Money::ZERO)),
            'oldest' => $row->oldest ?? null,
        ];
    }

    /**
     * Income against expenses, month by month, for the trend card.
     *
     * Both series are cash on their own value dates — the same basis the reports use — so the chart and
     * the profit-and-loss statement tell one story rather than two similar ones.
     *
     * @return array{available: bool, labels: list<string>, income: list<string>, expenses: list<string>, profit: list<string>}
     */
    public function incomeVsExpenseByMonth(int $months = 12, ?User $viewer = null): array
    {
        $months = max(1, min(36, $months));

        if (! $this->may($viewer, 'income') && ! $this->may($viewer, 'expenses')) {
            return ['available' => false, 'labels' => [], 'income' => [], 'expenses' => [], 'profit' => []];
        }

        $start = Carbon::now(Format::timezone())->startOfMonth()->subMonths($months - 1);

        $labels = [];
        $keys = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $keys[] = $month->format('Y-m');
            $labels[] = $month->format('M y');
        }

        $income = $this->monthlySums('incomes', 'received_on', 'net_amount', $start,
            fn ($q) => $q->where('status', IncomeStatus::Recorded->value)->whereNull('deleted_at'),
            $this->may($viewer, 'income'));

        $projects = $this->monthlySums('project_payments', 'paid_on', 'net_received_amount', $start,
            fn ($q) => $q->whereNot('status', ReceivedPaymentStatus::Voided->value),
            $this->may($viewer, 'project_payments'));

        $fees = $this->monthlySums('student_fee_payments', 'paid_on', 'net_received_amount', $start,
            fn ($q) => $q->whereNot('status', ReceivedPaymentStatus::Voided->value),
            $this->may($viewer, 'student_fee_payments'));

        $spent = $this->monthlySums('expenses', 'expense_date', 'net_amount', $start,
            fn ($q) => $q->where('status', ExpenseStatus::Approved->value)->whereNull('deleted_at'),
            $this->may($viewer, 'expenses'));

        $in = [];
        $out = [];
        $profit = [];

        foreach ($keys as $key) {
            $received = Money::sum([
                $income[$key] ?? Money::ZERO,
                $projects[$key] ?? Money::ZERO,
                $fees[$key] ?? Money::ZERO,
            ]);
            $paid = $spent[$key] ?? Money::ZERO;

            $in[] = $received;
            $out[] = $paid;
            $profit[] = Money::sub($received, $paid);
        }

        return [
            'available' => true,
            'labels' => $labels,
            'income' => $in,
            'expenses' => $out,
            'profit' => $profit,
        ];
    }

    /**
     * `Y-m => total` for one table, or an empty map when the reader may not see it.
     *
     * @return array<string, string>
     */
    private function monthlySums(
        string $table,
        string $dateColumn,
        string $amountColumn,
        Carbon $start,
        callable $constrain,
        bool $allowed,
    ): array {
        if (! $allowed) {
            return [];
        }

        /*
        | phase-24-25 6.6. The two column names below are interpolated into SQL, because a column
        | identifier cannot be a bound parameter. Every caller passes a literal, so nothing reaches
        | here from a request - but that is a property of the callers, not of this method, and a
        | later refactor that threads a sort column through would silently change it.
        |
        | Refused rather than escaped: a column name that is not a plain identifier is a bug at the
        | call site, and quoting it would hide the bug while running the query anyway.
        */
        foreach (['table' => $table, 'date column' => $dateColumn, 'amount column' => $amountColumn] as $what => $identifier) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'monthlySums(): [%s] is not a plain identifier, and identifiers are interpolated here.',
                    $what,
                ));
            }
        }

        $rows = $this->db->table($table)
            ->where($dateColumn, '>=', $start->toDateString())
            ->tap($constrain)
            ->groupByRaw("DATE_FORMAT({$dateColumn}, '%Y-%m')")
            ->selectRaw("DATE_FORMAT({$dateColumn}, '%Y-%m') as period, COALESCE(SUM({$amountColumn}), 0) as total")
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row->period] = Money::of((string) $row->total);
        }

        return $map;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * A reader who may not see a source's figures cannot see it inside a total either.
     */
    private function may(?User $viewer, string $module): bool
    {
        if ($viewer === null) {
            return true;
        }

        return $viewer->can($module.'.view_reports') && $viewer->can($module.'.view_financial');
    }

    /**
     * @param  list<string>  $omitted
     * @return array<string, mixed>
     */
    private function meta(DateRange $range, string $dateColumn, array $filters, array $omitted, array $extra = []): array
    {
        return array_merge([
            'basis' => 'cash',
            'date_column' => $dateColumn,
            'from' => $range->start()->toDateString(),
            'to' => $range->end()->toDateString(),
            'range_label' => $range->label(),
            'filters' => array_filter($filters, static fn ($value): bool => $value !== null && $value !== ''),
            'omitted_sources' => $omitted,
            'generated_at' => Carbon::now(Format::timezone())->toDateTimeString(),
        ], $extra);
    }
}
