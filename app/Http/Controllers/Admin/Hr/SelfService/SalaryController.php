<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr\SelfService;

use App\Services\Hr\EmployeeScopeResolver;
use App\Services\Hr\MySalaryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * `/admin/my/salary` — "My Salary & Deductions": the employee's own finalized payroll by day, month and
 * year, with every deduction's date, reason, calculation and amount (phase-07 §7.7 self-service).
 *
 * Read only. The employee is the signed-in user's own record (`SelfServiceController::employee()`, 404
 * when there is none); no id from the request reaches a query, and the filters only pick a date, a month
 * or a year of that one employee's slips.
 */
final class SalaryController extends SelfServiceController
{
    private const VIEWS = ['day', 'month', 'year'];

    public function __construct(
        EmployeeScopeResolver $scopes,
        private readonly MySalaryService $salary,
    ) {
        parent::__construct($scopes);
    }

    public function index(Request $request): View
    {
        $employee = $this->employee($request);
        $view = in_array($request->query('view'), self::VIEWS, true) ? (string) $request->query('view') : 'month';
        $today = Carbon::today();

        $data = [
            'employee' => $employee,
            'view' => $view,
            'years' => $this->salary->years($employee),
        ];

        if ($view === 'day') {
            $date = $this->parse((string) $request->query('date', ''), 'Y-m-d') ?? $today;
            $date = $date->greaterThan($today) ? $today : $date;

            return view('admin.hr.my.salary', $data + [
                'date' => $date,
                'day' => $this->salary->day($employee, $date),
            ]);
        }

        if ($view === 'year') {
            $year = (int) $request->query('year', (string) $today->year);
            $year = ($year < 2000 || $year > (int) $today->year + 1) ? (int) $today->year : $year;

            return view('admin.hr.my.salary', $data + [
                'year' => $this->salary->year($employee, $year),
            ]);
        }

        $month = $this->parse((string) $request->query('month', ''), 'Y-m') ?? $this->salary->defaultMonth($employee);

        return view('admin.hr.my.salary', $data + [
            'monthDate' => $month->copy()->startOfMonth(),
            'month' => $this->salary->month($employee, (int) $month->year, (int) $month->month),
        ]);
    }

    private function parse(string $value, string $format): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat($format === 'Y-m' ? 'Y-m-d' : $format, $format === 'Y-m' ? $value.'-01' : $value);
        } catch (Throwable) {
            return null;
        }

        return $date === false ? null : $date->startOfDay();
    }
}
