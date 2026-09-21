<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Finance;

use App\DataObjects\Finance\ExpenseData;
use App\DataObjects\Finance\RefundData;
use App\Enums\ExpenseStatus;
use App\Enums\FinanceCategoryType;
use App\Enums\FinanceContext;
use App\Enums\PaymentMethod;
use App\Enums\ReversalType;
use App\Http\Controllers\Controller;
use App\Models\Finance\Expense;
use App\Models\Finance\FinanceCategory;
use App\Models\Finance\PaymentMethodOption;
use App\Models\Project\Project;
use App\Services\Finance\PaymentMethodService;
use App\Services\Finance\ExpenseService;
use App\Support\CsvWriter;
use App\Support\DateRange;
use App\Support\FinanceVisibility;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expenses — `admin.expenses.*` (phase-13 §7.2, §8.8).
 *
 * Three different acts, three different permissions, and they are genuinely different jobs: `create` is
 * anybody claiming a cost, `approve` is somebody agreeing it is the company's, and `change_status` is
 * voiding one that has already been agreed. A single `edit` would have collapsed all three into one
 * permission nobody could hand out safely.
 *
 * The receipt lives on the **private** disk and is streamed by a route that re-runs the permission
 * chain (D21). A receipt scan names a supplier and an amount; a guessable public URL for it would be a
 * leak nobody would notice.
 */
