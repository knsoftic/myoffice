<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\Models\Institute\Course;

/**
 * Editing a course (phase-14-17 §6.2, §8.3).
 *
 * The same field set as {@see StoreCourseRequest} — including its `view_financial` strip — plus the one
 * field that only exists on an edit: the reason for moving a published slug. `/courses/{slug}` may be
 * in a WhatsApp forward or a printed flyer by then, and the reason is what the activity row carries so
 * the broken links have an explanation attached to them.
 */
final class UpdateCourseRequest extends StoreCourseRequest
{
    public function authorize(): bool
    {
        $course = $this->route('course');

        return $course instanceof Course && $this->user()?->can('update', $course) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        /*
        | **`code` is required again here, and the parent deliberately is not.**
        |
        | Blank on create means "generate one". Blank on *update* would mean renumbering a course
        | that timetables, certificates, fee slips and every printed reference already point at —
        | so it is refused, and refused here rather than deeper down, because the editor should see
        | it against the field they emptied instead of as an error about a service they have never
        | heard of. `CourseService::codeFor()` refuses it too; this is the message, that is the floor.
        */
        $rules['code'] = array_merge(['required'], array_values(array_filter(
            (array) $rules['code'],
            static fn (mixed $rule): bool => $rule !== 'nullable',
        )));

        return array_merge($rules, [
            'slug_change_reason' => ['nullable', 'string', 'min:5', 'max:255'],
        ]);
    }
}
