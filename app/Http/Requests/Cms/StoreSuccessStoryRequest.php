<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\NormalisesContentInput;
use App\Http\Requests\Cms\Concerns\ValidatesContentImage;
use App\Http\Requests\Cms\Concerns\ValidatesDeferredLinks;
use App\Http\Requests\Cms\Concerns\ValidatesSuccessStory;
use App\Http\Requests\Cms\Concerns\ValidatesVideoUrl;
use App\Models\Cms\SuccessStory;

/**
 * Create a success story — `admin.success-stories.store`, `can:success_stories.create` (phase-04 §6.4,
 * §6.11, §8.6).
 */
final class StoreSuccessStoryRequest extends CmsFormRequest
{
    use NormalisesContentInput;
    use ValidatesContentImage;
    use ValidatesDeferredLinks;
    use ValidatesSuccessStory;
    use ValidatesVideoUrl;

    protected function permission(): string
    {
        return 'success_stories.create';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->successStoryRules(partial: false);
    }

    protected function prepareForValidation(): void
    {
        $this->prepareSuccessStoryInput();
    }

    public function successStory(): ?SuccessStory
    {
        return null;
    }
}
