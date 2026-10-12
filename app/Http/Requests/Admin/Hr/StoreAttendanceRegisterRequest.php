<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Hr;

use App\Services\Hr\AttendanceRegisterService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The employee register saved in one go (`AttendanceRegisterController::store`).
 *
 * Shape only: which employees this user may mark, and whether payroll has locked any of them, is decided
 * by `AttendanceRegisterService::save()` against the rows themselves. A blank status leaves that employee
 * alone. One reason covers the save — each changed day is a correction, and a correction says why.
 */
final class StoreAttendanceRegisterRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'department_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'marks' => ['required', 'array', 'max:2000'],
            'marks.*.status' => ['nullable', Rule::in(array_keys(AttendanceRegisterService::MARKS))],
            'marks.*.check_in' => ['nullable', 'required_with:marks.*.check_out', 'date_format:H:i'],
            'marks.*.check_out' => ['nullable', 'date_format:H:i', 'after:marks.*.check_in'],
            'marks.*.remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.before_or_equal' => 'Attendance is recorded for today or an earlier day, not ahead of time.',
            'marks.*.check_out.after' => 'Check-out has to be after check-in.',
            'marks.*.check_in.required_with' => 'Give the check-in as well as the check-out.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'marks.*.status' => 'mark',
            'marks.*.check_in' => 'check-in',
            'marks.*.check_out' => 'check-out',
            'marks.*.remarks' => 'note',
        ];
    }
}
