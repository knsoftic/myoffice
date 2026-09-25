<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\Enums\CourseLevel;
use App\Enums\DeliveryMode;
use App\Enums\DurationUnit;
use App\Models\Institute\Course;
use App\Support\SlugGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a course (§62, phase-14-17 §8.3).
 *
 * **The three fee columns are dropped unless the caller holds `courses.view_financial`** (§4.2, FT-10).
 * Hiding the Fees tab is the courtesy; removing the values from the validated payload is the control —
 * otherwise a coordinator could set a price with a crafted POST against a tab they cannot see.
 *
 * `status` and `published_at` are deliberately absent: a course is created as a draft and moves only
 * through `CourseService`'s transition table, so there is no field here that could skip it.
 */
class StoreCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Course::class) === true;
    }

    /**
     * A textarea posts one string; the rules below expect a list. Splitting here rather than loosening
     * the rule keeps "at most 40 items, each at most 255 characters" enforced on what was actually typed.
     */
    protected function prepareForValidation(): void
    {
        foreach (['requirements', 'outcomes'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $this->merge([
                    $field => array_values(array_filter(
                        array_map('trim', preg_split('/\r\n|\r|\n/', $value) ?: []),
                        static fn (string $line): bool => $line !== '',
                    )),
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'course_category_id' => ['required', 'integer', 'exists:course_categories,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],

            /*
            | **Nullable on create: blank means "generate one".** `CourseService::codeFor()` mints
            | `institute.course_code_prefix` + the next number when this arrives empty, so a new
            | course does not have to answer "what shall we call it?" before it can be saved at all.
            | A typed code still wins and is kept exactly as entered — `WEB-101` reads better on a
            | certificate than `CRS-0007`.
            |
            | `UpdateCourseRequest` puts `required` back. An existing course already has a code that
            | timetables, certificates and fee slips point at, and emptying the field there must be
            | a validation error the editor sees on the form, not a silent renumber.
            */
            'code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9\-_]+$/',
                Rule::unique('courses', 'code')->ignore($this->courseId())->withoutTrashed()],
            'name' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:200', 'regex:'.SlugGenerator::PATTERN,
                Rule::unique('courses', 'slug')->ignore($this->courseId())->withoutTrashed()],

            'short_description' => ['nullable', 'string', 'max:500'],
            'full_description' => ['nullable', 'string', 'max:65000'],

            'duration_value' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'duration_unit' => ['required', Rule::enum(DurationUnit::class)],
            'total_classes' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'class_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],

            'level' => ['required', Rule::enum(CourseLevel::class)],
            'delivery_mode' => ['required', Rule::enum(DeliveryMode::class)],
            'default_teacher_id' => ['nullable', 'integer'],

            // Either shape: a textarea of lines, or an array of repeatable rows. The service
            // normalises both into a flat list of trimmed, non-empty strings, so the form is free to
            // change its mind about which control it uses without the rules having to follow.
            'requirements' => ['nullable', 'array', 'max:40'],
            'requirements.*' => ['nullable', 'string', 'max:255'],
            'outcomes' => ['nullable', 'array', 'max:40'],
            'outcomes.*' => ['nullable', 'string', 'max:255'],

            'certificate_available' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'admission_open' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],

            'promo_video_url' => ['nullable', 'url', 'max:255'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'thumbnail_path' => ['nullable', 'string', 'max:255'],

            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'seo_keywords' => ['nullable', 'string', 'max:255'],
            'og_image_path' => ['nullable', 'string', 'max:255'],
            'canonical_url' => ['nullable', 'url', 'max:255'],
            'is_indexable' => ['nullable', 'boolean'],

            'notes' => ['nullable', 'string', 'max:5000'],

            // Present in the rules so a holder's values validate; stripped in validated() for anybody
            // who may not see them.
            'course_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'admission_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'registration_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'monthly_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'installment_available' => ['nullable', 'boolean'],
            'max_installments' => ['nullable', 'integer', 'min:0', 'max:36'],
            'installment_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'A course needs a code — it is what staff call it.',
            'code.unique' => 'Another course already uses that code. Two courses sharing one makes every reference to it a guess.',
            'slug.unique' => 'That web address already belongs to another course.',
            'slug.regex' => 'A web address is lowercase letters, numbers and hyphens.',
            'course_category_id.required' => 'Every course sits in a category — it is how the catalogue is navigated.',
        ];
    }

    /**
     * The payload the service is handed, with the money removed from anybody who may not see it.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        $data = parent::validated();

        if ($this->user()?->can('viewFinancial', Course::class) !== true) {
            foreach ([
                'course_fee', 'admission_fee', 'registration_fee', 'monthly_fee',
                'installment_available', 'max_installments', 'installment_note',
            ] as $field) {
                unset($data[$field]);
            }
        }

        return $data;
    }

    private function courseId(): ?int
    {
        $course = $this->route('course');

        return $course instanceof Course ? (int) $course->getKey() : null;
    }
}
