<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Hr;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\AttendanceDeductionStatus;
use App\Models\Hr\AttendanceDeduction;
use App\Services\Hr\EmployeeScopeResolver;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The signed-in employee's own attendance deductions — what payroll has still to take, and the latest notices.
 *
 * Persistent by construction: it reads the `attendance_deductions` rows, not the bell, so every notice is
 * still here after a sign-out, and it can only ever show the viewer's own rows (resolved from the session
 * user, never from a request id).
 */
final class MySalaryDeductionsWidget extends Widget
{
    public function key(): string
    {
        return 'my_salary_deductions';
    }

    public function title(): string
    {
        return 'My salary deductions';
    }

    public function icon(): string
    {
        return 'receipt-percent';
    }

    public function permission(): ?string
    {
        return 'employee_self_service.view_financial';
    }

    public function module(): ?string
    {
        return 'employee_self_service';
    }

    public function group(): string
    {
        return WidgetGroup::OPERATIONS;
    }

    public function sort(): int
    {
        return 35;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.my.deductions.index');
    }

    public function emptyMessage(): ?string
    {
        return 'No salary deductions.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $employee = app(EmployeeScopeResolver::class)->selfFor(Auth::user());

        if ($employee === null) {
            return ['available' => true, 'mine' => false, 'pending_total' => Money::zero(), 'pending_count' => 0, 'recent' => []];
        }

        try {
            $pending = AttendanceDeduction::query()
                ->where('employee_id', $employee->getKey())
                ->where('status', AttendanceDeductionStatus::Pending->value)
                ->toBase()
                ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as total')
                ->first();

            $recent = AttendanceDeduction::query()
                ->where('employee_id', $employee->getKey())
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit(3)
                ->get()
                ->map(fn (AttendanceDeduction $row): array => [
                    'type' => $row->deduction_type->label(),
                    'type_color' => $row->deduction_type->color(),
                    'status' => $row->status->label(),
                    'status_color' => $row->status->color(),
                    'amount' => (string) $row->amount,
                    'text' => $row->explanation(),
                    'at' => $row->created_at,
                ])
                ->all();
        } catch (Throwable) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'mine' => true,
            'pending_total' => Money::round((string) ($pending->total ?? '0')),
            'pending_count' => (int) ($pending->n ?? 0),
            'recent' => $recent,
        ];
    }
}
