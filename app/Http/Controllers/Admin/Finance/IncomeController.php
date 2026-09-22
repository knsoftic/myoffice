<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Finance;

use App\DataObjects\Finance\ExpenseData;
use App\DataObjects\Finance\RefundData;
use App\Enums\FinanceCategoryType;
use App\Enums\FinanceContext;
use App\Enums\IncomeStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReversalType;
use App\Http\Controllers\Controller;
use App\Models\Crm\Client;
use App\Models\Finance\FinanceCategory;
use App\Models\Finance\Income;
use App\Models\Project\Project;
use App\Services\Finance\IncomeService;
use App\Services\Finance\PaymentMethodService;
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
 * Other income — `admin.income.*` (phase-13 §7.3, §8.9).
 *
 * §29's "other income": money in that is **neither** a project payment **nor** a student fee. Those two
 * belong to the spine and fire the commission engine; a row recorded here fires nothing — which is why
 * putting a client's project payment on this screen would look right on every report and quietly pay no
 * commission. The category list makes that hard and the service refuses it outright.
 *
 * No approval workflow: §29 asks for none, and money that has arrived does not need a second person to
 * agree that it arrived. Voiding is the only undoing, and it appends rather than deletes.
 */
final class IncomeController extends Controller
{
    public function __construct(
        private readonly IncomeService $income,
        private readonly PaymentMethodService $paymentMethods,
    ) {}

    public function index(Request $request): View
    {
        $fields = FinanceVisibility::for($request->user(), 'income');

        return view('admin.income.index', [
            'incomes' => $this->filtered($request)
                ->with(['category:id,name', 'client:id,name,company_name', 'project:id,code,name'])
                ->latest('received_on')->latest('id')
                ->paginate(25)->withQueryString(),
            'fields' => $fields,
            'statuses' => IncomeStatus::cases(),
            'contexts' => FinanceContext::cases(),
            'categories' => $this->categories(),
            'range' => $this->range($request),
            'recordedTotal' => $fields->seesMoney
                ? Money::of((string) ($this->filtered($request)->reorder()->toBase()
                    ->where('status', IncomeStatus::Recorded->value)->sum('net_amount') ?: Money::ZERO))
                : null,
            'canCreate' => (bool) $request->user()?->can('income.create'),
        ]);
    }

