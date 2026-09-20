<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr\SelfService;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * `/admin/my/hr-profile` — what the business holds about me (phase-07 §7.7, §8.21).
 *
 * Read-only on purpose. An employee correcting their own department or joining date would be editing the
 * inputs to their own salary; asking HR is the point.
 */
final class ProfileController extends SelfServiceController
{
    public function show(Request $request): View
    {
        $employee = $this->employee($request);

        $employee->load([
            'department:id,name', 'designation:id,title', 'workShift:id,name',
            'manager:id,name,employee_code', 'skills',
        ]);

        return view('admin.hr.my.profile', [
            'employee' => $employee,
            'showMoney' => (bool) $request->user()?->can('employee_self_service.view_financial'),
        ]);
    }
}
