<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Enums\PayrollRunStatus;
use App\Enums\PayrollRunType;
use App\Http\Controllers\Controller;
use App\Models\Hr\Employee;
use App\Models\Hr\PayrollRun;
use App\Services\Hr\PayrollRunService;
use App\Services\Hr\PayslipService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payroll runs — `admin.payroll-runs.*` (phase-07 §7.6, §8.19), `module:payroll`.
 *
 * The run screen states where it is in the one-way sequence: draft, generated, locked, paid. **There is
 * no unlock button** anywhere, because there is no unlock — a mistake found after locking becomes a
 * correction run, and the screen says so rather than leaving somebody hunting for the button.
 *
 * Generation reports who was **skipped and why**. An employee with no structure or no attendance summary
 * is named, not paid zero: those two look identical on a payslip and could not be more different.
 */
final class PayrollRunController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PayrollRunService $runs,
        private readonly PayslipService $payslips,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PayrollRun::class);

        return view('admin.hr.payroll.index', [
            'runs' => PayrollRun::query()
                ->with(['branch:id,name', 'locker:id,name'])
                ->withCount('items')
                ->when($request->filled('status'), fn ($query) => $query
                    ->where('status', $request->string('status')->toString()))
                ->orderByDesc('period_year')
                ->orderByDesc('period_month')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
            'statuses' => PayrollRunStatus::options(),
            'showMoney' => (bool) $request->user()?->can('payroll.view_financial'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', PayrollRun::class);

        return view('admin.hr.payroll.create', [
            'types' => PayrollRunType::options(),
            'defaultMonth' => now()->subMonthNoOverflow()->format('Y-m'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PayrollRun::class);

        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'title' => ['nullable', 'string', 'max:150'],
            'payment_date' => ['nullable', 'date'],
        ]);

        $period = Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth();

        $run = $this->runs->create(
            year: (int) $period->year,
            month: (int) $period->month,
            branchId: $data['branch_id'] ?? null,
            title: $data['title'] ?? null,
            paymentDate: isset($data['payment_date']) ? Carbon::parse($data['payment_date']) : null,
            actor: $request->user(),
        );

        return redirect()
            ->route('admin.payroll-runs.show', $run)
            ->with('toast', ['type' => 'success', 'message' => $run->run_number.' opened as a draft.']);
    }

    public function show(Request $request, PayrollRun $run): View
    {
        $this->authorize('view', $run);

        $showMoney = (bool) $request->user()?->can('payroll.view_financial');

        $run->load(['branch:id,name', 'locker:id,name', 'generator:id,name', 'parentRun:id,run_number']);

        return view('admin.hr.payroll.show', [
            'run' => $run,
            'items' => $run->items()
                ->with('employee:id,name,employee_code,department_id')
                ->orderBy('id')
                ->paginate(50),
            'showMoney' => $showMoney,
            'canGenerate' => $request->user()?->can('generate', $run) === true,
            'canLock' => $request->user()?->can('approve', $run) === true,
            'canPay' => $request->user()?->can('changeStatus', $run) === true,
            'canCancel' => $request->user()?->can('cancel', $run) === true,
        ]);
    }

    public function generate(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->authorize('generate', $run);

        $report = $this->runs->generate($run, null, $request->user());

        $skipped = collect($report['skipped']);
        $names = Employee::query()
            ->whereIn('id', $skipped->keys())
            ->pluck('name', 'id');

        $detail = $skipped
            ->map(fn (string $reason, int $id): string => sprintf(
                '%s (%s)',
                $names[$id] ?? ('employee #'.$id),
                str_replace('_', ' ', $reason)
            ))
            ->join(', ');

        return back()->with('toast', [
            'type' => $skipped->isEmpty() ? 'success' : 'warning',
            'message' => $skipped->isEmpty()
                ? sprintf('%d slip(s) generated.', $report['generated'])
                : sprintf('%d slip(s) generated. Skipped: %s.', $report['generated'], $detail),
        ]);
    }

    /**
     * The same calculator the generator uses (§6.2), so a preview can never differ from the result.
     */
    public function preview(Request $request, PayrollRun $run, Employee $employee): View
    {
        $this->authorize('viewFinancial', $run);

        return view('admin.hr.payroll.preview', [
            'run' => $run,
            'employee' => $employee,
            'draft' => $this->runs->preview($run, $employee),
        ]);
    }

    public function lock(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->authorize('approve', $run);

        $this->runs->lock($run, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Run locked. Every slip, and the attendance behind them, is now evidence — a mistake becomes a correction run.',
        ]);
    }

    public function cancel(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->authorize('cancel', $run);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $this->runs->cancel($run, $data['reason'], $request->user());

        return redirect()
            ->route('admin.payroll-runs.index')
            ->with('toast', ['type' => 'success', 'message' => $run->run_number.' cancelled.']);
    }

    public function export(PayrollRun $run, string $format): StreamedResponse
    {
        $this->authorize('export', PayrollRun::class);

        abort_unless($format === 'csv', 404);

        return $this->payslips->export($run);
    }

    /**
     * The printable register (§8.19).
     */
    public function register(PayrollRun $run): View
    {
        $this->authorize('print', PayrollRun::class);

        $run->load(['items.employee:id,name,employee_code', 'branch:id,name']);

        return view('admin.hr.payroll.register', ['run' => $run]);
    }
}
