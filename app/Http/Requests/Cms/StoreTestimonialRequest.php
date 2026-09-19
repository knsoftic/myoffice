<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\NormalisesContentInput;
use App\Http\Requests\Cms\Concerns\ValidatesContentImage;
use App\Http\Requests\Cms\Concerns\ValidatesDeferredLinks;
use App\Http\Requests\Cms\Concerns\ValidatesTestimonial;
use App\Models\Cms\Testimonial;

/**
 * Create a testimonial — `admin.testimonials.store`, `can:testimonials.create` (phase-04 §6.5, §6.11,
 * §8.5). It is created `pending` unless `website.testimonial_auto_approve` is on (staff entries only —
 * decided by the service, never by a form field).
 */
final class StoreTestimonialRequest extends CmsFormRequest
{
    use NormalisesContentInput;
    use ValidatesContentImage;
    use ValidatesDeferredLinks;
    use ValidatesTestimonial;

    protected function permission(): string
    {
        return 'testimonials.create';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->testimonialRules(partial: false);
    }

    protected function prepareForValidation(): void
    {
        $this->prepareTestimonialInput();
    }

    public function testimonial(): ?Testimonial
    {
        return null;
    }
}
