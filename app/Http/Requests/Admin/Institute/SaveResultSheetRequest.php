<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\Enums\ExamAttendanceStatus;
use App\Models\Institute\Exam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A whole sheet of marks, submitted at once (phase-19-23 §6.10, INV-20-6).
 *
 * **This layer stops the obvious and the service stops the rest.** A shape check here — every row has
 * a student, a valid attendance status, a numeric mark inside the ceiling — means a marker gets errors
 * against the right fields before anything touches a transaction. What it *cannot* check is whether
 * each student was on the roster on the exam's date, which needs a query the service already makes.
 *
 * **The ceiling is checked against the exam, not against the row's snapshot.** At this point the rows
 * may not exist yet, so there is no snapshot to compare with — and for a new sheet the two are the
 * same number anyway. `ExamResultService` re-checks against the snapshot, which is what matters when
 * a mark is later amended.
 *
 * **An absence is not a zero**, and this says so on the field: marks are required exactly when the
 * student appeared, and refused otherwise. `chk_er_appeared` and `chk_er_absent` say it again at the
 * database, where it cannot be argued with.
 */
class SaveResultSheetRequest extends FormRequest
{
    /**
     * **Two panels, two vocabularies for one operation.** The admin screen is governed by
     * `results.create`; the teacher panel by `teacher_portal.results_entry`. A teacher holds the
     * second and not the first, deliberately — the portal abilities exist precisely so somebody can
     * be given their own screens without being given the office's.
     *
     * Either is enough here, because the route that reached this request already required the right
     * one for its panel. This is the second net, not the first. **Ownership is not checked here at
     * all**: the teacher controller asks `TeacherScope` before the service is reached, which is what
     * makes another teacher's exam a 404 rather than a 403.
     */
    public function authorize(): bool
    {
        $exam = $this->route('exam');

        if (! $exam instanceof Exam) {
            return false;
        }

        $user = $this->user();

        return $user?->can('results.create') === true
            || $user?->can('teacher_portal.results_entry') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.student_id' => ['required', 'integer', Rule::exists('students', 'id')->withoutTrashed()],
            'rows.*.attendance_status' => ['required', Rule::enum(ExamAttendanceStatus::class)],
            // Nullable here because an absence carries none; the pairing rule is checked below.
            'rows.*.obtained_marks' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'rows.*.remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $exam = $this->route('exam');

            if (! $exam instanceof Exam) {
                return;
            }

            $total = (string) $exam->getAttribute('total_marks');
            $rows = $this->input('rows', []);

            if (! is_array($rows)) {
                return;
            }

            $seen = [];

            foreach ($rows as $index => $row) {
                $attendance = ExamAttendanceStatus::tryFrom((string) ($row['attendance_status'] ?? ''));
                $marks = $row['obtained_marks'] ?? null;
                $marks = $marks === '' ? null : $marks;

                // One student, one row. A sheet listing somebody twice would upsert the second over
                // the first and quietly lose a mark.
                $studentId = (int) ($row['student_id'] ?? 0);

                if (isset($seen[$studentId])) {
                    $validator->errors()->add(
                        "rows.$index.student_id",
                        'This student is already on the sheet — they cannot have two marks for one exam.',
                    );
                }

                $seen[$studentId] = true;

                if (! $attendance instanceof ExamAttendanceStatus) {
                    continue;
                }

                if ($attendance->requiresMarks() && $marks === null) {
                    $validator->errors()->add(
                        "rows.$index.obtained_marks",
                        'They appeared, so they have a mark. Use “absent” if they did not sit it.',
                    );

                    continue;
                }

                if (! $attendance->requiresMarks() && $marks !== null) {
                    $validator->errors()->add(
                        "rows.$index.obtained_marks",
                        sprintf('A student marked %s has no mark — an absence is not a zero.', mb_strtolower($attendance->label())),
                    );

                    continue;
                }

                if ($marks !== null && bccomp((string) $marks, $total, 2) > 0) {
                    $validator->errors()->add(
                        "rows.$index.obtained_marks",
                        sprintf('This paper is out of %s, so %s is not a possible mark.', $total, (string) $marks),
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.required' => 'There is nothing to save.',
            'rows.*.obtained_marks.decimal' => 'A mark is a plain number like 38 or 38.50.',
            'rows.*.student_id.exists' => 'One of these students no longer exists.',
        ];
    }
}
