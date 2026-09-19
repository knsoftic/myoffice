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
 * Update a taxonomy term — `admin.{service-categories,portfolio-categories,blog-categories,blog-tags,
 * technologies}.update`, `can:{module}.edit` (phase-04 §6.11, §8.1).
 *
 * Every field is `sometimes`: the inline active toggle of the list posts `is_active` alone and the
 * controller hands that to `TaxonomyService::toggleActive()`.
 */
final class UpdateTaxonomyRequest extends CmsFormRequest
{
    use DelegatesSeoRules;
    use NormalisesContentInput;
    use ResolvesContentModule;
    use ValidatesContentImage;
    use ValidatesContentSlug;
    use ValidatesTaxonomy;

    private ?Model $resolvedTerm = null;

    private bool $termResolved = false;

    protected function permission(): ?string
    {
        return $this->taxonomy() === null ? null : $this->contentPermission('edit');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->taxonomyRules(partial: true);
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

    /**
     * The `{term}` route parameter resolved against the matched list (non-trashed only).
     */
    public function term(): ?Model
    {
        if ($this->termResolved) {
            return $this->resolvedTerm;
        }

        $this->termResolved = true;
        $definition = $this->taxonomy();
        $id = $this->route('term');

        if ($definition === null || ! (is_int($id) || (is_string($id) && ctype_digit($id)))) {
            return null;
        }

        /** @var class-string<Model> $model */
        $model = $definition['model'];

        return $this->resolvedTerm = $model::query()->find((int) $id);
    }
}
