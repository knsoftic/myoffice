<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

/**
 * Save the screening notes and rating of an application — `admin.job-applications.update`,
 * `can:job_applications.edit` plus `JobApplicationPolicy::update()` (phase-04 §6.8 `saveNotes()`, §7.2
 * "internal notes + rating only", §8.9 autosave-on-blur).
 *
 * Only these two fields: the applicant's own data and the CV are the candidate's submission and are
 * never edited by staff; the stage, the reviewer and the rejection reason have their own endpoints.
 */
final class UpdateJobApplicationRequest extends CmsFormRequest
{
    protected function permission(): string
    {
        return 'job_applications.edit';
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        // `sometimes`: the list's inline stars post `rating` alone, the notes box autosaves `internal_notes`
        // alone; a field that is not posted keeps its stored value.
        return [
            'internal_notes' => ['sometimes', 'bail', 'nullable', 'string', 'max:10000'],
            'rating' => ['sometimes', 'bail', 'nullable', 'integer', 'between:1,5'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['internal_notes']);
    }

    public function hasNotes(): bool
    {
        return array_key_exists('internal_notes', $this->validated());
    }

    public function hasRating(): bool
    {
        return array_key_exists('rating', $this->validated());
    }

    public function notes(): ?string
    {
        $notes = $this->validated('internal_notes');

        return is_string($notes) ? $notes : null;
    }

    public function rating(): ?int
    {
        $rating = $this->validated('rating');

        return is_numeric($rating) ? (int) $rating : null;
    }
}
