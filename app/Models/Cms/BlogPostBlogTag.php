<?php

declare(strict_types=1);

namespace App\Models\Cms;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One tag on one post — the `blog_post_blog_tag` pivot (phase-04 §2.15, alphabetical
 * singular_singular per CLAUDE.md §3).
 *
 * Composite primary key (`blog_post_id`, `blog_tag_id`), both sides `cascadeOnDelete`, no timestamps,
 * no soft deletes, no blameable (decision D19 link pivot). No delete guard: `BlogService::syncTags()`
 * detaches the tags a post no longer carries (§6.7 invariant 4).
 *
 * @property int $blog_post_id
 * @property int $blog_tag_id
 */
class BlogPostBlogTag extends Pivot
{
    protected $table = 'blog_post_blog_tag';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'blog_post_id',
        'blog_tag_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blog_post_id' => 'integer',
            'blog_tag_id' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'blog_posts';
    }

    /**
     * @return BelongsTo<BlogPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'blog_post_id');
    }

    /**
     * @return BelongsTo<BlogTag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(BlogTag::class, 'blog_tag_id');
    }
}
