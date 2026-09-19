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
 * Post a job — `admin.jobs.store`, `can:jobs.create` (phase-04 §6.8, §6.11, §8.8).
 */
final class StoreJobOpeningRequest extends CmsFormRequest
{
    use DelegatesSeoRules;
    use NormalisesContentInput;
    use ValidatesContentSlug;
    use ValidatesDeferredLinks;
    use ValidatesJobOpening;

    protected function permission(): string
    {
        return 'jobs.create';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->jobOpeningRules(partial: false);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->jobOpeningAfter($validator, partial: false)];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareJobOpeningInput();
    }

    public function jobOpening(): ?JobOpening
    {
        return null;
    }
}
