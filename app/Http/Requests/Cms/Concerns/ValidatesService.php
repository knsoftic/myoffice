<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Enums\Cms\ContentStatus;
use App\Models\Cms\Service;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A catalogue service (phase-04 §2.3, §6.4, §6.11 `StoreServiceRequest` / `UpdateServiceRequest`, §8.2).
 *
 *   · `starting_price` is a decimal string — `nullable, decimal:0,2, min:0, max:9999999999999.99` —
 *     handed to the service as the string it arrived as (never a float, CLAUDE.md rule 4);
 *   · `features` is an ordered list of at most 20 strings of at most 150 characters;
 *   · `technology_ids.*` must exist;
 *   · the image slot follows §6.6 (`ValidatesContentImage`), the SEO block §6.11 ND-13;
 *   · `status` and `is_featured` are validated here but never written by the save itself: the
 *     controller demands `services.change_status` before acting on a change and routes it through
 *     `changeStatus()` / `toggleFeatured()`, so an `edit` holder cannot publish by saving a form.
 *
 * The using class must extend `CmsFormRequest` and implement `service()`.
 */
trait ValidatesService
{
    abstract public function service(): ?Service;

    /**
     * @return array<string, list<mixed>>
     */
    protected function serviceRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $id = $this->service()?->getKey();

        return array_merge([
            'name' => array_merge($required, ['bail', 'string', 'max:150']),
            'slug' => $this->slugRules('services', is_numeric($id) ? (int) $id : null),
            'service_category_id' => ['sometimes', 'bail', 'nullable', 'integer', 'min:1', Rule::exists('service_categories', 'id')->whereNull('deleted_at')],
            'short_description' => ['sometimes', 'bail', 'nullable', 'string', 'max:500'],
            'full_description' => ['sometimes', 'bail', 'nullable', 'string', 'max:200000'],
            'icon' => ['sometimes', 'bail', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'starting_price' => ['sometimes', 'bail', 'nullable', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'price_note' => ['sometimes', 'bail', 'nullable', 'string', 'max:100'],
            'price_visible' => ['sometimes', 'boolean'],
            'features' => ['sometimes', 'bail', 'nullable', 'array', 'max:20'],
            'features.*' => ['bail', 'string', 'max:150'],
            'technology_ids' => ['sometimes', 'bail', 'nullable', 'array', 'max:50'],
            'technology_ids.*' => ['bail', 'integer', 'min:1', 'distinct', Rule::exists('technologies', 'id')->whereNull('deleted_at')],
            'sort_order' => ['sometimes', 'bail', 'nullable', 'integer', 'min:0', 'max:2147483647'],
            // Offered by the form to `services.change_status` holders only; the controller demands that
            // ability before acting on a change and routes it through changeStatus() / toggleFeatured().
            'status' => ['sometimes', 'bail', 'string', Rule::in([
                ContentStatus::Draft->value,
                ContentStatus::Published->value,
                ContentStatus::Archived->value,
            ])],
            'is_featured' => ['sometimes', 'boolean'],
        ], $this->imageRules('image'), $this->seoRules());
    }

    protected function prepareServiceInput(): void
    {
        $this->trimInputs(['name', 'short_description', 'full_description', 'icon', 'price_note']);
        $this->normaliseSlugInput();
        $this->normaliseAmounts(['starting_price']);
        $this->cleanStringLists(['features']);
    }

    protected function serviceAfter(Validator $validator): void
    {
        $this->seoAfter($validator);
    }

    /**
     * The `services` columns for `ServiceContentService` — no SEO, no technologies, no upload.
     *
     * @return array<string, mixed>
     */
    public function servicePayload(): array
    {
        $data = $this->safe()->except(['seo', 'image', 'image_media_id', 'remove_image', 'technology_ids', 'status', 'is_featured']);

        if (array_key_exists('price_visible', $data)) {
            $data['price_visible'] = $this->boolean('price_visible');
        }

        if (array_key_exists('features', $data)) {
            $features = $this->validatedStrings('features');
            $data['features'] = $features === [] ? null : $features;
        }

        if (array_key_exists('starting_price', $data) && $data['starting_price'] !== null) {
            $data['starting_price'] = (string) $data['starting_price'];
        }

        return array_merge($data, $this->imageColumnPayload('image_media_id'));
    }

    /**
     * The technology ids in chip order. Only meaningful when `hasTechnologies()` is true — an update
     * that did not post the field must leave the pivot alone.
     *
     * @return list<int>
     */
    public function technologyIds(): array
    {
        return $this->validatedIds('technology_ids');
    }

    public function hasTechnologies(): bool
    {
        return $this->has('technology_ids');
    }

    public function requestedStatus(): ?ContentStatus
    {
        $status = $this->validated('status');

        return is_string($status) ? ContentStatus::tryFrom($status) : null;
    }

    public function requestedFeatured(): ?bool
    {
        return array_key_exists('is_featured', $this->validated()) ? $this->boolean('is_featured') : null;
    }
}
