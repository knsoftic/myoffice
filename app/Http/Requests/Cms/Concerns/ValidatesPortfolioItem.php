<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Enums\Cms\ContentStatus;
use App\Models\Cms\PortfolioItem;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * A portfolio case study (phase-04 §2.7, §6.3, §6.11 `StorePortfolioItemRequest` /
 * `UpdatePortfolioItemRequest`, §8.3).
 *
 *   · `completion_date` — `nullable, date, before_or_equal:today`, "today" in the display timezone;
 *   · `project_url` — `nullable, url, max:255` (rendered `rel="nofollow noopener"`);
 *   · `images.*` — the §6.6 image rules, at most 20 in one batch (create only; the gallery manager
 *     posts to `admin.portfolio.images.store` afterwards);
 *   · `client_id` is a deferred link (§2.1): until Phase 5 ships `clients` the form offers the
 *     free-text `client_name` only, so the id is prohibited while the table is absent;
 *   · `status` and `is_featured` are never written by the save: the controller re-checks
 *     `portfolio.change_status` and routes a change through `changeStatus()` / `toggleFeatured()`;
 *   · `cover_media_id` is prohibited — it moves only through the set-cover endpoint (§6.3 invariant 1: the
 *     cover must be an attached image).
 *
 * The using class must extend `CmsFormRequest`, use `ValidatesDeferredLinks`, `ValidatesContentSlug`,
 * `ValidatesContentImage`, `DelegatesSeoRules` and `NormalisesContentInput`, and implement
 * `portfolioItem()`.
 */
trait ValidatesPortfolioItem
{
    /** §6.3 invariant 3. */
    public const MAX_GALLERY_IMAGES = 20;

    abstract public function portfolioItem(): ?PortfolioItem;

    /**
     * @return array<string, list<mixed>>
     */
    protected function portfolioRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $id = $this->portfolioItem()?->getKey();

        $rules = [
            'title' => array_merge($required, ['bail', 'string', 'max:180']),
            'slug' => $this->slugRules('portfolio_items', is_numeric($id) ? (int) $id : null),
            'portfolio_category_id' => ['sometimes', 'bail', 'nullable', 'integer', 'min:1', Rule::exists('portfolio_categories', 'id')->whereNull('deleted_at')],
            'client_name' => ['sometimes', 'bail', 'nullable', 'string', 'max:150'],
            'client_id' => $this->deferredLinkRules('clients'),
            'summary' => ['sometimes', 'bail', 'nullable', 'string', 'max:500'],
            'description' => ['sometimes', 'bail', 'nullable', 'string', 'max:200000'],
            'technologies_note' => ['sometimes', 'bail', 'nullable', 'string', 'max:255'],
            'project_url' => ['sometimes', 'bail', 'nullable', 'string', 'max:255', 'url:http,https'],
            'completion_date' => ['sometimes', 'bail', 'nullable', 'string', 'max:40', 'date', $this->notInTheFuture()],
            'sort_order' => ['sometimes', 'bail', 'nullable', 'integer', 'min:0', 'max:2147483647'],
            'technology_ids' => ['sometimes', 'bail', 'nullable', 'array', 'max:50'],
            'technology_ids.*' => ['bail', 'integer', 'min:1', 'distinct', Rule::exists('technologies', 'id')->whereNull('deleted_at')],

            // Offered to `portfolio.change_status` holders only; acted on by the controller through
            // changeStatus() / toggleFeatured() after it re-checks that ability.
            'status' => ['sometimes', 'bail', 'string', Rule::in([
                ContentStatus::Draft->value,
                ContentStatus::Published->value,
                ContentStatus::Archived->value,
            ])],
            'is_featured' => ['sometimes', 'boolean'],
            'cover_media_id' => ['prohibited'],
        ];

        if (! $partial) {
            $image = $this->imageRules('image')['image'];
            $rules['images'] = ['sometimes', 'bail', 'nullable', 'array', 'max:'.self::MAX_GALLERY_IMAGES];
            $rules['images.*'] = array_values(array_diff($image, ['sometimes', 'nullable']));
        } else {
            $rules['images'] = ['prohibited'];
        }

        return array_merge($rules, $this->seoRules());
    }

    protected function preparePortfolioInput(): void
    {
        $this->trimInputs(['title', 'client_name', 'summary', 'description', 'technologies_note', 'project_url']);
        $this->normaliseSlugInput();
    }

    protected function portfolioAfter(Validator $validator): void
    {
        $this->seoAfter($validator);
    }

    /**
     * The `portfolio_items` columns for `PortfolioService` — no SEO, no technologies, no uploads.
     *
     * @return array<string, mixed>
     */
    public function portfolioPayload(): array
    {
        return $this->safe()->except(['seo', 'images', 'technology_ids', 'status', 'is_featured']);
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

    /**
     * The gallery uploads of a create form, in the order they were chosen.
     *
     * @return list<UploadedFile>
     */
    public function galleryUploads(): array
    {
        $files = $this->file('images');

        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    /**
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

    private function notInTheFuture(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            try {
                $date = CarbonImmutable::parse($value)->toDateString();
                $today = CarbonImmutable::now(Format::displayTimezone())->toDateString();
            } catch (Throwable) {
                return; // the `date` rule has already reported an unreadable value
            }

            if ($date > $today) {
                $fail('The completion date cannot be in the future.');
            }
        };
    }
}
