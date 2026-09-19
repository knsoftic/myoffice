<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\MediaCollection;
use App\Events\Cms\BlogPostPublished;
use App\Models\Cms\BlogCategory;
use App\Models\Cms\BlogPost;
use App\Models\Cms\BlogTag;
use App\Models\User;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\Support\ContentHelper;
use App\Support\Format;
use App\Support\SlugGenerator;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The blog (phase-04 §6.7, requirement §15): drafts, scheduled posts, tags, authors, related posts.
 *
 * Every status is a `ContentStatus` case (F-5.1) and every move is checked against **`allowedNext()`**, the
 * blog's own transition map (phase-03 owns the enum; the map is blog policy):
 *
 *   draft → scheduled, published · scheduled → draft, published · published → draft, archived · archived → draft
 *
 * (re-scheduling a post that is already scheduled is allowed — it only moves `published_at`).
 *
 * Invariants:
 *
 *   1. `schedule()` needs a moment strictly in the future (otherwise it publishes now) and stores it as
 *      `published_at`. A scheduled post is invisible to the public until `blog:publish-scheduled` flips it.
 *   2. `publish()` sets `published_at = now()` only when it is null or in the future, so a re-published
 *      post keeps its first-publish moment (stable permalinks and sitemap `lastmod`). `BlogPostPublished`
 *      fires exactly once per transition into `published`, never on a plain edit.
 *   3. `unpublish()` returns to draft and leaves `published_at` alone; `archive()` retires a post without
 *      deleting it.
 *   4. `syncTags()` matches tags by slug, case-insensitively and including trashed tags (a trashed tag is
 *      restored, never duplicated), creates the missing ones and detaches the rest; at most 40 per post.
 *   5. `readingMinutes()` counts the words of the stripped content at 200 wpm, minimum 1, stored on save.
 *   6. `related()` never returns an unpublished post or the post itself (§6.7.2).
 *   7. Deleting soft-deletes: the views stay, the slug stays reserved.
 *
 * Authorship: a post is owned by its creator; only a holder of `blog_posts.approve` (the editor) may set
 * or change `author_id` to someone else — anyone else's posted author is ignored. Content is sanitised
 * with `RichText::sanitize()` on write (D25); the featured image is a `media_assets` row (`pages` /
 * `Banner`, D24); SEO is `seo_meta` (D23).
 */
final class BlogService
{
    private const MODULE = 'blog_posts';

    private const RELATED_MAX = 6;

