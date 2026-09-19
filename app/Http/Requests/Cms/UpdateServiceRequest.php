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
 * Update a service — `admin.services.update`, `can:services.edit` (phase-04 §6.11, §8.2). A slug change
 * on a service that has ever been published is logged by the service as `slug_changed` (§6.1).
 */
final class UpdateServiceRequest extends CmsFormRequest
{
    use DelegatesSeoRules;
    use NormalisesContentInput;
    use ValidatesContentImage;
    use ValidatesContentSlug;
    use ValidatesService;

    protected function permission(): string
    {
        return 'services.edit';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->serviceRules(partial: true);
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
        $service = $this->route('service');

        return $service instanceof Service ? $service : null;
    }
}
