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
 * Update a success story — `admin.success-stories.update`, `can:success_stories.edit` (phase-04 §6.4,
 * §6.11, §8.6).
 */
final class UpdateSuccessStoryRequest extends CmsFormRequest
{
    use NormalisesContentInput;
    use ValidatesContentImage;
    use ValidatesDeferredLinks;
    use ValidatesSuccessStory;
    use ValidatesVideoUrl;

    protected function permission(): string
    {
        return 'success_stories.edit';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->successStoryRules(partial: true);
    }

    protected function prepareForValidation(): void
    {
        $this->prepareSuccessStoryInput();
    }

    public function successStory(): ?SuccessStory
    {
        $story = $this->route('story');

        return $story instanceof SuccessStory ? $story : null;
    }
}
