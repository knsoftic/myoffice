<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\MediaCollection;
use App\Models\Cms\BlogCategory;
use App\Models\Cms\BlogTag;
use App\Models\Cms\PortfolioCategory;
use App\Models\Cms\ServiceCategory;
use App\Models\Cms\Technology;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\Support\ContentHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The five small lists everything else hangs off (phase-04 §6.2, §8.1): service categories, portfolio
 * categories, blog categories, blog tags and technologies.
 *
 * Invariants:
 *
 *   · `delete()` **refuses** (422 on `reassign_to`) while the term still has live children and no
 *     `$reassignTo` is given. With one, every child — trashed children included — is moved to that term
 *     in the same transaction, then the term is soft-deleted. `$reassignTo` must be a different, existing
 *     term of the same model.
 *   · Deleting a `Technology` only detaches its pivot rows (`service_technology`,
 *     `portfolio_item_technology`); it never touches a service or a portfolio item and needs no reassign.
 *   · A slug is generated once, on create; `update()` never changes it by itself. A hand-edited slug is
 *     written to the activity log as `slug_changed`.
 *   · Images are `media_assets` rows through `MediaService` (categories: `pages` / `Card`; technology
 *     logos: `general` / `Logo`); tags have none. SEO for the three category types is `seo_meta` through
 *     `SeoService` (D23).
 *   · Every write is one transaction; a change a visitor can see bumps the public cache after commit.
 */
