<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Enums\Cms\ContentStatus;
use App\Models\Cms\BlogPost;
use App\Models\Cms\BlogTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 tests 29 and 31 — related posts (§6.7.2) and tag de-duplication (§6.7 invariant 4).
 *
 *  29. `related()` fills same-category published posts first (newest first), then posts sharing tags (most shared
 *      first), never the post itself, never a draft / scheduled / trashed post, respects
 *      `website.blog_related_count`, and is an empty collection on a one-post site;
 *  31. `syncTags(['Laravel', 'laravel', ' LARAVEL '])` creates one tag and attaches it once; a trashed tag is
 *      restored rather than duplicated.
 */
final class RelatedPostsAndTagsTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        $this->actAsSuperAdmin();
    }

    public function test_29_related_posts_are_deterministic_tiers_of_published_posts(): void
    {
        $this->setSetting('website.blog_related_count', 3);

        $laravel = $this->insertBlogCategory('Laravel Articles');
        $design = $this->insertBlogCategory('Design Articles');
        $php = $this->insertBlogTag('PHP Tag');
        $apis = $this->insertBlogTag('APIs Tag');

        $hours = static fn (int $hours): Carbon => Carbon::now()->subHours($hours);

        $post = $this->insertPost('Subject Post', ['blog_category_id' => $laravel->getKey(), 'published_at' => $hours(1)], [$php, $apis]);

        $sameOld = $this->insertPost('Same Category Older', ['blog_category_id' => $laravel->getKey(), 'published_at' => $hours(30)]);
        $sameNew = $this->insertPost('Same Category Newer', ['blog_category_id' => $laravel->getKey(), 'published_at' => $hours(5)]);
        $this->insertPost('Same Category Draft', ['blog_category_id' => $laravel->getKey(), 'status' => ContentStatus::Draft->value, 'published_at' => null]);
        $this->insertPost('Same Category Scheduled', ['blog_category_id' => $laravel->getKey(), 'status' => ContentStatus::Scheduled->value, 'published_at' => Carbon::now()->addDay()]);
        $this->insertPost('Same Category Trashed', ['blog_category_id' => $laravel->getKey(), 'deleted_at' => Carbon::now()]);

        $sharesTwo = $this->insertPost('Shares Two Tags', ['blog_category_id' => $design->getKey(), 'published_at' => $hours(50)], [$php, $apis]);
        $sharesOne = $this->insertPost('Shares One Tag', ['blog_category_id' => $design->getKey(), 'published_at' => $hours(2)], [$php]);
        $this->insertPost('Unrelated Newest', ['blog_category_id' => $design->getKey(), 'published_at' => $hours(0)]);

        $related = $this->blog()->related($post->fresh());

        $this->assertSame(
            [(int) $sameNew->getKey(), (int) $sameOld->getKey(), (int) $sharesTwo->getKey()],
            $this->ids($related),
            'Same category (newest first), then the post sharing the most tags; the limit comes from website.blog_related_count.',
        );

        $this->assertNotContains((int) $post->getKey(), $this->ids($related), 'Never the post itself.');

        foreach ($related as $relatedPost) {
            $this->assertTrue($relatedPost->isPubliclyVisible(), sprintf('"%s" is not a published post.', $relatedPost->title));
            $this->assertTrue($relatedPost->relationLoaded('category') && $relatedPost->relationLoaded('tags'), 'Every tier eager-loads category and tags.');
        }

        // A thin category fills from shared tags, most shared first, then pads with the newest.
        $this->setSetting('website.blog_related_count', 5);
        $this->assertSame(
            [(int) $sameNew->getKey(), (int) $sameOld->getKey(), (int) $sharesTwo->getKey(), (int) $sharesOne->getKey()],
            array_slice($this->ids($this->blog()->related($post->fresh())), 0, 4),
        );

        $this->setSetting('website.blog_related_count', 0);
        $this->assertCount(0, $this->blog()->related($post->fresh()), 'A count of 0 hides the block.');

        // Deterministic: the same call gives the same answer.
        $this->setSetting('website.blog_related_count', 3);
        $this->assertSame($this->ids($this->blog()->related($post->fresh())), $this->ids($this->blog()->related($post->fresh())));
    }

    public function test_29_a_one_post_site_has_no_related_posts(): void
    {
        $this->setSetting('website.blog_related_count', 3);

        DB::table('blog_post_blog_tag')->delete();
        DB::table('blog_post_views')->delete();
        BlogPost::withTrashed()->get()->each(static fn (BlogPost $existing) => $existing->forceDelete());

        $only = $this->insertPost('The Only Post');

        $related = $this->blog()->related($only->fresh());

        $this->assertCount(0, $related);
        $this->assertTrue($related->isEmpty());
    }

    public function test_31_sync_tags_matches_by_slug_case_insensitively_and_restores_trashed_tags(): void
    {
        $post = $this->blog()->store(['title' => 'Tagging Is Case Blind', 'content' => '<p>Tags are matched by slug.</p>'], null);
        $tagsBefore = BlogTag::withTrashed()->count();

        $this->blog()->syncTags($post->fresh(), ['Laravel', 'laravel', ' LARAVEL ']);

        $this->assertSame($tagsBefore + 1, BlogTag::withTrashed()->count(), 'Exactly one tag is created.');
        $this->assertSame(1, DB::table('blog_post_blog_tag')->where('blog_post_id', $post->getKey())->count(), 'It is attached once.');

        $tag = BlogTag::query()->where('slug', 'laravel')->firstOrFail();
        $this->assertSame('Laravel', $tag->name, 'The name is stored as first typed.');

        // Re-syncing the same names changes nothing.
        $this->blog()->syncTags($post->fresh(), ['LARAVEL']);
        $this->assertSame($tagsBefore + 1, BlogTag::withTrashed()->count());
        $this->assertSame(1, DB::table('blog_post_blog_tag')->where('blog_post_id', $post->getKey())->count());

        // A trashed tag is restored, never duplicated.
        $tag->delete();
        $this->assertSoftDeleted('blog_tags', ['id' => $tag->getKey()]);

        $other = $this->blog()->store(['title' => 'Another Laravel Post', 'content' => '<p>Reusing a trashed tag.</p>'], null);
        $this->blog()->syncTags($other->fresh(), ['laravel']);

        $this->assertSame($tagsBefore + 1, BlogTag::withTrashed()->count());
        $this->assertNull($tag->fresh()->deleted_at, 'The trashed tag was restored.');
        $this->assertSame([(int) $tag->getKey()], DB::table('blog_post_blog_tag')->where('blog_post_id', $other->getKey())->pluck('blog_tag_id')->map('intval')->all());

        // An empty list detaches everything.
        $this->blog()->syncTags($post->fresh(), []);
        $this->assertSame(0, DB::table('blog_post_blog_tag')->where('blog_post_id', $post->getKey())->count());
    }

    /**
     * @param  iterable<BlogPost>  $posts
     * @return list<int>
     */
    private function ids(iterable $posts): array
    {
        $ids = [];

        foreach ($posts as $post) {
            $ids[] = (int) $post->getKey();
        }

        return $ids;
    }
}
