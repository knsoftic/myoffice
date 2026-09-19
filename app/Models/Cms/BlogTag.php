<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Models\Cms\Concerns\SearchesContent;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasSlug;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One blog tag (phase-04 §2.14). `name` is stored as typed; `slug` is the de-duplication key
 * `BlogService::syncTags()` matches on — case-insensitively and including trashed rows, so "Laravel",
 * "laravel" and " LARAVEL " are one tag and a trashed tag is restored rather than duplicated.
 *
 * An inactive tag keeps its posts but leaves the public tag cloud, and `/blog/tag/{slug}` 404s.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class BlogTag extends Model
{
    use Blameable;
    use HasSlug;
    use LogsActivityWithContext;
    use SearchesContent;
    use SoftDeletes;

    protected $table = 'blog_tags';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function moduleSlug(): string
    {
        return 'blog_tags';
    }

    protected function activityModule(): ?string
    {
        return 'blog_tags';
    }

    public function sluggableSource(): string
    {
        return (string) $this->name;
    }

    /**
     * @return list<string>
     */
    protected function searchableColumns(): array
    {
        return ['name', 'slug'];
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsToMany<BlogPost, $this, BlogPostBlogTag>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(BlogPost::class, 'blog_post_blog_tag', 'blog_tag_id', 'blog_post_id')
            ->using(BlogPostBlogTag::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * The only filter the public site may use (§9.2).
     *
     * @param  Builder<BlogTag>  $query
     * @return Builder<BlogTag>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<BlogTag>  $query
     * @return Builder<BlogTag>
     */
    public function scopeActive(Builder $query, bool $active = true): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), $active);
    }

    /**
     * @param  Builder<BlogTag>  $query
     * @return Builder<BlogTag>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('name'))->orderBy($query->qualifyColumn('id'));
    }
}
