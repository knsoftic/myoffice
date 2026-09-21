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
        return array_merge(parent::rules(), [
            'slug_change_reason' => ['nullable', 'string', 'min:5', 'max:255'],
        ]);
    }
}
