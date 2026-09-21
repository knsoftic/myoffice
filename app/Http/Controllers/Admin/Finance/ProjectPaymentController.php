<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Finance;

use App\DataObjects\Finance\RefundData;
use App\Enums\CommissionProcessingState;
use App\Enums\PaymentMethod;
use App\Enums\ReceivedPaymentStatus;
use App\Enums\ReversalType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Finance\RecordProjectPaymentRequest;
use App\Models\Collaborator\Collaborator;
use App\Models\Crm\Client;
use App\Models\Finance\ProjectPayment;
use App\Models\Project\Project;
use App\Services\Finance\PaymentService;
use App\Support\CsvWriter;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The project payments register — `admin.project-payments.*` (phase-11 §7.2, §8.2 project variant).
 *
 * The student register's twin, and deliberately so: the same filters, the same three totals, the same
 * value-date toggle, the same absence of an edit route. What differs is the document a payment belongs
 * to — a project, optionally a milestone, optionally an invoice.
 *
 * **Every route here carries `module:project_payments`**, not the `payments` umbrella (F-6.1). The
 * umbrella belongs to Phase 13's cross-source finance register; switching it off must not take this
 * register with it.
 */
final class ProjectPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    public function index(Request $request): View
    {
        $filtered = $this->filtered($request);

        $totals = (clone $filtered)
            ->selectRaw('COALESCE(SUM(amount), 0) as amount')
            ->selectRaw('COALESCE(SUM(refunded_amount), 0) as refunded')
            ->selectRaw('COALESCE(SUM(net_received_amount), 0) as net')
            ->first();

        return view('admin.project-payments.index', [
            'payments' => $filtered
                ->with([
                    'project:id,code,name,client_id',
                    'client:id,client_code,name',
                    'milestone:id,title',
                    'collaborator:id,collaborator_code,name,company_name',
                ])
                ->latest('paid_on')
                ->latest('id')
                ->paginate(30)
                ->withQueryString(),
            'totals' => [
                'amount' => Money::of((string) ($totals->amount ?? '0.00')),
                'refunded' => Money::of((string) ($totals->refunded ?? '0.00')),
                'net' => Money::of((string) ($totals->net ?? '0.00')),
            ],
            'methods' => PaymentMethod::cases(),
            'statuses' => ReceivedPaymentStatus::cases(),
            'states' => CommissionProcessingState::cases(),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name', 'client_code']),
            'collaborators' => Collaborator::query()->orderBy('name')->get(['id', 'name', 'company_name', 'collaborator_code']),
            'range' => $this->range($request),
            'dateColumn' => $this->dateColumn($request),
        ]);
    }

    public function show(ProjectPayment $payment): View
    {
        return view('admin.project-payments.show', [
            'payment' => $payment->load([
                'project:id,code,name,client_id,project_value,net_value',
                'client:id,client_code,name',
                'milestone:id,title,amount',
                'collaborator:id,collaborator_code,name,company_name',
                'referral:id,referral_code,referral_source,effective_from',
                'reversals',
                'commissionEntries',
            ]),
        ]);
    }

    public function store(RecordProjectPaymentRequest $request, Project $project): RedirectResponse
    {
        $result = $this->payments->recordProjectPayment($project, $request->toPaymentData());

        return redirect()
            ->route('admin.project-payments.show', $result->payment)
            ->with('toast', [
                'type' => $result->created ? 'success' : 'info',
                'message' => $result->created
                    ? sprintf('Payment %s recorded for %s. Commission is evaluated in the background.',
                        (string) $result->payment->payment_no, Money::format((string) $result->payment->amount))
                    : sprintf('This was already recorded as payment %s — nothing was taken twice.',
                        (string) $result->payment->payment_no),
            ]);
    }

    public function refund(Request $request, ProjectPayment $payment): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'type' => ['required', 'string'],
            'refund_method' => ['nullable', 'string', 'max:32'],
            'reference_no' => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
        ]);

        $reversal = $this->payments->refund($payment, new RefundData(
            amount: (string) $validated['amount'],
            reason: (string) $validated['reason'],
            type: ReversalType::from((string) $validated['type']),
            method: $validated['refund_method'] ?? null,
            idempotencyKey: (string) $validated['idempotency_key'],
            referenceNo: $validated['reference_no'] ?? null,
        ));

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s returned on %s. %s',
                Money::format((string) $reversal->amount),
                (string) $reversal->reversal_no,
                $reversal->mayReverseCommission()
                    ? 'Any commission it earned is being undone.'
                    : 'Nothing is undone until somebody approves it.'),
        ]);
    }

    public function void(Request $request, ProjectPayment $payment): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $this->payments->void($payment, (string) $validated['reason']);

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('Payment %s is voided. Enter the corrected one as a new payment.',
                (string) $payment->payment_no),
        ]);
    }

    public function receipt(ProjectPayment $payment): View
    {
        return view('admin.project-payments.receipt', [
            'payment' => $payment->load('project:id,code,name', 'client:id,client_code,name'),
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse|RedirectResponse
    {
        if ($format !== 'csv') {
            return back()->with('toast', ['type' => 'error', 'message' => 'Only CSV export is available.']);
        }

        $rows = $this->filtered($request)
            ->with('project:id,code', 'client:id,client_code', 'collaborator:id,collaborator_code')
            ->latest('paid_on')
            ->limit(5000)
            ->get();

        return (new CsvWriter)->download(
            'project-payments-'.app_date(now(), 'Y-m-d').'.csv',
            ['Payment', 'Paid on', 'Project', 'Client', 'Method', 'Amount', 'Refunded', 'Net received', 'Advance', 'Status', 'Commission', 'Partner'],
            $rows->map(static fn (ProjectPayment $p): array => [
                (string) $p->payment_no,
                $p->paid_on->toDateString(),
                (string) $p->project?->code,
                (string) $p->client?->client_code,
                $p->payment_method->label(),
                (string) $p->amount,
                (string) $p->refunded_amount,
                (string) $p->net_received_amount,
                $p->is_advance ? 'yes' : 'no',
                $p->status->label(),
                $p->commission_state->label().($p->commission_skip_reason === null ? '' : ': '.$p->commission_skip_reason->label()),
                (string) $p->collaborator?->collaborator_code,
            ])->all(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function filtered(Request $request): Builder
    {
        $query = ProjectPayment::query()
            ->when($request->filled('project'), fn (Builder $q) => $q->where('project_id', $request->integer('project')))
            ->when($request->filled('client'), fn (Builder $q) => $q->where('client_id', $request->integer('client')))
            ->when($request->filled('milestone'), fn (Builder $q) => $q->where('project_milestone_id', $request->integer('milestone')))
            ->when($request->filled('method'), fn (Builder $q) => $q->where('payment_method', $request->string('method')->toString()))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('commission_state'), fn (Builder $q) => $q->where('commission_state', $request->string('commission_state')->toString()))
            ->when($request->filled('collaborator'), fn (Builder $q) => $q->where('collaborator_id', $request->integer('collaborator')))
            ->when($request->boolean('advances_only'), fn (Builder $q) => $q->where('is_advance', true))
            ->when($request->boolean('has_refund'), fn (Builder $q) => $q->where('refunded_amount', '>', 0))
            ->when($request->filled('min_amount'), fn (Builder $q) => $q->where('amount', '>=', $request->string('min_amount')->toString()))
            ->when($request->filled('max_amount'), fn (Builder $q) => $q->where('amount', '<=', $request->string('max_amount')->toString()))
            ->when($request->filled('q'), function (Builder $q) use ($request): void {
                $term = '%'.$request->string('q')->toString().'%';

                $q->where(fn (Builder $inner) => $inner
                    ->where('payment_no', 'like', $term)
                    ->orWhere('reference_no', 'like', $term)
                    ->orWhereHas('project', fn (Builder $p) => $p->where('code', 'like', $term)->orWhere('name', 'like', $term)));
            });

        $this->range($request)->applyDates($query, $this->dateColumn($request));

        return $query;
    }

    private function dateColumn(Request $request): string
    {
        return $request->string('date_column')->toString() === 'recorded_at' ? 'recorded_at' : 'paid_on';
    }

    private function range(Request $request): DateRange
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        if ($from !== '' && $to !== '') {
            return DateRange::custom($from, $to);
        }

        return DateRange::lastDays(90);
    }
}
