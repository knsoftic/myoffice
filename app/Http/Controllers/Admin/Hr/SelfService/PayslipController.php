<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr\SelfService;

use App\Models\Hr\PayrollRunItem;
use App\Services\Hr\EmployeeScopeResolver;
use App\Services\Hr\PayslipService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * `/admin/my/payslips` — my own slips (phase-07 §7.7, §9).
 *
 * `employee_self_service.view_financial` is what reveals the amounts: a business that does not want
 * salaries visible on screen can leave the ability off and the whole list disappears rather than showing
 * an empty page with a hint of what is behind it.
 *
 * Somebody else's slip id answers **404**, not 403.
 */
final class PayslipController extends SelfServiceController
{
    public function __construct(
        EmployeeScopeResolver $scopes,
        private readonly PayslipService $payslips,
    ) {
        parent::__construct($scopes);
    }

    public function index(Request $request): View
    {
        $employee = $this->employee($request);

        return view('admin.hr.my.payslips', [
            'employee' => $employee,
            'slips' => $employee->payrollItems()
                ->with('run:id,run_number,period_year,period_month,run_type')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function show(Request $request, PayrollRunItem $item): View
    {
        $employee = $this->employee($request);
        $this->assertOwn($employee, $item->employee_id);

        return view('admin.hr.my.payslip-show', $this->payslips->viewData($item));
    }

    public function print(Request $request, PayrollRunItem $item): View
    {
        $employee = $this->employee($request);
        $this->assertOwn($employee, $item->employee_id);

        return view('admin.hr.payslips.print', $this->payslips->viewData($item));
    }
}
