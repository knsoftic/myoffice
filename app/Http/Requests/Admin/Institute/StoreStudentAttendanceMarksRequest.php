<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\Enums\StudentAttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A register posted as JSON (`AttendanceApiController::store`): the class, by `session_id` or by
 * `batch_id` + `date`, and a list of `{student_id, status, check_in_time?, remarks?}`.
 *
 * Shape only. Whether each student is on that class's roster (INV-I9) is `AttendanceService::mark()`'s to
 * decide, and the class and batch are narrowed to the user's branch by the controller.
 */
final class StoreStudentAttendanceMarksRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route carries `can:student_attendance.create`.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'session_id' => ['required_without:batch_id', 'nullable', 'integer'],
            'batch_id' => ['required_without:session_id', 'nullable', 'integer'],
            'date' => ['required_with:batch_id', 'nullable', 'date_format:Y-m-d'],
            'marks' => ['required', 'array', 'min:1', 'max:500'],
            'marks.*.student_id' => ['required', 'integer', 'distinct'],
            'marks.*.status' => ['required', Rule::enum(StudentAttendanceStatus::class)],
            'marks.*.check_in_time' => ['nullable', 'date_format:H:i,H:i:s'],
            'marks.*.remarks' => ['nullable', 'string', 'max:255'],
        ];
    }
}
