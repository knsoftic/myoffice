<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Record attendance" on the student attendance screen (`AttendanceRecordController::store`): a batch and a
 * date, plus the times of an extra class should the batch have none that day.
 *
 * Shape only — the batch's branch, its start date and which class the day holds are the controller's to
 * decide. Fields carry a `record_` prefix so their old input never fills the screen's filter form, which
 * uses `batch_id` and `date`.
 */
final class RecordStudentAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route carries `can:student_attendance.create`; adding a class is checked in the controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'record_batch_id' => ['required', 'integer'],
            'record_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'record_start_time' => ['nullable', 'required_with:record_end_time', 'date_format:H:i,H:i:s'],
            'record_end_time' => ['nullable', 'required_with:record_start_time', 'date_format:H:i,H:i:s', 'after:record_start_time'],
            'record_title' => ['nullable', 'string', 'max:180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'record_date.before_or_equal' => 'Attendance is recorded for today or an earlier day, not ahead of time.',
            'record_end_time.after' => 'The class has to end after it starts.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'record_batch_id' => 'batch',
            'record_date' => 'date',
            'record_start_time' => 'start time',
            'record_end_time' => 'end time',
            'record_title' => 'note',
        ];
    }
}
