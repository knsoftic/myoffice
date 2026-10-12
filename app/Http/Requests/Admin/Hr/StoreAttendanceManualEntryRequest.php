<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Hr;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Record attendance" on the HR register (`AttendanceManualEntryController::store`).
 *
 * Shape only. Whether the employee is one this user may see, whether the day is inside the employment and
 * whether payroll has locked the month are decided by the controller against the rows themselves, because
 * each needs the employee loaded. Every field carries an `entry_` prefix so this form's old input and errors
 * never collide with the register's filter and punch forms (`date`, `employee_id`).
 */
final class StoreAttendanceManualEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route carries `can:attendance.create`, and the controller asks the Attendance policy again.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entry_employee_id' => ['required', 'integer'],
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'entry_status' => ['required', Rule::enum(AttendanceStatus::class)],
            'entry_payable_factor' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:1'],
            'entry_check_in' => ['nullable', 'required_with:entry_check_out', 'date_format:H:i'],
            'entry_check_out' => ['nullable', 'required_with:entry_check_in', 'date_format:H:i', 'after:entry_check_in'],
            'entry_reason' => ['required', 'string', 'min:10', 'max:1000'],
            'entry_remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entry_date.before_or_equal' => 'Attendance is recorded for today or an earlier day, not ahead of time.',
            'entry_check_out.after' => 'Check-out has to be after check-in.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'entry_employee_id' => 'employee',
            'entry_date' => 'date',
            'entry_status' => 'status',
            'entry_payable_factor' => 'payable share',
            'entry_check_in' => 'check-in',
            'entry_check_out' => 'check-out',
            'entry_reason' => 'reason',
            'entry_remarks' => 'remarks',
        ];
    }
}
