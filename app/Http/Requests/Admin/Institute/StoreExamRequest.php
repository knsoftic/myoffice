<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\Enums\DeliveryMode;
use App\Enums\ExamType;
use App\Models\Institute\Exam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Setting up an exam (phase-19-23 §8, requirement §81).
 *
 * **`passing_marks <= total_marks` is checked here, by the service, and by `chk_ex_pass`.** The
 * database guarantee is what makes it true; this is what makes a coordinator see a message on the
 * field instead of a constraint violation they cannot read.
 *
 * **Double-booking is not checked here.** A Form Request cannot ask about other tables without doing
 * the query twice — `ScheduleClashDetector` does it once in the service, against `class_sessions`,
 * `demo_classes` and other exams together (D47).
 *
 * **The four frozen columns are ordinary fields at this layer.** `total_marks`, `passing_marks`,
 * `grade_scale_id` and `scheduled_date` are refused by the **model** once results are published,
 * because `Gate::before` would wave a Super Admin past anything a request or a policy said.
 */
class StoreExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        $exam = $this->route('exam');

        return $exam instanceof Exam
            ? $this->user()?->can('update', $exam) === true
            : $this->user()?->can('create', Exam::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $editing = $this->route('exam') instanceof Exam;

        return [
            // An exam belongs to one batch and stays with it: its results snapshot that roster.
            'batch_id' => [$editing ? 'nullable' : 'required', 'integer', Rule::exists('batches', 'id')->withoutTrashed()],
            'course_topic_id' => ['nullable', 'integer', Rule::exists('course_topics', 'id')->withoutTrashed()],
            'teacher_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')->withoutTrashed()],
            'classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')->withoutTrashed()],
            'grade_scale_id' => ['nullable', 'integer', Rule::exists('grade_scales', 'id')->withoutTrashed()],

            'exam_type' => [$editing ? 'nullable' : 'required', Rule::enum(ExamType::class)],
            // `sometimes` rather than `required` when editing, for the same reason every other field
            // here is conditional: this request also serves `reschedule`, which posts a date, a room
            // and a reason and nothing else. A flatly required `name` would have made every
            // reschedule a 422 — and the screen would have had to smuggle the current name through a
            // hidden input to get past its own validation, which is a form lying to itself.
            'name' => [$editing ? 'sometimes' : 'required', 'required', 'string', 'max:180'],

            'delivery_mode' => ['nullable', Rule::enum(DeliveryMode::class)],
            // `url` alone permits `javascript:` in some builds, so the scheme is pinned as well.
            'meeting_url' => ['nullable', 'string', 'max:500', 'url', 'regex:/^https?:\/\//i'],

            'scheduled_date' => [$editing ? 'nullable' : 'required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],

            // `decimal:0,2` rather than bare `numeric`: `numeric` accepts `1E1`, which no decimal(8,2)
            // column can parse. The same omission cost a settings field in Phase 18 (D114).
            'total_marks' => [$editing ? 'nullable' : 'required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999'],
            'passing_marks' => [$editing ? 'nullable' : 'required', 'numeric', 'decimal:0,2', 'min:0'],

            // A percentage, so decimal(8,4) — CLAUDE.md §3 has no "reported percentage" exception.
            'weight_percentage' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],

            'instructions' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:500'],

            // Required by the service on `cancel()` and `reschedule()`.
            'reason' => ['nullable', 'string', 'min:5', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $total = $this->input('total_marks');
            $passing = $this->input('passing_marks');

            if ($total !== null && $passing !== null && $passing !== ''
                && bccomp((string) $passing, (string) $total, 2) > 0) {
                $validator->errors()->add(
                    'passing_marks',
                    'The pass mark cannot be higher than what the paper is out of.',
                );
            }

            $mode = DeliveryMode::tryFrom((string) $this->input('delivery_mode', 'physical'));
            $url = trim((string) $this->input('meeting_url'));

            if ($mode instanceof DeliveryMode && $mode->needsMeetingUrl() && $url === '') {
                $validator->errors()->add('meeting_url', 'An online exam needs a joining link.');
            }

            if ($mode instanceof DeliveryMode && ! $mode->needsMeetingUrl() && $url !== '') {
                $validator->errors()->add('meeting_url', 'A '.$mode->label().' exam is not sat over a link.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'total_marks.gt' => 'An exam has to be worth something.',
            'total_marks.decimal' => 'Marks are a plain number like 100 or 12.50.',
            'weight_percentage.decimal' => 'A weight is a plain percentage like 30 or 12.5.',
            'end_time.after' => 'An exam cannot finish before it starts.',
            'meeting_url.regex' => 'A joining link has to start with http:// or https://.',
            'reason.min' => 'Say a little more about why.',
        ];
    }
}
