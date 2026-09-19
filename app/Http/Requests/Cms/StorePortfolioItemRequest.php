<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\DelegatesSeoRules;
use App\Http\Requests\Cms\Concerns\NormalisesContentInput;
use App\Http\Requests\Cms\Concerns\ValidatesContentImage;
use App\Http\Requests\Cms\Concerns\ValidatesContentSlug;
use App\Http\Requests\Cms\Concerns\ValidatesDeferredLinks;
use App\Http\Requests\Cms\Concerns\ValidatesPortfolioItem;
use App\Models\Cms\PortfolioItem;
use Illuminate\Validation\Validator;

/**
 * Create a portfolio item — `admin.portfolio.store`, `can:portfolio.create` (phase-04 §6.3, §6.11,
 * §8.3). Images chosen on the create form are stored all-or-nothing by `PortfolioService::store()`.
 */
final class StorePortfolioItemRequest extends CmsFormRequest
{
    use DelegatesSeoRules;
    use NormalisesContentInput;
    use ValidatesContentImage;
    use ValidatesContentSlug;
    use ValidatesDeferredLinks;
    use ValidatesPortfolioItem;

    protected function permission(): string
    {
        return 'portfolio.create';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->portfolioRules(partial: false);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->portfolioAfter($validator)];
    }

    protected function prepareForValidation(): void
    {
        $this->preparePortfolioInput();
    }

    public function portfolioItem(): ?PortfolioItem
    {
        return null;
    }
}
