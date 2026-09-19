<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\NormalisesContentInput;
use App\Http\Requests\Cms\Concerns\ValidatesContentImage;
use App\Http\Requests\Cms\Concerns\ValidatesDeferredLinks;
use App\Http\Requests\Cms\Concerns\ValidatesStudentReview;
use App\Http\Requests\Cms\Concerns\ValidatesVideoUrl;
use App\Models\Cms\StudentReview;

/**
 * Create a student review — `admin.student-reviews.store`, `can:student_reviews.create` (phase-04 §6.5,
 * §6.11, §8.5).
 */
final class StoreStudentReviewRequest extends CmsFormRequest
{
    use NormalisesContentInput;
    use ValidatesContentImage;
    use ValidatesDeferredLinks;
    use ValidatesStudentReview;
    use ValidatesVideoUrl;

    protected function permission(): string
    {
        return 'student_reviews.create';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->studentReviewRules(partial: false);
    }

    protected function prepareForValidation(): void
    {
        $this->prepareStudentReviewInput();
    }

    public function studentReview(): ?StudentReview
    {
        return null;
    }
}