    public function create(): View
    {
        return view('admin.income.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateIncome($request);

        $income = $this->income->record($this->dataFrom($request, $validated), $request->user());

        return redirect()->route('admin.income.show', $income)->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s recorded.', $income->income_no),
        ]);
    }

    public function show(Request $request, Income $income): View
    {
        $income->load(['category', 'client:id,name,company_name', 'project:id,code,name',
            'paymentMethod', 'reversals.performer:id,name', 'voider:id,name']);

        return view('admin.income.show', [
            'income' => $income,
            'fields' => FinanceVisibility::for($request->user(), 'income'),
            'canEdit' => (bool) $request->user()?->can('income.edit'),
            'canChangeStatus' => (bool) $request->user()?->can('income.change_status'),
            'refundMethods' => PaymentMethod::cases(),
        ]);
    }

    public function edit(Income $income): View
    {
        return view('admin.income.edit', array_merge($this->formData(), ['income' => $income]));
    }

    public function update(Request $request, Income $income): RedirectResponse
    {
        $validated = $this->validateIncome($request);

        $this->income->update($income, $this->dataFrom($request, $validated), $request->user());

        return redirect()->route('admin.income.show', $income)->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was updated.', $income->income_no),
        ]);
    }

    public function destroy(Income $income): RedirectResponse
    {
        if ($income->status === IncomeStatus::Voided || $income->reversals()->exists()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => sprintf('%s has a history against it. A voided row and a refunded one both '
                    .'stay as the record of what happened.', $income->income_no),
            ]);
        }

        $income->delete();

        return redirect()->route('admin.income.index')->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was deleted.', $income->income_no),
        ]);
    }

    public function void(Request $request, Income $income): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'Voiding takes a figure out of a report somebody may already have read.',
        ]);

        $this->income->void($income, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s is voided and out of the income report.', $income->income_no),
        ]);
    }

    public function storeReversal(Request $request, Income $income): RedirectResponse
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

        $reversal = $this->income->refund($income, new RefundData(
            amount: Money::of((string) $validated['amount']),
            reason: $validated['reason'],
            type: ReversalType::from($validated['type']),
            method: $validated['refund_method'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
            refundedOn: isset($validated['occurred_on']) ? Carbon::parse($validated['occurred_on']) : null,
            referenceNo: $validated['reference_no'] ?? null,
        ), $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s recorded: %s went back to the payer.',
                $reversal->reversal_no, Money::format((string) $reversal->amount)),
        ]);
    }

    public function receipt(Income $income): StreamedResponse
    {
        abort_if($income->receipt_path === null, 404);
        abort_unless(Storage::disk('local')->exists($income->receipt_path), 404);

        return Storage::disk('local')->download(
            $income->receipt_path,
            sprintf('%s-receipt.%s', $income->income_no, pathinfo($income->receipt_path, PATHINFO_EXTENSION)),
        );
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        abort_unless($format === 'csv', 404);

        $fields = FinanceVisibility::for($request->user(), 'income');

        return (new CsvWriter)->download(
            'income-'.app_date(now(), 'Y-m-d').'.csv',
            array_map(static fn (string $f): string => ucfirst(str_replace('_', ' ', $f)), $fields->columns()),
            CsvWriter::rowsFrom(
                $this->filtered($request)->with('category:id,name'),
                static fn (Income $income): array => array_values($fields->filter([
                    'income_no' => (string) $income->income_no,
                    'category' => (string) $income->category?->name,
                    'context' => $income->context->label(),
                    'title' => (string) $income->title,
                    'received_from' => (string) $income->received_from,
                    'received_on' => $income->received_on->toDateString(),
                    'payment_method' => $income->payment_method->label(),
                    'status' => $income->status->label(),
                    'amount' => (string) $income->amount,
                    'refunded_amount' => (string) $income->refunded_amount,
                    'net_amount' => (string) $income->net_amount,
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
    private function formData(): array
    {
        return [
            'categories' => $this->categories(),
            'contexts' => FinanceContext::cases(),
            // One source for every method dropdown (§6.6): a method switched off disappears from
            // every form at once rather than lingering on the one screen nobody filtered.
            'methods' => $this->paymentMethods->availableFor('income'),
            'paymentMethods' => PaymentMethod::cases(),
            'projects' => Project::query()->orderBy('name')->limit(500)->get(['id', 'code', 'name']),
            'clients' => Client::query()->orderBy('company_name')->orderBy('name')->limit(500)
                ->get(['id', 'name', 'company_name']),
        ];
    }

    private function categories()
    {
        return FinanceCategory::query()->ofType(FinanceCategoryType::Income)
            ->active()->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateIncome(Request $request): array
    {
        return $request->validate([
            'finance_category_id' => ['required', 'integer', 'exists:finance_categories,id'],
            'context' => ['required', 'string'],
            'title' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'received_on' => ['required', 'date'],
            'payment_method' => ['required', 'string'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'received_from' => ['nullable', 'string', 'max:150'],
            'reference_no' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:8192'],
        ], [
            'amount.gt' => 'A receipt for nothing is not a receipt.',
        ]);
    }

    private function dataFrom(Request $request, array $validated): ExpenseData
    {
        $path = null;

        if ($request->hasFile('receipt')) {
            $path = $request->file('receipt')->store('incomes/'.now()->format('Y/m'), 'local');
        }

        return new ExpenseData(
            financeCategoryId: (int) $validated['finance_category_id'],
            context: FinanceContext::from((string) $validated['context']),
            title: (string) $validated['title'],
            amount: Money::of((string) $validated['amount']),
            valueDate: Carbon::parse((string) $validated['received_on']),
            paymentMethod: PaymentMethod::from((string) $validated['payment_method']),
            branchId: $validated['branch_id'] ?? null,
            projectId: $validated['project_id'] ?? null,
            clientId: $validated['client_id'] ?? null,
            paymentMethodId: $validated['payment_method_id'] ?? null,
            description: $validated['description'] ?? null,
            counterparty: $validated['received_from'] ?? null,
            referenceNo: $validated['reference_no'] ?? null,
            receiptPath: $path,
            notes: $validated['notes'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
        );
    }

    /**
     * @return Builder<Income>
     */
    private function filtered(Request $request): Builder
    {
        $range = $this->range($request);

        return Income::query()
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('q')).'%';

                $query->where(fn (Builder $inner) => $inner
                    ->where('income_no', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhere('received_from', 'like', $term));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('category'), fn (Builder $q) => $q->where('finance_category_id', (int) $request->input('category')))
            ->when($request->filled('context'), fn (Builder $q) => $q->where('context', (string) $request->input('context')))
            ->whereBetween('received_on', [$range->start()->toDateString(), $range->end()->toDateString()]);
    }

    private function range(Request $request): DateRange
    {
        return DateRange::make($request->input('preset'), $request->input('from'), $request->input('to'));
    }
}
