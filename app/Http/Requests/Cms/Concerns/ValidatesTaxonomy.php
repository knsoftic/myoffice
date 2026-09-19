<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Http\Requests\Cms\TaxonomyDefinition;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * A term of one of the five taxonomies (phase-04 §6.2, §6.11 `StoreTaxonomyRequest` /
 * `UpdateTaxonomyRequest`, §8.1).
 *
 *   · `name` is required and unique within its own list, compared case-insensitively (the column
 *     collation is case-insensitive, and the check lower-cases as well);
 *   · `slug` follows §6.1 (`ValidatesContentSlug`);
 *   · `color` is a `#rrggbb` hex (technologies only);
 *   · a field the list does not have is **prohibited**, not silently dropped, so a form that posts the
 *     wrong shape is told so;
 *   · the three category lists merge `SeoService::rules()` under `seo.*` (D23).
 *
 * The using class must extend `CmsFormRequest` and use `ResolvesContentModule`, `ValidatesContentSlug`,
 * `ValidatesContentImage`, `DelegatesSeoRules` and `NormalisesContentInput`.
 */
trait ValidatesTaxonomy
{
    /**
     * The term being edited (null on create).
     */
    abstract public function term(): ?Model;

    /**
     * @return array<string, mixed>|null
     */
    public function taxonomy(): ?array
    {
        return TaxonomyDefinition::for($this->contentModule());
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function taxonomyRules(bool $partial): array
    {
        $definition = $this->taxonomy();

        if ($definition === null) {
            // No taxonomy route matched: authorize() has already refused; refuse every field too.
            return ['name' => ['prohibited']];
        }

        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $ignore = $this->term()?->getKey();
        $ignore = is_numeric($ignore) ? (int) $ignore : null;

        $rules = [
            'name' => array_merge($required, ['bail', 'string', 'max:'.$definition['name_max'], $this->nameIsUnique($definition['table'], $ignore)]),
            'slug' => $this->slugRules($definition['table'], $ignore),
            'is_active' => ['sometimes', 'boolean'],
            'description' => $definition['description'] ? ['sometimes', 'nullable', 'string', 'max:5000'] : ['prohibited'],
            'icon' => $definition['icon'] ? ['sometimes', 'bail', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/'] : ['prohibited'],
            'color' => $definition['color'] ? ['sometimes', 'bail', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'] : ['prohibited'],
            'sort_order' => $definition['sortable'] ? ['sometimes', 'bail', 'nullable', 'integer', 'min:0', 'max:2147483647'] : ['prohibited'],
            'seo' => $definition['seo'] ? ['sometimes', 'array'] : ['prohibited'],
        ];

        if ($definition['image_field'] !== null) {
            $rules = array_merge($rules, $this->imageRules($definition['image_field']));
        } else {
            $rules['image'] = ['prohibited'];
            $rules['image_media_id'] = ['prohibited'];
        }

        if ($definition['seo']) {
            $rules = array_merge($rules, $this->seoRules());
        }

        return $rules;
    }

    protected function taxonomyAfter(Validator $validator): void
    {
        if (($this->taxonomy()['seo'] ?? false) === true) {
            $this->seoAfter($validator);
        }
    }

    protected function prepareTaxonomyInput(): void
    {
        $this->trimInputs(['name', 'description', 'icon', 'color']);
        $this->normaliseSlugInput();
    }

    /**
     * The term columns for `TaxonomyService` (the image upload and the SEO block travel separately).
     *
     * @return array<string, mixed>
     */
    public function termPayload(): array
    {
        $definition = $this->taxonomy() ?? [];
        $field = $definition['image_field'] ?? null;

        $data = $this->safe()->except([
            'seo', 'image', 'image_media_id', 'remove_image', 'logo', 'logo_media_id', 'remove_logo',
        ]);

        if (array_key_exists('sort_order', $data) && $data['sort_order'] !== null) {
            $data['sort_order'] = (int) $data['sort_order'];
        }

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = $this->boolean('is_active');
        }

        if (is_string($field) && is_string($definition['image_column'] ?? null)) {
            $data = array_merge($data, $this->imageColumnPayload($definition['image_column'], $field));
        }

        return $data;
    }

    /**
     * True when the request only flips `is_active` — the list's inline active toggle (§8.1), which
     * goes to `TaxonomyService::toggleActive()` rather than a full update.
     */
    public function isActiveToggleOnly(): bool
    {
        $keys = array_keys($this->except(['_token', '_method']));

        return $keys === ['is_active'];
    }

    public function termImage(): ?UploadedFile
    {
        $field = $this->taxonomy()['image_field'] ?? null;

        return is_string($field) ? $this->uploadedImage($field) : null;
    }

    private function nameIsUnique(string $table, ?int $ignoreId): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($table, $ignoreId): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $taken = DB::table($table)
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($value))])
                ->when($ignoreId !== null, static fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists();

            if ($taken) {
                $fail('An entry with this name already exists.');
            }
        };
    }
}
