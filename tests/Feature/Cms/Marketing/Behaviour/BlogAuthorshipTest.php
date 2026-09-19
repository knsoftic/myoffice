<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Models\Cms\BlogPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 test 30 — author / editor row scoping (§4 ability semantics, §9.1.1 `BlogPost::visibleTo()`).
 *
 * An author holding `blog_posts.create` + `.edit` edits its own post and is refused another author's (403), and
 * the index lists only its own rows. Granting `blog_posts.approve` (the editor ability) opens both posts and the
 * index count rises accordingly. No role name is involved: the permission decides.
 */
final class BlogAuthorshipTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    public function test_30_an_author_reaches_only_its_own_posts_until_it_becomes_an_editor(): void
    {
        $abilities = ['blog_posts.view_any', 'blog_posts.view', 'blog_posts.create', 'blog_posts.edit'];

        $author = $this->createUserWithPermissions($abilities);
        $colleague = $this->createUserWithPermissions($abilities);

        $own = $this->postBy($author, 'Author Own Draft Thirty');
        $theirs = $this->postBy($colleague, 'Colleague Draft Thirty');

        $this->assertSame((int) $author->getKey(), (int) $own->author_id, 'A post is owned by its creator.');
        $this->assertSame((int) $colleague->getKey(), (int) $theirs->author_id);

        // Scope.
        $this->assertSame([(int) $own->getKey()], $this->visibleIds($author));

        // Screens.
        $this->actingAs($author)->get(route('admin.blog-posts.edit', $own))->assertOk();
        $this->actingAs($author)->get(route('admin.blog-posts.edit', $theirs))->assertForbidden();

        $index = $this->actingAs($author)->get(route('admin.blog-posts.index'))->assertOk();
        $this->assertSame(1, $index->viewData('posts')->total());
        $index->assertSee('Author Own Draft Thirty')->assertDontSee('Colleague Draft Thirty');

        // The edit refused on screen is refused on write too: whatever the answer, the row does not change.
        $this->actingAs($author)
            ->put(route('admin.blog-posts.update', $theirs), ['title' => 'Hijacked Title', 'content' => '<p>Hijacked.</p>']);
        $this->assertSame('Colleague Draft Thirty', $theirs->fresh()->title, "Another author's post cannot be written.");

        // Becoming an editor.
        $this->grantPermissions($author, 'blog_posts.approve');
        $author = $author->fresh();

        $this->assertEqualsCanonicalizing([(int) $own->getKey(), (int) $theirs->getKey()], $this->visibleIds($author));

        $this->actingAs($author)->get(route('admin.blog-posts.edit', $own))->assertOk();
        $this->actingAs($author)->get(route('admin.blog-posts.edit', $theirs))->assertOk();

        $index = $this->actingAs($author)->get(route('admin.blog-posts.index'))->assertOk();
        $this->assertSame(2, $index->viewData('posts')->total(), 'The index count rises with the editor ability.');
        $index->assertSee('Author Own Draft Thirty')->assertSee('Colleague Draft Thirty');

        // The colleague is still only an author.
        $this->assertSame([(int) $theirs->getKey()], $this->visibleIds($colleague->fresh()));
    }

    private function postBy(User $user, string $title): BlogPost
    {
        $this->actingAs($user);

        return $this->blog()->store(['title' => $title, 'content' => '<p>'.$title.' content.</p>'], null)->fresh();
    }

    /**
     * @return list<int>
     */
    private function visibleIds(User $user): array
    {
        $this->forgetPermissionCache();

        return BlogPost::query()->visibleTo($user)->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }
}
