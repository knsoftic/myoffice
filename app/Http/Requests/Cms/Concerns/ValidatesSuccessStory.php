<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Enums\Cms\ContentStatus;
use App\Models\Cms\SuccessStory;
use Illuminate\Validation\Rule;

/**
 * A staff-authored success story (phase-04 §2.12, §6.4, §6.11 `StoreSuccessStoryRequest` /
 * `UpdateSuccessStoryRequest`, §8.6).
 *
 *   · `story` is required, at most 20000 characters (sanitised rich text, `RichText::sanitize()` on
 *     write by the service);
 *   · `video_url` is host-allowlisted;
 *   · `student_id` / `course_id` are deferred links (§2.1);
 *   · `status` and `is_featured` are on the *Publishing* tab (§8.6); the controller demands
 *     `success_stories.change_status` before either changes and routes them through
 *     `SuccessStoryService::changeStatus()` / `toggleFeatured()`.
 *
 * The using class must extend `CmsFormRequest` and implement `successStory()`.
 */
trait ValidatesSuccessStory
{
    abstract public function successStory(): ?SuccessStory;

    /**
     * @return array<string, list<mixed>>
     */
    protected function successStoryRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return array_merge([
            'student_name' => array_merge($required, ['bail', 'string', 'max:150']),
            'student_id' => $this->deferredLinkRules('students'),
            'course_id' => $this->deferredLinkRules('courses'),
            'course_name' => ['sometimes', 'bail', 'nullable', 'string', 'max:150'],
            'headline' => ['sometimes', 'bail', 'nullable', 'string', 'max:180'],
            'story' => array_merge($required, ['bail', 'string', 'max:20000']),
            'achievement' => ['sometimes', 'bail', 'nullable', 'string', 'max:255'],
            'company_name' => ['sometimes', 'bail', 'nullable', 'string', 'max:150'],
            'platform' => ['sometimes', 'bail', 'nullable', 'string', 'max:100'],
            'video_url' => $this->videoUrlRules(),
            'status' => ['sometimes', 'bail', 'string', Rule::in([
                ContentStatus::Draft->value,
                ContentStatus::Published->value,
                ContentStatus::Archived->value,
            ])],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'bail', 'nullable', 'integer', 'min:0', 'max:2147483647'],
        ], $this->imageRules('photo'));
    }

    protected function prepareSuccessStoryInput(): void
    {
        $this->trimInputs(['student_name', 'course_name', 'headline', 'story', 'achievement', 'company_name', 'platform', 'video_url']);
    }

    /**
     * The `success_stories` columns for `SuccessStoryService` — without the upload and the two fields
     * that have their own service calls.
     *
     * @return array<string, mixed>
     */
    public function successStoryPayload(): array
    {
        $data = $this->safe()->except(['photo', 'photo_media_id', 'remove_photo', 'status', 'is_featured']);

        return array_merge($data, $this->imageColumnPayload('photo_media_id', 'photo'));
    }

    public function requestedStatus(): ?ContentStatus
    {
        $status = $this->validated('status');

        return is_string($status) ? ContentStatus::tryFrom($status) : null;
    }

    public function requestedFeatured(): ?bool
    {
        return array_key_exists('is_featured', $this->validated()) ? $this->boolean('is_featured') : null;
    }
}
