<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\CtaVariant;
use App\Enums\Cms\MediaCollection;
use App\Enums\Cms\MediaProcessingStatus;
use App\Enums\Cms\PageLayout;
use App\Enums\Cms\RobotsDirective;
use App\Enums\Cms\StatisticMetric;
use App\Enums\Cms\StatisticValueMode;
use BackedEnum;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query-string validation for every phase-03 admin list screen (§8.4-§8.13): sections, statistics,
 * menus, pages, CTA blocks, FAQs, FAQ categories, SEO, sitemap history and the media library.
 *
 * The same reasoning as Phase 1's `ListFilterRequest`: a filter read straight off the request turns
 * `?search[]=x` into a TypeError and a 500. Declaring the shape once makes every such probe a 422 and
 * leaves the controllers reading typed values. A filter a screen does not use is simply never asked for.
 *
 * Authorization is the route's `can:` plus the controller's `authorize()` — one request class cannot
 * name one permission for ten screens.
 */
final class CmsListRequest extends FormRequest
{
    private const FILTERS = [
        'search', 'status', 'layout', 'system', 'unpublished', 'missing_seo', 'trashed', 'enabled',
        'variant', 'unused', 'collection', 'type', 'derivatives', 'category', 'featured', 'attached',
        'robots', 'gap', 'mode', 'metric', 'page_id', 'sort', 'direction', 'page',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(array_merge(ContentStatus::values(), ['ok', 'failed']))],
            'layout' => ['nullable', 'string', Rule::enum(PageLayout::class)],
            'system' => ['nullable', 'string', Rule::in(['system', 'custom'])],
            'unpublished' => ['nullable', 'boolean'],
            'missing_seo' => ['nullable', 'boolean'],
            'trashed' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'string', Rule::in(['enabled', 'disabled'])],
            'variant' => ['nullable', 'string', Rule::enum(CtaVariant::class)],
            'unused' => ['nullable', 'boolean'],
            'collection' => ['nullable', 'string', Rule::enum(MediaCollection::class)],
            'type' => ['nullable', 'string', 'max:32', 'regex:/^[a-z_]+$/i'],
            'derivatives' => ['nullable', 'string', Rule::enum(MediaProcessingStatus::class)],
            // An FAQ category id, or `uncategorised` for the bucket with no category.
            'category' => ['nullable', 'string', 'max:20', 'regex:/^(\d{1,19}|uncategorised)$/'],
            'featured' => ['nullable', 'boolean'],
            'attached' => ['nullable', 'boolean'],
            'robots' => ['nullable', 'string', Rule::enum(RobotsDirective::class)],
            'gap' => ['nullable', 'string', Rule::in(['missing_title', 'missing_description', 'missing_og_image', 'noindex', 'excluded_from_sitemap'])],
            'mode' => ['nullable', 'string', Rule::enum(StatisticValueMode::class)],
            'metric' => ['nullable', 'string', Rule::enum(StatisticMetric::class)],
            // `page_id`, never `page`: `?page=` is the paginator's (integration G-4).
            'page_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'string', 'max:32'],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * A list screen answers 422 rather than redirecting to wherever the referrer points (the same
     * decision as `ListFilterRequest`).
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        abort(422, 'This link carries a filter this screen cannot read: '.$validator->errors()->first());
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (self::FILTERS as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $value = $this->input($key);

            if (is_string($value)) {
                $value = trim($value);
            }

            $clean[$key] = ($value === '' || $value === null) ? null : $value;
        }

        if ($clean !== []) {
            $this->merge($clean);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Typed accessors
    |--------------------------------------------------------------------------
    */

    public function searchTerm(): ?string
    {
        return $this->filterString('search');
    }

    public function filterString(string $key): ?string
    {
        $value = $this->validated($key);

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * A tri-state boolean filter: true, false, or null when it was not supplied.
     */
    public function filterBool(string $key): ?bool
    {
        $value = $this->validated($key);

        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public function filterId(string $key): ?int
    {
        $value = $this->validated($key);

        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum|null
     */
    public function filterEnum(string $key, string $enum): ?BackedEnum
    {
        $value = $this->filterString($key);

        return $value === null ? null : $enum::tryFrom($value);
    }

    /**
     * @param  list<string>  $allowed
     */
    public function sortColumn(array $allowed, string $default): string
    {
        $sort = $this->filterString('sort');

        return $sort !== null && in_array($sort, $allowed, true) ? $sort : $default;
    }

    public function sortDirection(string $default = 'asc'): string
    {
        $direction = mb_strtolower((string) $this->filterString('direction'));

        if (in_array($direction, ['asc', 'desc'], true)) {
            return $direction;
        }

        return $default === 'desc' ? 'desc' : 'asc';
    }

    /**
     * The filters that were actually applied, for the filter bar and `withQueryString()`.
     *
     * @return array<string, mixed>
     */
    public function activeFilters(): array
    {
        $active = [];

        foreach (self::FILTERS as $key) {
            if (in_array($key, ['page', 'sort', 'direction'], true)) {
                continue;
            }

            $value = $this->validated($key);

            if ($value !== null && $value !== '') {
                $active[$key] = $value;
            }
        }

        return $active;
    }
}
