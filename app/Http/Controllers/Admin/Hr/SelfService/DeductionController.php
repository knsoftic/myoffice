<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr\SelfService;

use App\Models\Hr\AttendanceDeduction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The employee's own attendance deductions — every one, whatever its status, newest first.
 *
 * Read only through the signed-in user's own employee row (`SelfServiceController::employee()`); no id
 * from the request reaches the query, so nobody can read another employee's deductions.
 */
final class DeductionController extends SelfServiceController
{
    public function index(Request $request): View
    {
        $employee = $this->employee($request);

        return view('admin.hr.my.deductions', [
            'employee' => $employee,
            'deductions' => AttendanceDeduction::query()
                ->where('employee_id', $employee->getKey())
                ->orderByDesc('deduction_date')
                ->orderByDesc('id')
                ->paginate(per_page()),
        ]);
    }
}