final class TaxonomyService
{
    public function __construct(
        private readonly ContentHelper $content,
    ) {}

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $data
     */
    public function store(string $modelClass, array $data, ?UploadedFile $image = null): Model
    {
        $definition = $this->definition($modelClass);

        return $this->content->transaction(function () use ($modelClass, $definition, $data, $image): Model {
            /** @var Model $term */
            $term = new $modelClass;
            $manualSlug = $this->content->manualSlug($data);

            $term->fill($this->attributes($definition, $data, null));
            $term->setAttribute('slug', $manualSlug ?? '');

            if (! array_key_exists('sort_order', $data) && $definition['sortable']) {
                $term->setAttribute('sort_order', (int) $modelClass::query()->withTrashed()->max('sort_order') + 1);
            }

            if ($definition['media'] !== null) {
                $term->setAttribute($definition['media'], $this->content->resolveMediaColumn(
                    $data,
                    $definition['media'],
                    $image,
                    $definition['collection'],
                    $definition['profile'],
                    str_replace('_media_id', '', (string) $definition['media']),
                    null,
                ));
            }

            $this->content->saveWithSlug($term, $manualSlug !== null);

            if ($definition['seo']) {
                $this->content->saveSeo($term, $data);
            }

            if ($definition['media'] !== null) {
                $this->content->recountMediaAfterCommit([$term->getAttribute($definition['media'])]);
            }

            if ((bool) $term->getAttribute('is_active')) {
                $this->content->flushPublicCache(sprintf('%s "%s" created', $definition['label'], (string) $term->getAttribute('name')));
            }

            return $term;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Model $term, array $data, ?UploadedFile $image = null): Model
    {
        $definition = $this->definition($term::class);

        return $this->content->transaction(function () use ($term, $definition, $data, $image): Model {
            $oldSlug = (string) $term->getAttribute('slug');
            $wasActive = (bool) $term->getAttribute('is_active');
            $oldMedia = $definition['media'] !== null ? $term->getAttribute($definition['media']) : null;
            $manualSlug = $this->content->manualSlug($data);

            $term->fill($this->attributes($definition, $data, $term));

            if ($manualSlug !== null) {
                $term->setAttribute('slug', $manualSlug);
            }

            if ($definition['media'] !== null) {
                $term->setAttribute($definition['media'], $this->content->resolveMediaColumn(
                    $data,
                    $definition['media'],
                    $image,
                    $definition['collection'],
                    $definition['profile'],
                    str_replace('_media_id', '', (string) $definition['media']),
                    $oldMedia === null ? null : (int) $oldMedia,
                ));
            }

            $this->content->saveWithSlug($term, $manualSlug !== null);

            $newSlug = (string) $term->getAttribute('slug');

            if ($newSlug !== $oldSlug) {
                $this->content->auditSlugChange($term, $definition['module'], $oldSlug, $newSlug, $wasActive);
            }

            if ($definition['seo']) {
                $this->content->saveSeo($term, $data);
            }

            if ($definition['media'] !== null) {
                $this->content->recountMediaAfterCommit([$oldMedia, $term->getAttribute($definition['media'])]);
            }

            if ($wasActive || (bool) $term->getAttribute('is_active')) {
                $this->content->flushPublicCache(sprintf('%s "%s" updated', $definition['label'], (string) $term->getAttribute('name')));
            }

            return $term;
        });
    }

    public function delete(Model $term, ?int $reassignTo = null): void
    {
        $definition = $this->definition($term::class);

        $this->content->transaction(function () use ($term, $definition, $reassignTo): void {
            $id = (int) $term->getKey();
            $connection = $this->content->connection();

            if ($definition['kind'] === 'detach') {
                $detached = 0;

                foreach ($definition['pivots'] as $pivot => $owner) {
                    $detached += $connection->table($pivot)->where('technology_id', $id)->delete();
                }

                $term->delete();

                if ($detached > 0) {
                    $this->content->audit(
                        $definition['module'],
                        sprintf('Technology "%s" removed from %d %s', (string) $term->getAttribute('name'), $detached, $detached === 1 ? 'record' : 'records'),
                        $term,
                        ['detached' => $detached],
                        null,
                        'detached',
                    );
                }

                $this->content->flushPublicCache(sprintf('Technology "%s" deleted', (string) $term->getAttribute('name')));

                return;
            }

            $target = null;

            if ($reassignTo !== null) {
                $target = $term::query()->whereKey($reassignTo)->first();

                if (! $target instanceof Model || (int) $target->getKey() === $id) {
                    throw ContentRuleException::invalidReassignTarget();
                }
            }

            $children = $this->liveChildren($definition, $id);

            if ($children > 0 && $target === null) {
                throw ContentRuleException::termHasChildren((string) $term->getAttribute('name'), $children, $definition['noun']);
            }

            $moved = $target === null ? 0 : $this->moveChildren($definition, $id, (int) $target->getKey());

            $term->delete();

            if ($target !== null && $moved > 0) {
                $this->content->audit(
                    $definition['module'],
                    sprintf(
                        'Moved %d %s from "%s" to "%s" before deleting it',
                        $moved,
                        $moved === 1 ? $definition['noun'] : $definition['noun'].'s',
                        (string) $term->getAttribute('name'),
                        (string) $target->getAttribute('name'),
                    ),
                    $term,
                    ['reassigned_to' => (int) $target->getKey(), 'moved' => $moved],
                    null,
                    'children_reassigned',
                );
            }

            $this->content->flushPublicCache(sprintf('%s "%s" deleted', $definition['label'], (string) $term->getAttribute('name')));
        });
    }

    public function toggleActive(Model $term): Model
    {
        $definition = $this->definition($term::class);

        return $this->content->transaction(function () use ($term, $definition): Model {
            $term->setAttribute('is_active', ! (bool) $term->getAttribute('is_active'));
            $term->save();

            $this->content->flushPublicCache(sprintf(
                '%s "%s" %s',
                $definition['label'],
                (string) $term->getAttribute('name'),
                (bool) $term->getAttribute('is_active') ? 'activated' : 'deactivated',
            ));

            return $term;
        });
    }

    /**
     * How many live (non-trashed) records hang off the term — the "12 services" column and the reassign
     * dialog's count (§8.1).
     */
    public function childrenCount(Model $term): int
    {
        $definition = $this->definition($term::class);

        return $definition['kind'] === 'detach' ? 0 : $this->liveChildren($definition, (int) $term->getKey());
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function liveChildren(array $definition, int $id): int
    {
        $connection = $this->content->connection();

        if ($definition['kind'] === 'pivot') {
            return $connection->table('blog_post_blog_tag as pivot')
                ->join('blog_posts as posts', 'posts.id', '=', 'pivot.blog_post_id')
                ->where('pivot.blog_tag_id', $id)
                ->whereNull('posts.deleted_at')
                ->count();
        }

        return $connection->table($definition['children'])
            ->where($definition['foreign'], $id)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Move every child, trashed ones included, onto `$targetId`. Returns how many moved.
     *
     * @param  array<string, mixed>  $definition
     */
    private function moveChildren(array $definition, int $fromId, int $targetId): int
    {
        $connection = $this->content->connection();

        if ($definition['kind'] === 'pivot') {
            $postIds = $connection->table('blog_post_blog_tag')->where('blog_tag_id', $fromId)->pluck('blog_post_id')->all();

            foreach (array_chunk($postIds, 500) as $chunk) {
                $connection->table('blog_post_blog_tag')->insertOrIgnore(array_map(
                    static fn ($postId): array => ['blog_post_id' => (int) $postId, 'blog_tag_id' => $targetId],
                    $chunk,
                ));
            }

            $connection->table('blog_post_blog_tag')->where('blog_tag_id', $fromId)->delete();

            return count($postIds);
        }

        return $connection->table($definition['children'])
            ->where($definition['foreign'], $fromId)
            ->update([$definition['foreign'] => $targetId, 'updated_at' => Carbon::now()]);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $definition, array $data, ?Model $term): array
    {
        $attributes = [];

        if (array_key_exists('name', $data) || $term === null) {
            $name = $this->content->plain($data['name'] ?? null, $definition['name_max']);

            if ($name === null) {
                throw ContentRuleException::refuse('name', 'A name is required.');
            }

            $attributes['name'] = $name;
        }

        if ($definition['has_description'] && array_key_exists('description', $data)) {
            $attributes['description'] = $this->content->plain($data['description']);
        }

        if ($definition['has_icon'] && array_key_exists('icon', $data)) {
            $attributes['icon'] = $this->content->plain($data['icon'], 64);
        }

        if ($definition['has_color'] && array_key_exists('color', $data)) {
            $color = $this->content->plain($data['color'], 16);

            if ($color !== null && preg_match(Technology::COLOR_PATTERN, $color) !== 1) {
                throw ContentRuleException::refuse('color', 'Use a hex colour such as #1E40AF.');
            }

            $attributes['color'] = $color;
        }

        if (array_key_exists('is_active', $data) || $term === null) {
            $attributes['is_active'] = $this->content->bool($data['is_active'] ?? null, true);
        }

        if ($definition['sortable'] && array_key_exists('sort_order', $data)) {
            $attributes['sort_order'] = $this->content->int($data['sort_order'], 0, 0);
        }

        return $attributes;
    }

    /**
     * @param  class-string  $modelClass
     * @return array<string, mixed>
     */
    private function definition(string $modelClass): array
    {
        $category = static fn (string $module, string $label, string $children, string $foreign, string $noun): array => [
            'module' => $module,
            'label' => $label,
            'kind' => 'foreign',
            'children' => $children,
            'foreign' => $foreign,
            'noun' => $noun,
            'media' => 'image_media_id',
            'collection' => MediaCollection::Pages,
            'profile' => ImageProfile::Card,
            'seo' => true,
            'sortable' => true,
            'has_description' => true,
            'has_icon' => true,
            'has_color' => false,
            'name_max' => 150,
        ];

        return match ($modelClass) {
            ServiceCategory::class => $category('service_categories', 'Service category', 'services', 'service_category_id', 'service'),
            PortfolioCategory::class => $category('portfolio_categories', 'Portfolio category', 'portfolio_items', 'portfolio_category_id', 'project'),
            BlogCategory::class => $category('blog_categories', 'Blog category', 'blog_posts', 'blog_category_id', 'post'),
            BlogTag::class => [
                'module' => 'blog_tags',
                'label' => 'Blog tag',
                'kind' => 'pivot',
                'noun' => 'post',
                'media' => null,
                'collection' => null,
                'profile' => null,
                'seo' => false,
                'sortable' => false,
                'has_description' => false,
                'has_icon' => false,
                'has_color' => false,
                'name_max' => 100,
            ],
            Technology::class => [
                'module' => 'technologies',
                'label' => 'Technology',
                'kind' => 'detach',
                'pivots' => ['service_technology' => 'service_id', 'portfolio_item_technology' => 'portfolio_item_id'],
                'noun' => 'record',
                'media' => 'logo_media_id',
                'collection' => MediaCollection::General,
                'profile' => ImageProfile::Logo,
                'seo' => false,
                'sortable' => true,
                'has_description' => false,
                'has_icon' => true,
                'has_color' => true,
                'name_max' => 100,
            ],
            default => throw new InvalidArgumentException(sprintf('[%s] is not a phase-04 taxonomy model.', $modelClass)),
        };
    }
}