    public function __construct(
        private readonly ContentHelper $content,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $tagNames
     */
    public function store(array $data, ?UploadedFile $image, array $tagNames = []): BlogPost
    {
        return $this->content->transaction(function () use ($data, $image, $tagNames): BlogPost {
            $post = new BlogPost;
            $manualSlug = $this->content->manualSlug($data);
            $target = $this->content->contentStatus($data['status'] ?? null) ?? ContentStatus::Draft;

            $post->fill($this->attributes($data, null));
            $post->setAttribute('slug', $manualSlug ?? '');
            $post->setAttribute('status', ContentStatus::Draft);
            $post->setAttribute('published_at', null);
            $post->setAttribute('author_id', $this->authorFor($data, null));
            $post->setAttribute('featured_image_media_id', $this->content->resolveMediaColumn(
                $data, 'featured_image_media_id', $image, MediaCollection::Pages, ImageProfile::Banner, 'featured_image', null,
            ));

            $this->content->saveWithSlug($post, $manualSlug !== null, 200);
            $this->syncTags($post, $tagNames);
            $this->content->saveSeo($post, $data);
            $this->content->recountMediaAfterCommit([$post->getAttribute('featured_image_media_id')]);

            $this->applyRequestedStatus($post, $target, $data);

            return $post->load(['category', 'tags']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $tagNames  the complete tag list of the form (an empty array clears it)
     */
    public function update(BlogPost $post, array $data, ?UploadedFile $image, array $tagNames = []): BlogPost
    {
        return $this->content->transaction(function () use ($post, $data, $image, $tagNames): BlogPost {
            $oldSlug = (string) $post->getAttribute('slug');
            $oldImage = $post->getAttribute('featured_image_media_id');
            $wasPublic = $post->isPubliclyVisible();
            $wasEverPublished = $post->hasEverBeenPublished() || $post->getAttribute('status') === ContentStatus::Archived;
            $statusBefore = $post->getAttribute('status');
            $manualSlug = $this->content->manualSlug($data);
            $target = $this->content->contentStatus($data['status'] ?? null);

            $post->fill($this->attributes($data, $post));

            if ($manualSlug !== null) {
                $post->setAttribute('slug', $manualSlug);
            }

            if (array_key_exists('author_id', $data)) {
                $post->setAttribute('author_id', $this->authorFor($data, $post));
            }

            $post->setAttribute('featured_image_media_id', $this->content->resolveMediaColumn(
                $data, 'featured_image_media_id', $image, MediaCollection::Pages, ImageProfile::Banner, 'featured_image',
                $oldImage === null ? null : (int) $oldImage,
            ));

            $this->content->saveWithSlug($post, $manualSlug !== null, 200);

            if ((string) $post->getAttribute('slug') !== $oldSlug) {
                $this->content->auditSlugChange($post, self::MODULE, $oldSlug, (string) $post->getAttribute('slug'), $wasEverPublished);
            }

            $this->syncTags($post, $tagNames);
            $this->content->saveSeo($post, $data);
            $this->content->recountMediaAfterCommit([$oldImage, $post->getAttribute('featured_image_media_id')]);

            if ($target !== null) {
                $this->applyRequestedStatus($post, $target, $data);
            }

            if ($post->getAttribute('status') === $statusBefore && ($wasPublic || $post->isPubliclyVisible())) {
                $this->content->flushPublicCache(sprintf('Blog post "%s" updated', (string) $post->getAttribute('title')));
            }

            return $post->load(['category', 'tags']);
        });
    }

    public function publish(BlogPost $post): BlogPost
    {
        return $this->content->transaction(function () use ($post): BlogPost {
            $from = $this->lockStatus($post);

            $this->assertTransition($from, ContentStatus::Published);

            $publishedAt = $post->getAttribute('published_at');
            $oldPublishedAt = $publishedAt instanceof CarbonInterface ? $publishedAt->toDateTimeString() : null;

            if (! $publishedAt instanceof CarbonInterface || $publishedAt->isFuture()) {
                $post->setAttribute('published_at', Carbon::now());
            }

            $this->write($post, ContentStatus::Published);

            $this->auditTransition($post, $from, ContentStatus::Published, $oldPublishedAt, 'published');

            event(new BlogPostPublished($post, false));

            return $post;
        });
    }

    public function schedule(BlogPost $post, CarbonInterface $at): BlogPost
    {
        if (! $at->isFuture()) {
            return $this->publish($post);
        }

        return $this->content->transaction(function () use ($post, $at): BlogPost {
            $from = $this->lockStatus($post);

            if ($from !== ContentStatus::Scheduled) {
                $this->assertTransition($from, ContentStatus::Scheduled);
            }

            $old = $post->getAttribute('published_at');
            $oldValue = $old instanceof CarbonInterface ? $old->toDateTimeString() : null;

            // Stored in the storage zone (UTC, D61) whatever zone the picker produced.
            $post->setAttribute('published_at', Carbon::instance($at)->setTimezone((string) config('app.timezone', 'UTC')));

            $this->write($post, ContentStatus::Scheduled);

            $this->auditTransition($post, $from, ContentStatus::Scheduled, $oldValue, $from === ContentStatus::Scheduled ? 'rescheduled' : 'scheduled');

            return $post;
        });
    }

    public function unpublish(BlogPost $post): BlogPost
    {
        return $this->content->transaction(function () use ($post): BlogPost {
            $from = $this->lockStatus($post);
            $wasPublic = $post->isPubliclyVisible();

            $this->assertTransition($from, ContentStatus::Draft);

            $this->write($post, ContentStatus::Draft);
            $this->auditTransition($post, $from, ContentStatus::Draft, null, 'unpublished');

            if ($wasPublic) {
                $this->content->flushPublicCache(sprintf('Blog post "%s" unpublished', (string) $post->getAttribute('title')));
            }

            return $post;
        });
    }

    public function archive(BlogPost $post): BlogPost
    {
        return $this->content->transaction(function () use ($post): BlogPost {
            $from = $this->lockStatus($post);
            $wasPublic = $post->isPubliclyVisible();

            $this->assertTransition($from, ContentStatus::Archived);

            $this->write($post, ContentStatus::Archived);
            $this->auditTransition($post, $from, ContentStatus::Archived, null, 'archived');

            if ($wasPublic) {
                $this->content->flushPublicCache(sprintf('Blog post "%s" archived', (string) $post->getAttribute('title')));
            }

            return $post;
        });
    }

    /**
     * @param  array<int, mixed>  $tagNames
     */
    public function syncTags(BlogPost $post, array $tagNames): void
    {
        /** @var array<string, string> $wanted slug => name as first typed */
        $wanted = [];

        foreach ($tagNames as $name) {
            $name = $this->content->plain($name);

            if ($name === null) {
                continue;
            }

            if (mb_strlen($name) > BlogPost::MAX_TAG_LENGTH) {
                throw ContentRuleException::refuse('tags', sprintf('Each tag may be at most %d characters.', BlogPost::MAX_TAG_LENGTH));
            }

            $slug = SlugGenerator::normalise($name);

            if ($slug !== '') {
                $wanted[$slug] ??= $name;
            }
        }

        if (count($wanted) > BlogPost::MAX_TAGS) {
            throw ContentRuleException::tooManyItems('tags', BlogPost::MAX_TAGS);
        }

        $this->content->transaction(function () use ($post, $wanted): void {
            $connection = $this->content->connection();
            $postId = (int) $post->getKey();

            $ids = [];

            foreach ($wanted as $slug => $name) {
                $ids[] = $this->tagId($slug, $name);
            }

            $current = $connection->table('blog_post_blog_tag')->where('blog_post_id', $postId)->pluck('blog_tag_id')->map('intval')->all();
            $detach = array_values(array_diff($current, $ids));
            $attach = array_values(array_diff($ids, $current));

            if ($detach !== []) {
                $connection->table('blog_post_blog_tag')->where('blog_post_id', $postId)->whereIn('blog_tag_id', $detach)->delete();
            }

            if ($attach !== []) {
                $connection->table('blog_post_blog_tag')->insertOrIgnore(array_map(
                    static fn (int $tagId): array => ['blog_post_id' => $postId, 'blog_tag_id' => $tagId],
                    $attach,
                ));
            }

            if ($detach !== [] || $attach !== []) {
                $names = static fn (array $tagIds): array => BlogTag::withTrashed()->whereIn('id', $tagIds)->orderBy('name')->pluck('name')->all();

                $this->content->audit(
                    self::MODULE,
                    sprintf('Tags of blog post "%s" updated', (string) $post->getAttribute('title')),
                    $post,
                    ['old' => ['tags' => $names($current)], 'attributes' => ['tags' => $names($ids)]],
                    null,
                    'tags_synced',
                );
            }

            $post->unsetRelation('tags');
        });
    }

    /**
     * Up to `$limit` related published posts (default `website.blog_related_count`), filled in tiers:
     * same category → shared tags (most shared first) → featured, then newest. Deterministic, never the
     * post itself, never unpublished. Three id queries at most, then one load with `category` and `tags`.
     *
     * @return Collection<int, BlogPost>
     */
    public function related(BlogPost $post, ?int $limit = null): Collection
    {
        $limit ??= $this->content->int($this->content->settings()->get('website.blog_related_count', 3), 3);
        $limit = max(0, min(self::RELATED_MAX, (int) $limit));

        if ($limit === 0) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        $postId = (int) $post->getKey();
        $ids = [];

        $categoryId = $post->getAttribute('blog_category_id');

        if ($categoryId !== null) {
            $ids = BlogPost::query()->public()
                ->where('blog_posts.blog_category_id', $categoryId)
                ->whereKeyNot($postId)
                ->latestPublished()
                ->limit($limit)
                ->pluck('blog_posts.id')
                ->map('intval')
                ->all();
        }

        if (count($ids) < $limit) {
            $shared = BlogPost::query()->public()
                ->join('blog_post_blog_tag as shared', 'shared.blog_post_id', '=', 'blog_posts.id')
                ->whereIn('shared.blog_tag_id', static function ($query) use ($postId): void {
                    $query->select('blog_tag_id')->from('blog_post_blog_tag')->where('blog_post_id', $postId);
                })
                ->whereKeyNot($postId)
                ->whereNotIn('blog_posts.id', $ids)
                ->groupBy('blog_posts.id', 'blog_posts.published_at')
                ->orderByRaw('COUNT(*) DESC')
                ->orderByDesc('blog_posts.published_at')
                ->orderByDesc('blog_posts.id')
                ->limit($limit - count($ids))
                ->pluck('blog_posts.id')
                ->map('intval')
                ->all();

            $ids = [...$ids, ...$shared];
        }

        if (count($ids) < $limit) {
            $padding = BlogPost::query()->public()
                ->whereKeyNot($postId)
                ->whereNotIn('blog_posts.id', $ids)
                ->orderByDesc('blog_posts.is_featured')
                ->latestPublished()
                ->limit($limit - count($ids))
                ->pluck('blog_posts.id')
                ->map('intval')
                ->all();

            $ids = [...$ids, ...$padding];
        }

        if ($ids === []) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        $order = array_flip($ids);

        return BlogPost::query()
            ->with(['category', 'tags'])
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(static fn (BlogPost $related): int => $order[(int) $related->getKey()] ?? PHP_INT_MAX)
            ->values();
    }

    public function readingMinutes(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $words = (int) preg_match_all('~[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*~u', $text);

        return min(255, max(1, (int) ceil($words / BlogPost::WORDS_PER_MINUTE)));
    }

    /**
     * The blog transition map (F-5.1).
     *
     * @return list<ContentStatus>
     */
    public function allowedNext(ContentStatus $from): array
    {
        return match ($from) {
            ContentStatus::Draft => [ContentStatus::Scheduled, ContentStatus::Published],
            ContentStatus::Scheduled => [ContentStatus::Draft, ContentStatus::Published],
            ContentStatus::Published => [ContentStatus::Draft, ContentStatus::Archived],
            ContentStatus::Archived => [ContentStatus::Draft],
        };
    }

    public function delete(BlogPost $post): void
    {
        $this->content->transaction(function () use ($post): void {
            $wasPublic = $post->isPubliclyVisible();

            $post->delete();

            if ($wasPublic) {
                $this->content->flushPublicCache(sprintf('Blog post "%s" deleted', (string) $post->getAttribute('title')));
            }
        });
    }

    /**
     * Publish every scheduled post whose moment has come — `blog:publish-scheduled` (§6.7.3).
     *
     * Chunks of `$chunkSize`, each in one transaction with the rows locked `FOR UPDATE`. The scheduled
     * moment **is** the publish moment, so `published_at` is not rewritten. Each post fires
     * `BlogPostPublished` with `$viaScheduler = true` and gets one activity entry with the system as the
     * actor. A backlog left by a scheduler outage is cleared in one call; a second runner finds the rows
     * already published and does nothing twice. Draft and archived rows are never touched.
     *
     * @return int how many posts were published
     */
    public function publishDue(int $chunkSize = 50): int
    {
        $chunkSize = max(1, $chunkSize);
        $total = 0;

        do {
            $published = $this->content->transaction(function () use ($chunkSize): int {
                $posts = BlogPost::query()
                    ->dueForPublishing()
                    ->orderBy('blog_posts.published_at')
                    ->orderBy('blog_posts.id')
                    ->limit($chunkSize)
                    ->lockForUpdate()
                    ->get();

                foreach ($posts as $post) {
                    $this->write($post, ContentStatus::Published);

                    $this->content->audit(
                        self::MODULE,
                        sprintf('Blog post "%s" published on schedule', (string) $post->getAttribute('title')),
                        $post,
                        [
                            'old' => ['status' => ContentStatus::Scheduled->value],
                            'attributes' => ['status' => ContentStatus::Published->value],
                            'published_at' => $post->getAttribute('published_at')?->toDateTimeString(),
                            'actor' => 'system',
                        ],
                        null,
                        'published',
                    );

                    event(new BlogPostPublished($post, true));
                }

                return $posts->count();
            });

            $total += $published;
        } while ($published === $chunkSize);

        return $total;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Honour a status posted with the editor form (Save draft / Publish now / Schedule…) through the same
     * methods the dedicated buttons use.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyRequestedStatus(BlogPost $post, ContentStatus $target, array $data): void
    {
        $current = $post->getAttribute('status');

        if ($target === ContentStatus::Scheduled) {
            $at = $this->dateTime($data['published_at'] ?? null, 'published_at');

            if ($at === null) {
                throw ContentRuleException::refuse('published_at', 'Choose when the post should go live.');
            }

            if ($current !== ContentStatus::Scheduled || ! $post->getAttribute('published_at')?->equalTo($at)) {
                $this->schedule($post, $at);
            }

            return;
        }

        if ($target === $current) {
            return;
        }

        match ($target) {
            ContentStatus::Published => $this->publish($post),
            ContentStatus::Draft => $this->unpublish($post),
            ContentStatus::Archived => $this->archive($post),
        };
    }

    private function assertTransition(ContentStatus $from, ContentStatus $to): void
    {
        if (! in_array($to, $this->allowedNext($from), true)) {
            throw ContentRuleException::transitionNotAllowed($from->label(), $to->label());
        }
    }

    private function lockStatus(BlogPost $post): ContentStatus
    {
        $row = BlogPost::withTrashed()->whereKey($post->getKey())->lockForUpdate()->first(['id', 'status', 'published_at']);

        if ($row === null) {
            throw ContentRuleException::refuse('status', 'This post no longer exists.');
        }

        $post->setAttribute('status', $row->getAttribute('status'));
        $post->setAttribute('published_at', $row->getAttribute('published_at'));
        $post->syncOriginalAttributes(['status', 'published_at']);

        $status = $post->getAttribute('status');

        return $status instanceof ContentStatus ? $status : ContentStatus::Draft;
    }

    private function write(BlogPost $post, ContentStatus $status): void
    {
        $this->content->quietly($post, function () use ($post, $status): void {
            $post->setAttribute('status', $status);
            $post->save();
        });
    }

    private function auditTransition(BlogPost $post, ContentStatus $from, ContentStatus $to, ?string $oldPublishedAt, string $event): void
    {
        $publishedAt = $post->getAttribute('published_at');

        $this->content->audit(
            self::MODULE,
            sprintf('Blog post "%s" moved from %s to %s', (string) $post->getAttribute('title'), $from->label(), $to->label()),
            $post,
            [
                'old' => ['status' => $from->value, 'published_at' => $oldPublishedAt],
                'attributes' => [
                    'status' => $to->value,
                    'published_at' => $publishedAt instanceof CarbonInterface ? $publishedAt->toDateTimeString() : null,
                ],
            ],
            null,
            $event,
        );
    }

    private function tagId(string $slug, string $name): int
    {
        $tag = BlogTag::withTrashed()->where('slug', $slug)->first();

        if ($tag instanceof BlogTag) {
            if ($tag->trashed()) {
                $tag->restore();
            }

            return (int) $tag->getKey();
        }

        try {
            return (int) $this->content->transaction(static fn () => BlogTag::query()->create([
                'name' => mb_substr($name, 0, 100),
                'slug' => $slug,
                'is_active' => true,
            ]))->getKey();
        } catch (UniqueConstraintViolationException) {
            // A concurrent save created the same tag first.
            $existing = BlogTag::withTrashed()->where('slug', $slug)->first();

            if (! $existing instanceof BlogTag) {
                throw ContentRuleException::refuse('tags', sprintf('The tag "%s" could not be saved. Try again.', $name));
            }

            return (int) $existing->getKey();
        }
    }

    /**
     * The author a save may set: the editor's choice, otherwise the actor (create) or the existing author.
     *
     * @param  array<string, mixed>  $data
     */
    private function authorFor(array $data, ?BlogPost $post): ?int
    {
        $actor = Auth::user();
        $requested = $this->content->id($data['author_id'] ?? null);
        $isEditor = ! $actor instanceof User || $this->isEditor($actor);

        if ($requested !== null && $isEditor) {
            if (! User::query()->whereKey($requested)->exists()) {
                throw ContentRuleException::refuse('author_id', 'The chosen author no longer exists.');
            }

            return $requested;
        }

        if ($post !== null) {
            return $post->getAttribute('author_id') === null ? null : (int) $post->getAttribute('author_id');
        }

        return $actor instanceof User ? (int) $actor->getKey() : null;
    }

    private function isEditor(User $user): bool
    {
        try {
            return $user->can('blog_posts.approve');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?BlogPost $post): array
    {
        $attributes = [];
        $creating = $post === null;

        if ($creating || array_key_exists('title', $data)) {
            $title = $this->content->plain($data['title'] ?? null, 200);

            if ($title === null) {
                throw ContentRuleException::refuse('title', 'A title is required.');
            }

            $attributes['title'] = $title;
        }

        if (array_key_exists('blog_category_id', $data)) {
            $categoryId = $this->content->id($data['blog_category_id']);

            if ($categoryId !== null && ! BlogCategory::query()->whereKey($categoryId)->exists()) {
                throw ContentRuleException::refuse('blog_category_id', 'The chosen category no longer exists.');
            }

            $attributes['blog_category_id'] = $categoryId;
        }

        $content = $post?->getAttribute('content');

        if ($creating || array_key_exists('content', $data)) {
            $content = $this->content->rich($data['content'] ?? null);

            if ($content === null) {
                throw ContentRuleException::refuse('content', 'The post needs some content.');
            }

            $attributes['content'] = $content;
            $attributes['reading_minutes'] = $this->readingMinutes($content);
        }

        if ($creating || array_key_exists('excerpt', $data) || trim((string) $post?->getAttribute('excerpt')) === '') {
            $excerpt = array_key_exists('excerpt', $data) ? $this->content->plain($data['excerpt'], 500) : $post?->getAttribute('excerpt');
            $attributes['excerpt'] = $excerpt ?? $this->excerptFrom((string) $content);
        }

        if (array_key_exists('featured_image_alt', $data)) {
            $attributes['featured_image_alt'] = $this->content->plain($data['featured_image_alt'], 180);
        }

        if ($creating || array_key_exists('is_featured', $data)) {
            $attributes['is_featured'] = $this->content->bool($data['is_featured'] ?? null, false);
        }

        return $attributes;
    }

    private function excerptFrom(string $html): ?string
    {
        $text = trim((string) preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > BlogPost::EXCERPT_FALLBACK_LENGTH
            ? rtrim(mb_substr($text, 0, BlogPost::EXCERPT_FALLBACK_LENGTH))
            : $text;
    }

    private function dateTime(mixed $value, string $field): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value);
        }

        try {
            // A value from the form is wall-clock time in the business timezone (D61).
            return Carbon::parse((string) $value, Format::timezone());
        } catch (Throwable) {
            throw ContentRuleException::refuse($field, 'Enter a valid date and time.');
        }
    }
}
