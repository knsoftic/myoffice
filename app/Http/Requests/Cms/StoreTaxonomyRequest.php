<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\DelegatesSeoRules;
use App\Http\Requests\Cms\Concerns\NormalisesContentInput;
use App\Http\Requests\Cms\Concerns\ResolvesContentModule;
use App\Http\Requests\Cms\Concerns\ValidatesContentImage;
use App\Http\Requests\Cms\Concerns\ValidatesContentSlug;
use App\Http\Requests\Cms\Concerns\ValidatesTaxonomy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Validator;

/**
 * Create a term in one of the five taxonomies (phase-04 §6.11):
 *
 *   `admin.service-categories.store`   `can:service_categories.create`
 *   `admin.portfolio-categories.store` `can:portfolio_categories.create`
 *   `admin.blog-categories.store`      `can:blog_categories.create`
 *   `admin.blog-tags.store`            `can:blog_tags.create`
 *   `admin.technologies.store`         `can:technologies.create`
 *
 * The list, and therefore the permission and the field set, follow the matched route name.
 */
final class StoreTaxonomyRequest extends CmsFormRequest
{
    use DelegatesSeoRules;
    use NormalisesContentInput;
    use ResolvesContentModule;
    use ValidatesContentImage;
    use ValidatesContentSlug;
    use ValidatesTaxonomy;

    protected function permission(): ?string
    {
        return $this->taxonomy() === null ? null : $this->contentPermission('create');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->taxonomyRules(partial: false);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->taxonomyAfter($validator)];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareTaxonomyInput();
    }

    public function term(): ?Model
    {
        return null;
    }
}
