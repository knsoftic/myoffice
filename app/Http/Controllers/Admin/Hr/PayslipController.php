<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Enums\PayrollItemStatus;
use App\Http\Controllers\Controller;
use App\Models\Hr\PayrollRunItem;
use App\Services\Hr\EmployeeScopeResolver;
use App\Services\Hr\PayslipService;
use App\Support\CsvWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Salary slips — `admin.payslips.*` (phase-07 §7.6, §8.20), `module:salary_slips`.
 *
 * A separate module from `payroll` on purpose (§4.1): an Accountant reads and prints slips without
 * holding the right to lock a run.
 *
 * **The slip is rendered from its stored rows, never recomputed.** If this screen re-ran the calculator,
 * a settings change or a retired component could make a printed slip disagree with the copy an employee
 * already has — and theirs is the one that matters.
 */
final class PayslipController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PayslipService $payslips,
        private readonly EmployeeScopeResolver $scopes,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PayrollRunItem::class);

        $month = $request->query('month');
        $period = $month === null ? null : Carbon::parse((string) $month)->startOfMonth();

        $query = PayrollRunItem::query()
            ->with(['employee:id,name,employee_code', 'run:id,run_number,period_year,period_month,run_type'])
            ->when($period !== null, fn ($scoped) => $scoped->whereHas('run', fn ($inner) => $inner
                ->where('period_year', $period->year)
                ->where('period_month', $period->month)))
            ->when($request->filled('status'), fn ($scoped) => $scoped
                ->where('status', $request->string('status')->toString()))
            ->orderByDesc('id');

        $this->scopes->apply($query, $request->user());

        return view('admin.hr.payslips.index', [
            'slips' => $query->paginate(30)->withQueryString(),
            'statuses' => PayrollItemStatus::options(),
            'month' => $period,
        ]);
    }

    public function show(PayrollRunItem $item): View
    {
        $this->authorize('view', $item);

        return view('admin.hr.payslips.show', $this->payslips->viewData($item));
    }

    public function print(PayrollRunItem $item): View
    {
        $this->authorize('print', $item);

        return view('admin.hr.payslips.print', $this->payslips->viewData($item));
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        $this->authorize('export', PayrollRunItem::class);

        abort_unless($format === 'csv', 404);

        $query = PayrollRunItem::query()->with(['employee:id,name,employee_code', 'run:id,run_number']);
        $this->scopes->apply($query, $request->user());

        $rows = $query->orderByDesc('id')->get()->map(fn (PayrollRunItem $item): array => [
            $item->slip_number,
            $item->run?->run_number,
            $item->employee?->employee_code,
            $item->employee?->name,
            (string) $item->gross_earnings,
            (string) $item->total_deductions,
            (string) $item->net_salary,
            $item->status->label(),
            $item->paid_at?->toDateString(),
        ])->all();

        return (new CsvWriter)->download(
            'salary-slips.csv',
            ['Slip', 'Run', 'Code', 'Employee', 'Gross', 'Deductions', 'Net', 'Status', 'Paid on'],
            $rows,
        );
    }
}
