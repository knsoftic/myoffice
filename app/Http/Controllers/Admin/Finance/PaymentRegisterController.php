<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Finance;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CsvWriter;
use App\Support\DateRange;
use App\Support\FinanceVisibility;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The cross-source payments register — `admin.payments.*` (§29, phase-13 §7.5, §8.13).
 *
 * **One place where every rupee received is visible, whichever business it came from.** Three
 * `UNION ALL` sub-selects — project payments, student fees, other income — normalised to one shape and
 * **paginated by the database**, never by pulling three registers into memory and merging them in PHP.
 *
 * **A source the reader may not see is not in the union at all**, and the screen says which ones were
 * left out. A total that quietly omits a third of its inputs is worse than a refusal: somebody will
 * quote it.
 *
 * **Read-only.** Every write action deep-links to the register that owns the row, because the rules
 * about refunding a fee receipt live with fees and the rules about voiding a project payment live with
 * the spine. A second place to do either would eventually disagree with the first.
 */
final class PaymentRegisterController extends Controller
{
    /**
     * `source key => [label, module slug, the route that owns the row]`.
     */
    private const SOURCES = [
        'project_payment' => ['Project payment', 'project_payments'],
        'student_fee' => ['Student fee', 'student_fee_payments'],
        'income' => ['Other income', 'income'],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function index(Request $request): View
    {
        $range = $this->range($request);
        $user = $request->user();
        $allowed = $this->allowedSources($user);

        $union = $this->union($request, $range, $allowed);

        $payments = $union === null
            ? new LengthAwarePaginator([], 0, 25, (int) $request->input('page', 1), [
                'path' => $request->url(), 'query' => $request->query(),
            ])
            : $this->db->query()->fromSub($union, 'p')
                ->orderByDesc('paid_on')->orderByDesc('reference')
                ->paginate(25)->withQueryString();

        return view('admin.payments.index', [
            'payments' => $payments,
            'fields' => FinanceVisibility::for($user, 'payments'),
            'range' => $range,
            'methods' => PaymentMethod::cases(),
            'sources' => $this->sourceLabels($allowed),
            'omitted' => $this->omittedLabels($allowed),
            'totals' => $union === null ? [] : $this->totals($union),
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        abort_unless($format === 'csv', 404);

        $range = $this->range($request);
        $fields = FinanceVisibility::for($request->user(), 'payments');
        $allowed = $this->allowedSources($request->user());
        $union = $this->union($request, $range, $allowed);

        return (new CsvWriter)->download(
            'payments-'.app_date(now(), 'Y-m-d').'.csv',
            array_map(static fn (string $f): string => ucfirst(str_replace('_', ' ', $f)), $fields->columns()),
            $union === null
                ? []
                : CsvWriter::rowsFrom(
                    $this->db->query()->fromSub($union, 'p')->orderByDesc('paid_on'),
                    static fn (object $row): array => array_values($fields->filter([
                        'reference' => (string) $row->reference,
                        'source' => self::SOURCES[$row->source][0] ?? $row->source,
                        'payer' => (string) $row->payer,
                        'paid_on' => (string) $row->paid_on,
                        'payment_method' => (PaymentMethod::tryFrom((string) $row->payment_method)?->label())
                            ?? (string) $row->payment_method,
                        'status' => (string) $row->status,
                        'amount' => (string) $row->amount,
                        'refunded_amount' => (string) $row->refunded_amount,
                        'net_received_amount' => (string) $row->net_received_amount,
                    ])),
                    column: 'reference',
                ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The union
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<string>  $allowed
     */
    private function union(Request $request, DateRange $range, array $allowed): ?Builder
    {
        // A source the reader picked in the filter narrows the union further; one they may not see was
        // never in it.
        $wanted = $request->filled('source')
            ? array_values(array_intersect($allowed, [(string) $request->input('source')]))
            : $allowed;

        $parts = [];

        if (in_array('project_payment', $wanted, true)) {
            $parts[] = $this->filtered($this->db->table('project_payments as pp')
                ->leftJoin('clients as c', 'c.id', '=', 'pp.client_id')
                ->selectRaw("'project_payment' as source, pp.id as source_id, pp.payment_no as reference,
                    COALESCE(c.company_name, c.name, '') as payer, pp.paid_on, pp.recorded_at,
                    pp.payment_method, pp.status, pp.amount, pp.refunded_amount, pp.net_received_amount")
                ->tap(fn ($q) => $range->applyDates($q, 'pp.paid_on')), $request, 'pp.payment_method', 'pp.refunded_amount');
        }

        if (in_array('student_fee', $wanted, true)) {
            $parts[] = $this->filtered($this->db->table('student_fee_payments as sf')
                // The payer is blank until the institute phases ship a `students` table to join.
                // A receipt number with no name is still a receipt; inventing one would not be.
                ->selectRaw("'student_fee' as source, sf.id as source_id, sf.receipt_no as reference,
                    '' as payer, sf.paid_on, sf.recorded_at,
                    sf.payment_method, sf.status, sf.amount, sf.refunded_amount, sf.net_received_amount")
                ->tap(fn ($q) => $range->applyDates($q, 'sf.paid_on')), $request, 'sf.payment_method', 'sf.refunded_amount');
        }

        if (in_array('income', $wanted, true)) {
            $parts[] = $this->filtered($this->db->table('incomes as i')
                ->whereNull('i.deleted_at')
                ->selectRaw("'income' as source, i.id as source_id, i.income_no as reference,
                    COALESCE(i.received_from, '') as payer, i.received_on as paid_on, i.recorded_at,
                    i.payment_method, i.status, i.amount, i.refunded_amount, i.net_amount as net_received_amount")
                ->tap(fn ($q) => $range->applyDates($q, 'i.received_on')), $request, 'i.payment_method', 'i.refunded_amount');
        }

        if ($parts === []) {
            return null;
        }

        $union = array_shift($parts);

        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return $union;
    }

    /**
     * The filters go on each part **before** the union, so every table uses its own indexes instead of
     * the database scanning the merged result.
     */
    private function filtered(Builder $query, Request $request, string $methodColumn, string $refundColumn): Builder
    {
        return $query
            ->when($request->filled('method'), fn (Builder $q) => $q->where($methodColumn, (string) $request->input('method')))
            ->when($request->boolean('refunded'), fn (Builder $q) => $q->where($refundColumn, '>', 0));
    }

    /**
     * @return array<string, string>
     */
    private function totals(Builder $union): array
    {
        $row = $this->db->query()->fromSub($union, 'p')
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(amount), 0) as amount,
                COALESCE(SUM(refunded_amount), 0) as refunded, COALESCE(SUM(net_received_amount), 0) as net')
            ->first();

        return [
            'entries' => (string) (int) ($row->entries ?? 0),
            'amount' => Money::of((string) ($row->amount ?? Money::ZERO)),
            'refunded' => Money::of((string) ($row->refunded ?? Money::ZERO)),
            'net' => Money::of((string) ($row->net ?? Money::ZERO)),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Who may see what
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<string>
     */
    private function allowedSources(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_filter(
            array_keys(self::SOURCES),
            static fn (string $key): bool => $user->can(self::SOURCES[$key][1].'.view_any')
                && $user->can(self::SOURCES[$key][1].'.view_financial'),
        ));
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, string>
     */
    private function sourceLabels(array $allowed): array
    {
        $labels = [];

        foreach ($allowed as $key) {
            $labels[$key] = self::SOURCES[$key][0];
        }

        return $labels;
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function omittedLabels(array $allowed): array
    {
        return array_values(array_map(
            static fn (string $key): string => self::SOURCES[$key][0],
            array_diff(array_keys(self::SOURCES), $allowed),
        ));
    }

    private function range(Request $request): DateRange
    {
        return DateRange::make($request->input('preset'), $request->input('from'), $request->input('to'));
    }
}
