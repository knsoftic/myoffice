<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Enums\Cms\ContentStatus;
use App\Models\Cms\BlogPost;
use App\Models\User;
use App\Notifications\Cms\PostPublishedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 tests 26-27 — `blog:publish-scheduled` (§6.7.3).
 *
 *  26. a post due a minute ago is published, a post due in an hour is left alone, a second run publishes nothing
 *      twice, `published_at` is never rewritten (the scheduled moment is the publish moment), and the author
 *      gets one `PostPublishedNotification`;
 *  27. after a three-hour scheduler outage one run publishes all four overdue posts.
 *
 * Draft and archived posts are never touched, and a scheduled post is not public before the command runs
 * (§9.2 — the two sources of truth never disagree).
 */
final class ScheduledPublishingTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();

        $this->author = $this->createUserWithPermissions(['blog_posts.view_any', 'blog_posts.view', 'blog_posts.create', 'blog_posts.edit', 'blog_posts.change_status']);
        $this->actingAs($this->author);
    }

    public function test_26_the_command_publishes_due_posts_exactly_once_and_notifies_the_author(): void
    {
        Notification::fake();

        $due = $this->scheduledPost('Due In One Minute', Carbon::now()->addMinute());
        $later = $this->scheduledPost('Due In Sixty Two Minutes', Carbon::now()->addMinutes(62));
        $draft = $this->blog()->store(['title' => 'Untouched Draft', 'content' => '<p>Still a draft.</p>'], null);

        $scheduledMoment = (string) DB::table('blog_posts')->where('id', $due->getKey())->value('published_at');

        $this->travel(2)->minutes();

        // Due, but not yet flipped: still private.
        $this->assertFalse(BlogPost::query()->public()->whereKey($due->getKey())->exists(), 'A scheduled row with a past moment is not public until the scheduler flips it.');

        $this->assertSame(0, Artisan::call('blog:publish-scheduled'));
        $this->assertStringContainsString('published 1 post(s)', Artisan::output());

        $this->assertSame(ContentStatus::Published, $due->fresh()->status);
        $this->assertSame($scheduledMoment, (string) DB::table('blog_posts')->where('id', $due->getKey())->value('published_at'), 'published_at is not rewritten.');
        $this->assertTrue(BlogPost::query()->public()->whereKey($due->getKey())->exists());

        $this->assertSame(ContentStatus::Scheduled, $later->fresh()->status, 'A post due in an hour is left alone.');
        $this->assertSame(ContentStatus::Draft, $draft->fresh()->status, 'A draft is never touched.');

        $this->assertSame(0, Artisan::call('blog:publish-scheduled'));
        $this->assertStringContainsString('published 0 post(s)', Artisan::output(), 'A second run publishes nothing twice.');
        $this->assertSame($scheduledMoment, (string) DB::table('blog_posts')->where('id', $due->getKey())->value('published_at'));

        Notification::assertSentToTimes($this->author, PostPublishedNotification::class, 1);
        Notification::assertSentTo(
            $this->author,
            PostPublishedNotification::class,
            fn (PostPublishedNotification $notification): bool => (int) $notification->post->getKey() === (int) $due->getKey(),
        );

        // A manual publish is not a scheduler publication and notifies nobody.
        $this->blog()->publish($later->fresh());
        Notification::assertSentToTimes($this->author, PostPublishedNotification::class, 1);
    }

    public function test_27_a_backlog_after_an_outage_is_published_in_one_pass(): void
    {
        Notification::fake();

        $posts = [];

        foreach ([10, 20, 30, 40] as $minutes) {
            $posts[] = $this->scheduledPost('Backlog Post '.$minutes, Carbon::now()->addMinutes($minutes));
        }

        $moments = array_map(
            static fn (BlogPost $post): string => (string) DB::table('blog_posts')->where('id', $post->getKey())->value('published_at'),
            $posts,
        );

        $this->travel(3)->hours();

        $this->assertSame(0, Artisan::call('blog:publish-scheduled'));
        $this->assertStringContainsString('published 4 post(s)', Artisan::output());

        foreach ($posts as $index => $post) {
            $this->assertSame(ContentStatus::Published, $post->fresh()->status);
            $this->assertSame($moments[$index], (string) DB::table('blog_posts')->where('id', $post->getKey())->value('published_at'));
        }

        $this->assertSame(0, Artisan::call('blog:publish-scheduled'));
        $this->assertStringContainsString('published 0 post(s)', Artisan::output());
        Notification::assertSentToTimes($this->author, PostPublishedNotification::class, 4);
    }

    private function scheduledPost(string $title, Carbon $at): BlogPost
    {
        $post = $this->blog()->store(['title' => $title, 'content' => '<p>'.$title.' body text.</p>'], null);

        $post = $this->blog()->schedule($post, $at);

        $this->assertSame(ContentStatus::Scheduled, $post->fresh()->status);

        return $post->fresh();
    }
}
