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
 * Correct a student review — `admin.student-reviews.update`, `can:student_reviews.edit` (phase-04 §6.5
 * invariant 4: editing the text is audited with old and new values and never changes moderation).
 */
final class UpdateStudentReviewRequest extends CmsFormRequest
{
    use NormalisesContentInput;
    use ValidatesContentImage;
    use ValidatesDeferredLinks;
    use ValidatesStudentReview;
    use ValidatesVideoUrl;

    protected function permission(): string
    {
        return 'student_reviews.edit';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->studentReviewRules(partial: true);
    }

    protected function prepareForValidation(): void
    {
        $this->prepareStudentReviewInput();
    }

    public function studentReview(): ?StudentReview
    {
        $review = $this->route('review');

        return $review instanceof StudentReview ? $review : null;
    }
}
