<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\DelegatesSeoRules;
use App\Http\Requests\Cms\Concerns\NormalisesContentInput;
use App\Http\Requests\Cms\Concerns\ValidatesContentSlug;
use App\Http\Requests\Cms\Concerns\ValidatesDeferredLinks;
use App\Http\Requests\Cms\Concerns\ValidatesJobOpening;
use App\Models\Cms\JobOpening;
use Illuminate\Validation\Validator;

/**
 * Update a job — `admin.jobs.update`, `can:jobs.edit` (phase-04 §6.8, §6.11, §8.8).
 */
final class UpdateJobOpeningRequest extends CmsFormRequest
{
    use DelegatesSeoRules;
    use NormalisesContentInput;
    use ValidatesContentSlug;
    use ValidatesDeferredLinks;
    use ValidatesJobOpening;

    protected function permission(): string
    {
        return 'jobs.edit';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->jobOpeningRules(partial: true);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->jobOpeningAfter($validator, partial: true)];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareJobOpeningInput();
    }

    public function jobOpening(): ?JobOpening
    {
        $job = $this->route('job');

        return $job instanceof JobOpening ? $job : null;
    }
}
