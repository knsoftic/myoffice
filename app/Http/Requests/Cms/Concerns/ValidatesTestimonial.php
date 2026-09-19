<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Enums\TestimonialType;
use App\Models\Cms\Testimonial;
use Illuminate\Validation\Rule;

/**
 * A client / student / other testimonial entered by staff (phase-04 §2.10, §6.5, §6.11
 * `StoreTestimonialRequest` / `UpdateTestimonialRequest`, §8.5, acceptance test 18).
 *
 *   · `type` is a `TestimonialType`; `author_company` is required for a client, `course_name` for a
 *     student;
 *   · `rating` is `nullable, integer, between:1,5` — `0` and `6` are refused, null renders no stars;
 *   · `review` is required, at most 2000 characters (tags are stripped by the service on write);
 *   · moderation columns (`status`, `approved_*`, `rejection_reason`), `is_featured`, `source` and the
 *     submitter/IP columns are **prohibited**: approval is `ModerationService`'s alone and a staff form
 *     can never claim a public or panel source.
 *
 * The using class must extend `CmsFormRequest` and implement `testimonial()`.
 */
trait ValidatesTestimonial
{
    abstract public function testimonial(): ?Testimonial;

    /**
     * @return array<string, list<mixed>>
     */
    protected function testimonialRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $type = $this->input('type', $this->testimonial()?->type);
        $type = $type instanceof TestimonialType ? $type->value : $type;

        return array_merge([
            'type' => array_merge($required, ['bail', 'string', Rule::enum(TestimonialType::class)]),
            'author_name' => array_merge($required, ['bail', 'string', 'max:150']),
            'author_designation' => ['sometimes', 'bail', 'nullable', 'string', 'max:150'],
            'author_company' => $type === TestimonialType::Client->value
                ? $this->requiredOnChange('author_company', ['bail', 'string', 'max:150'], $partial)
                : ['sometimes', 'bail', 'nullable', 'string', 'max:150'],
            'course_name' => $type === TestimonialType::Student->value
                ? $this->requiredOnChange('course_name', ['bail', 'string', 'max:150'], $partial)
                : ['sometimes', 'bail', 'nullable', 'string', 'max:150'],
            'rating' => ['sometimes', 'bail', 'nullable', 'integer', 'between:1,5'],
            'review' => array_merge($required, ['bail', 'string', 'max:2000']),
            'review_date' => ['sometimes', 'bail', 'nullable', 'string', 'max:40', 'date'],
            'client_id' => $this->deferredLinkRules('clients'),
            'student_id' => $this->deferredLinkRules('students'),
            'sort_order' => ['sometimes', 'bail', 'nullable', 'integer', 'min:0', 'max:2147483647'],

            'status' => ['prohibited'],
            'approved_by' => ['prohibited'],
            'approved_at' => ['prohibited'],
            'rejection_reason' => ['prohibited'],
            'is_featured' => ['prohibited'],
            'source' => ['prohibited'],
            'submitted_by_user_id' => ['prohibited'],
            'ip_address' => ['prohibited'],
        ], $this->imageRules('author_photo'));
    }

    protected function prepareTestimonialInput(): void
    {
        $this->trimInputs(['type', 'author_name', 'author_designation', 'author_company', 'course_name', 'review']);
    }

    /**
     * The `testimonials` columns for `TestimonialService` (the upload travels separately).
     *
     * @return array<string, mixed>
     */
    public function testimonialPayload(): array
    {
        $data = $this->safe()->except(['author_photo', 'author_photo_media_id', 'remove_author_photo']);

        return array_merge($data, $this->imageColumnPayload('author_photo_media_id', 'author_photo'));
    }

    /**
     * Required on create; on update required only when the type-dependent field is posted or the type
     * itself changes (a partial update of the rating must not demand the company again).
     *
     * @param  list<mixed>  $rules
     * @return list<mixed>
     */
    private function requiredOnChange(string $field, array $rules, bool $partial): array
    {
        if (! $partial) {
            return array_merge(['required'], $rules);
        }

        $typeChanges = $this->has('type');

        return array_merge($typeChanges ? ['required'] : ['sometimes', 'required'], $rules);
    }
}