final class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly PaymentMethodService $paymentMethods,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.expenses.index', $this->listData($request, 'admin.expenses.index'));
    }

    /**
     * The approval queue: the same list, narrowed, and worked oldest first.
     *
     * Its own screen rather than a tab, because a claim waiting for a decision is work somebody has to
     * notice — and a queue nobody can see from the sidebar is a queue that grows.
     */
    public function approvals(Request $request): View
    {
        $request->merge(['status' => ExpenseStatus::Pending->value]);

        return view('admin.expenses.approvals', array_merge(
            $this->listData($request, 'admin.expenses.approvals', oldestFirst: true),
            ['canApprove' => (bool) $request->user()?->can('expenses.approve')],
        ));
    }

    public function create(Request $request): View
    {
        return view('admin.expenses.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateExpense($request);

        $expense = $this->expenses->record(
            $this->dataFrom($request, $validated),
            $request->user(),
        );

        return redirect()->route('admin.expenses.show', $expense)->with('toast', [
            'type' => 'success',
            'message' => $expense->status === ExpenseStatus::Pending
                ? sprintf('%s recorded and waiting for approval. It does not count in any report until '
                    .'somebody approves it.', $expense->expense_no)
                : sprintf('%s recorded and approved.', $expense->expense_no),
        ]);
    }

    public function show(Request $request, Expense $expense): View
    {
        $expense->load(['category', 'project:id,code,name', 'paymentMethod', 'reversals.performer:id,name',
            'approver:id,name', 'rejecter:id,name', 'voider:id,name', 'corrects:id,expense_no']);

        return view('admin.expenses.show', [
            'expense' => $expense,
            'fields' => FinanceVisibility::for($request->user(), 'expenses'),
            'canApprove' => (bool) $request->user()?->can('expenses.approve'),
            'canReject' => (bool) $request->user()?->can('expenses.reject'),
            'canChangeStatus' => (bool) $request->user()?->can('expenses.change_status'),
            'canEdit' => (bool) $request->user()?->can('expenses.edit'),
            'refundMethods' => PaymentMethod::cases(),
        ]);
    }

    public function edit(Request $request, Expense $expense): View
    {
        return view('admin.expenses.edit', array_merge($this->formData(), ['expense' => $expense]));
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $validated = $this->validateExpense($request);

        $this->expenses->update($expense, $this->dataFrom($request, $validated), $request->user());

        return redirect()->route('admin.expenses.show', $expense)->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was updated.', $expense->expense_no),
        ]);
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        if (! $expense->status->isDeletable()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => sprintf('%s is %s. Once somebody has decided about an expense it is voided '
                    .'with a reason, never deleted — the decision is part of the record.',
                    $expense->expense_no, strtolower($expense->status->label())),
            ]);
        }

        $expense->delete();

        return redirect()->route('admin.expenses.index')->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was deleted. Nobody had acted on it.', $expense->expense_no),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Deciding
    |--------------------------------------------------------------------------
    */

    public function approve(Request $request, Expense $expense): RedirectResponse
    {
        $this->expenses->approve($expense, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s approved. It now counts in the expense report and the profit-and-loss statement.',
                $expense->expense_no),
        ]);
    }

    public function reject(Request $request, Expense $expense): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'The reason is what the person who claimed it is told.',
        ]);

        $this->expenses->reject($expense, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s rejected. The row stays with its reason on it.', $expense->expense_no),
        ]);
    }

    /**
     * Explicit ids only — never "everything matching the filter".
     */
    public function bulkApprove(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $result = $this->expenses->approveMany($validated['ids'], $request->user());

        return back()->with('toast', [
            'type' => $result->skipped === [] ? 'success' : 'warning',
            'message' => sprintf('%d approved, totalling %s.%s',
                $result->count(),
                Money::format($result->total),
                $result->skipped === []
                    ? ''
                    : ' '.count($result->skipped).' were skipped: '.implode(' ', $result->skipped),
            ),
        ]);
    }

    public function void(Request $request, Expense $expense): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'Voiding takes a figure out of a report somebody may already have read.',
        ]);

        $this->expenses->void($expense, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s is voided and out of every report. Re-enter the correction and link '
                .'it back to this one.', $expense->expense_no),
        ]);
    }

    public function storeReversal(Request $request, Expense $expense): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'type' => ['required', 'in:full_refund,partial_refund,void,correction'],
            'refund_method' => ['nullable', 'string'],
            'reference_no' => ['nullable', 'string', 'max:64'],
            'occurred_on' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        $reversal = $this->expenses->refund($expense, new RefundData(
            amount: Money::of((string) $validated['amount']),
            reason: $validated['reason'],
            type: ReversalType::from($validated['type']),
            method: $validated['refund_method'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
            refundedOn: isset($validated['occurred_on'])
                ? Carbon::parse($validated['occurred_on'])
                : null,
            referenceNo: $validated['reference_no'] ?? null,
        ), $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s recorded: %s came back. Every report picks it up through `net_amount`.',
                $reversal->reversal_no, Money::format((string) $reversal->amount)),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The receipt, and the file
    |--------------------------------------------------------------------------
    */

    /**
     * Streamed by this route and only this route, which re-runs the permission chain (D21).
     */
    public function receipt(Expense $expense): StreamedResponse
    {
        abort_if($expense->receipt_path === null, 404);
        abort_unless(Storage::disk('local')->exists($expense->receipt_path), 404);

        return Storage::disk('local')->download(
            $expense->receipt_path,
            sprintf('%s-receipt.%s', $expense->expense_no, pathinfo($expense->receipt_path, PATHINFO_EXTENSION)),
        );
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        abort_unless($format === 'csv', 404);

        $fields = FinanceVisibility::for($request->user(), 'expenses');

        return (new CsvWriter)->download(
            'expenses-'.app_date(now(), 'Y-m-d').'.csv',
            array_map(static fn (string $f): string => ucfirst(str_replace('_', ' ', $f)), $fields->columns()),
            CsvWriter::rowsFrom(
                $this->filtered($request)->with(['category:id,name', 'project:id,code']),
                static fn (Expense $expense): array => array_values($fields->filter([
                    'expense_no' => (string) $expense->expense_no,
                    'category' => (string) $expense->category?->name,
                    'context' => $expense->context->label(),
                    'title' => (string) $expense->title,
                    'paid_to' => (string) $expense->paid_to,
                    'expense_date' => $expense->expense_date->toDateString(),
                    'payment_method' => $expense->payment_method->label(),
                    'status' => $expense->status->label(),
                    'amount' => (string) $expense->amount,
                    'refunded_amount' => (string) $expense->refunded_amount,
                    'net_amount' => (string) $expense->net_amount,
                ])),
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function listData(Request $request, string $route, bool $oldestFirst = false): array
    {
        $fields = FinanceVisibility::for($request->user(), 'expenses');

        $query = $this->filtered($request)->with(['category:id,name', 'project:id,code,name', 'creator:id,name']);

        $expenses = ($oldestFirst
            ? $query->oldest('expense_date')->oldest('id')
            : $query->latest('expense_date')->latest('id'))
            ->paginate(25)
            ->withQueryString();

        return [
            'expenses' => $expenses,
            'fields' => $fields,
            'route' => $route,
            'statuses' => ExpenseStatus::cases(),
            'contexts' => FinanceContext::cases(),
            'categories' => $this->categories(FinanceCategoryType::Expense),
            'range' => $this->range($request),
            // Approved only: a total that quietly included pending claims would move every time
            // somebody typed a number.
            'approvedTotal' => $fields->seesMoney
                ? Money::of((string) ($this->filtered($request)->reorder()->toBase()
                    ->where('status', ExpenseStatus::Approved->value)->sum('net_amount') ?: Money::ZERO))
                : null,
            'pendingCount' => (clone $this->filtered($request))->where('status', ExpenseStatus::Pending->value)->count(),
            'canCreate' => (bool) $request->user()?->can('expenses.create'),
            'canApprove' => (bool) $request->user()?->can('expenses.approve'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'categories' => $this->categories(FinanceCategoryType::Expense),
            'contexts' => FinanceContext::cases(),
            // One source for every method dropdown (§6.6): a method switched off disappears from
            // every form at once rather than lingering on the one screen nobody filtered.
            'methods' => $this->paymentMethods->availableFor('expense'),
            'paymentMethods' => PaymentMethod::cases(),
            'projects' => Project::query()->orderBy('name')->limit(500)->get(['id', 'code', 'name']),
            'receiptRequired' => (bool) setting('finance.expense_receipt_required', false),
            'receiptThreshold' => Money::of((string) setting('finance.expense_receipt_threshold', '0.00')),
        ];
    }

    private function categories(FinanceCategoryType $type)
    {
        return FinanceCategory::query()->ofType($type)->active()->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateExpense(Request $request): array
    {
        return $request->validate([
            'finance_category_id' => ['required', 'integer', 'exists:finance_categories,id'],
            'context' => ['required', 'string'],
            'title' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'expense_date' => ['required', 'date'],
            'payment_method' => ['required', 'string'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'paid_to' => ['nullable', 'string', 'max:150'],
            'reference_no' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:8192'],
        ], [
            'amount.gt' => 'An expense of nothing is not an expense.',
        ]);
    }

    private function dataFrom(Request $request, array $validated): ExpenseData
    {
        $path = null;

        if ($request->hasFile('receipt')) {
            // The private disk, always: a receipt scan names a supplier and an amount.
            $path = $request->file('receipt')->store('expenses/'.now()->format('Y/m'), 'local');
        }

        return new ExpenseData(
            financeCategoryId: (int) $validated['finance_category_id'],
            context: FinanceContext::from((string) $validated['context']),
            title: (string) $validated['title'],
            amount: Money::of((string) $validated['amount']),
            valueDate: Carbon::parse((string) $validated['expense_date']),
            paymentMethod: PaymentMethod::from((string) $validated['payment_method']),
            branchId: $validated['branch_id'] ?? null,
            projectId: $validated['project_id'] ?? null,
            paymentMethodId: $validated['payment_method_id'] ?? null,
            description: $validated['description'] ?? null,
            counterparty: $validated['paid_to'] ?? null,
            referenceNo: $validated['reference_no'] ?? null,
            receiptPath: $path,
            notes: $validated['notes'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
        );
    }

    /**
     * @return Builder<Expense>
     */
    private function filtered(Request $request): Builder
    {
        $range = $this->range($request);

        return Expense::query()
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('q')).'%';

                $query->where(fn (Builder $inner) => $inner
                    ->where('expense_no', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhere('paid_to', 'like', $term));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('category'), fn (Builder $q) => $q->where('finance_category_id', (int) $request->input('category')))
            ->when($request->filled('context'), fn (Builder $q) => $q->where('context', (string) $request->input('context')))
            ->when($request->filled('project'), fn (Builder $q) => $q->where('project_id', (int) $request->input('project')))
            ->when($request->boolean('mine'), fn (Builder $q) => $q->where('created_by', $request->user()?->getKey()))
            ->whereBetween('expense_date', [$range->start()->toDateString(), $range->end()->toDateString()]);
    }

    private function range(Request $request): DateRange
    {
        return DateRange::make($request->input('preset'), $request->input('from'), $request->input('to'));
    }
}
