<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\DelegatesSeoRules;
use App\Http\Requests\Cms\Concerns\NormalisesContentInput;
use App\Http\Requests\Cms\Concerns\ValidatesContentImage;
use App\Http\Requests\Cms\Concerns\ValidatesContentSlug;
use App\Http\Requests\Cms\Concerns\ValidatesService;
use App\Models\Cms\Service;
use Illuminate\Validation\Validator;

/**
 * Create a service — `admin.services.store`, `can:services.create` (phase-04 §6.11, §8.2). A new
 * service is a draft until `admin.services.status` publishes it.
 */
final class StoreServiceRequest extends CmsFormRequest
{
    use DelegatesSeoRules;
    use NormalisesContentInput;
    use ValidatesContentImage;
    use ValidatesContentSlug;
    use ValidatesService;

    protected function permission(): string
    {
        return 'services.create';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->serviceRules(partial: false);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->serviceAfter($validator)];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareServiceInput();
    }

    public function service(): ?Service
    {
        return null;
    }
}
