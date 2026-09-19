<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\NormalisesContentInput;
use App\Http\Requests\Cms\Concerns\ValidatesContentImage;
use App\Http\Requests\Cms\Concerns\ValidatesDeferredLinks;
use App\Http\Requests\Cms\Concerns\ValidatesTestimonial;
use App\Models\Cms\Testimonial;

/**
 * Correct a testimonial — `admin.testimonials.update`, `can:testimonials.edit` (phase-04 §4 "correct a
 * typo in a review — audited with old/new values, never silent", §10.5). Editing never changes the
 * moderation state.
 */
final class UpdateTestimonialRequest extends CmsFormRequest
{
    use NormalisesContentInput;
    use ValidatesContentImage;
    use ValidatesDeferredLinks;
    use ValidatesTestimonial;

    protected function permission(): string
    {
        return 'testimonials.edit';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->testimonialRules(partial: true);
    }

    protected function prepareForValidation(): void
    {
        $this->prepareTestimonialInput();
    }

    public function testimonial(): ?Testimonial
    {
        $id = $this->route('testimonial');

        if ($id instanceof Testimonial) {
            return $id;
        }

        return is_string($id) && ctype_digit($id) ? Testimonial::query()->find((int) $id) : null;
    }
}
