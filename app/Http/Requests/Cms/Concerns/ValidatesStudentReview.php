<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Models\Cms\StudentReview;

/**
 * A student review entered by staff (phase-04 §2.11, §6.5, §6.11 `StoreStudentReviewRequest` /
 * `UpdateStudentReviewRequest`, §8.5).
 *
 * The testimonial rules (rating 1-5 or null, review required ≤ 2000, moderation columns prohibited) plus
 * the `video_url` host allowlist. `student_id` and `course_id` are deferred links (§2.1) prohibited
 * until Phase 15 / Phase 14 ship their tables; `student_name` and `course_name` are the snapshots the
 * site renders. No panel can write here in Phase 4 (§9.3, Q4).
 *
 * The using class must extend `CmsFormRequest` and implement `studentReview()`.
 */
trait ValidatesStudentReview
{
    abstract public function studentReview(): ?StudentReview;

    /**
     * @return array<string, list<mixed>>
     */
    protected function studentReviewRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return array_merge([
            'student_name' => array_merge($required, ['bail', 'string', 'max:150']),
            'student_id' => $this->deferredLinkRules('students'),
            'course_id' => $this->deferredLinkRules('courses'),
            'course_name' => ['sometimes', 'bail', 'nullable', 'string', 'max:150'],
            'rating' => ['sometimes', 'bail', 'nullable', 'integer', 'between:1,5'],
            'review' => array_merge($required, ['bail', 'string', 'max:2000']),
            'video_url' => $this->videoUrlRules(),
            'sort_order' => ['sometimes', 'bail', 'nullable', 'integer', 'min:0', 'max:2147483647'],

            'status' => ['prohibited'],
            'approved_by' => ['prohibited'],
            'approved_at' => ['prohibited'],
            'rejection_reason' => ['prohibited'],
            'is_featured' => ['prohibited'],
            'source' => ['prohibited'],
            'submitted_by_user_id' => ['prohibited'],
            'ip_address' => ['prohibited'],
        ], $this->imageRules('student_photo'));
    }

    protected function prepareStudentReviewInput(): void
    {
        $this->trimInputs(['student_name', 'course_name', 'review', 'video_url']);
    }

    /**
     * The `student_reviews` columns for `StudentReviewService` (the upload travels separately).
     *
     * @return array<string, mixed>
     */
    public function studentReviewPayload(): array
    {
        $data = $this->safe()->except(['student_photo', 'student_photo_media_id', 'remove_student_photo']);

        return array_merge($data, $this->imageColumnPayload('student_photo_media_id', 'student_photo'));
    }
}
