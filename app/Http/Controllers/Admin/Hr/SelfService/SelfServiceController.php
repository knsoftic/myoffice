<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr\SelfService;

use App\Http\Controllers\Controller;
use App\Models\Hr\Employee;
use App\Services\Hr\EmployeeScopeResolver;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

/**
 * The shared base of every `/admin/my/*` screen (phase-07 §7.7, §9).
 *
 * **No id from the request ever reaches a `where`.** Each screen resolves `auth()->user()->employee` and
 * queries only through its relations. That is the whole isolation model here: there is no way to ask for
 * somebody else's row, because there is no place to put their id.
 *
 * A user with **no employee record gets a 404**, not a 403. A 403 would confirm that the self-service
 * area exists and that they simply are not staff, which is more than the page needs to say.
 *
 * Route-model binding on a slip, a leave request or a document is checked against the resolved employee
 * and answers 404 for anything else — again, so ids cannot be probed.
 */
abstract class SelfServiceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected readonly EmployeeScopeResolver $scopes) {}

    /**
     * The employee behind the signed-in user, or a 404.
     */
    protected function employee(Request $request): Employee
    {
        $employee = $this->scopes->selfFor($request->user());

        abort_if($employee === null, 404);

        return $employee;
    }

    /**
     * Does this row belong to the signed-in employee? Anything else is a 404.
     */
    protected function assertOwn(Employee $employee, ?int $employeeId): void
    {
        abort_if($employeeId === null || (int) $employeeId !== (int) $employee->getKey(), 404);
    }
}
