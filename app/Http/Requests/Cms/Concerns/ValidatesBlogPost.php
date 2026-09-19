<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Models\Cms\BlogPost;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * A blog post from the two-column editor (phase-04 §2.16, §6.7, §6.11 `StoreBlogPostRequest` /
 * `UpdateBlogPostRequest`, §8.7, acceptance tests 28, 30, 31, 65).
 *
 *   · `title` required ≤ 200; `slug` per §6.1 (≤ 200); `blog_category_id` nullable, existing;
 *   · `tags.*` strings ≤ 40, at most 40 — matched and created by `BlogService::syncTags()`;
 *   · `content` required; `published_at` nullable date, read in the display timezone (D61);
 *   · `author_id` only for an editor holding `blog_posts.approve` — for anyone else it is prohibited,
 *     so an author can never publish under someone else's name;
 *   · `intent` is the button pressed: *Save* (`save`, or `draft`), *Publish now* (`publish`) or
 *     *Schedule* (`schedule`, which then requires a future `published_at`). The controller acts on
 *     `publish` / `schedule` only with `blog_posts.change_status` plus the policy;
 *   · `status`, `views_count` and `reading_minutes` are never writable from a form;
 *   · **no SEO rule is declared here** (ND-13) — the SEO block is `SeoService::rules()`.
 *
 * The using class must extend `CmsFormRequest` and implement `blogPost()`.
 */
trait ValidatesBlogPost
{
    abstract public function blogPost(): ?BlogPost;

    /**
     * @return array<string, list<mixed>>
     */
    protected function blogPostRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $id = $this->blogPost()?->getKey();
        $editor = $this->user()?->can('blog_posts.approve') === true;

        return array_merge([
            'title' => array_merge($required, ['bail', 'string', 'max:200']),
            'slug' => $this->slugRules('blog_posts', is_numeric($id) ? (int) $id : null, 200),
            'blog_category_id' => ['sometimes', 'bail', 'nullable', 'integer', 'min:1', Rule::exists('blog_categories', 'id')->whereNull('deleted_at')],
            'author_id' => $editor
                ? ['sometimes', 'bail', 'nullable', 'integer', 'min:1', Rule::exists('users', 'id')]
                : ['prohibited'],
            'excerpt' => ['sometimes', 'bail', 'nullable', 'string', 'max:500'],
            'content' => array_merge($required, ['bail', 'string', 'max:1000000']),
            'featured_image_alt' => ['sometimes', 'bail', 'nullable', 'string', 'max:180'],
            'is_featured' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'bail', 'nullable', 'array', 'max:40'],
            'tags.*' => ['bail', 'string', 'max:40'],
            'published_at' => ['sometimes', 'bail', 'nullable', 'required_if:intent,schedule', 'string', 'max:40', 'date'],
            'intent' => ['sometimes', 'bail', 'nullable', 'string', Rule::in(['save', 'draft', 'publish', 'schedule'])],

            'status' => ['prohibited'],
            'views_count' => ['prohibited'],
            'reading_minutes' => ['prohibited'],
        ], $this->imageRules('featured_image'), $this->seoRules());
    }

    protected function prepareBlogPostInput(): void
    {
        $this->trimInputs(['title', 'excerpt', 'featured_image_alt', 'published_at', 'intent']);
        $this->normaliseSlugInput();
        $this->cleanStringLists(['tags']);
    }

    protected function blogPostAfter(Validator $validator): void
    {
        $this->seoAfter($validator);

        if ($this->intent() === 'schedule' && ! $validator->errors()->has('published_at')) {
            $at = $this->publishedAt();

            if ($at === null || ! $at->isFuture()) {
                $validator->errors()->add('published_at', 'Choose a moment in the future to schedule the post.');
            }
        }
    }

    /**
     * The `blog_posts` columns for `BlogService` — no SEO, no tags, no upload, no intent.
     *
     * @return array<string, mixed>
     */
    public function blogPostPayload(): array
    {
        $data = $this->safe()->except(['seo', 'tags', 'intent', 'featured_image', 'featured_image_media_id', 'remove_featured_image', 'published_at']);

        if (array_key_exists('is_featured', $data)) {
            $data['is_featured'] = $this->boolean('is_featured');
        }

        return array_merge($data, $this->imageColumnPayload('featured_image_media_id', 'featured_image'));
    }

    /**
     * @return list<string>
     */
    public function tagNames(): array
    {
        return $this->validatedStrings('tags');
    }

    public function hasTags(): bool
    {
        return $this->has('tags');
    }

    public function featuredImage(): ?UploadedFile
    {
        return $this->uploadedImage('featured_image');
    }

    /**
     * `save` (the default — `draft` is accepted as the same button), `publish` or `schedule`.
     */
    public function intent(): string
    {
        $intent = $this->input('intent');

        return is_string($intent) && in_array($intent, ['publish', 'schedule'], true) ? $intent : 'save';
    }

    /**
     * `published_at` read in the display timezone and returned in UTC (D61), or null.
     */
    public function publishedAt(): ?CarbonImmutable
    {
        $value = $this->input('published_at');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, Format::displayTimezone())->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
