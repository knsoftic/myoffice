<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Jobs\Cms\RecordBlogPostView;
use App\Models\Cms\BlogPost;
use App\Models\User;
use App\Services\Cms\BlogViewCounter;
use App\Support\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 tests 21-24 — honest view counts (§2.17, §6.7.1).
 *
 *  21. `views_count == COUNT(blog_post_views)` after every scenario of 22-24;
 *  22. one visitor loading a post 5 times in a day is one row; another user agent is a second; the same visitor
 *      the next day is a third — through the real page (track + after-response job) and through `record()`;
 *  23. a `Sec-Purpose: prefetch` request and a request from a user who can edit the post add no row at all;
 *  24. two jobs racing for the same visitor/post/day insert one row; the loser does not throw and does not
 *      increment the counter.
 *
 * The raw IP is never stored: the row holds an HMAC of IP + user agent.
 */
final class BlogViewCounterTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    private const IP = '198.51.100.23';

    private const AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        $this->setSetting('website.blog_view_dedupe_minutes', 1440);
    }

    public function test_22_one_visitor_counts_once_per_day_per_user_agent_through_the_real_page(): void
    {
        $post = $this->insertPost('Counting Honest Views');
        $this->becomeGuest();

        for ($load = 1; $load <= 5; $load++) {
            $this->withServerVariables(['REMOTE_ADDR' => self::IP])
                ->withHeaders(['User-Agent' => self::AGENT])
                ->get(route('site.blog.show', ['blogPost' => $post->slug]))
                ->assertOk();
        }

        $this->assertSame(1, $this->rowsFor($post), 'Five loads in one day are one view row.');
        $this->assertCounterMatchesRows($post);

        $row = DB::table('blog_post_views')->where('blog_post_id', $post->getKey())->first();
        $this->assertSame(64, strlen((string) $row->visitor_hash));
        $this->assertStringNotContainsString(self::IP, json_encode($row), 'The raw IP is never written to blog_post_views.');
    }

    public function test_21_22_the_same_visitor_other_agent_and_next_day_each_count_once(): void
    {
        $post = $this->insertPost('Views Per Visitor Per Day');
        $counter = app(BlogViewCounter::class);

        $counted = 0;

        for ($load = 1; $load <= 5; $load++) {
            $counted += (int) $counter->record($post, $this->readerRequest($post));
        }

        $this->assertSame(1, $counted, 'Only the first of five loads counts.');
        $this->assertSame(1, $this->rowsFor($post));
        $this->assertCounterMatchesRows($post);

        $this->assertTrue($counter->record($post, $this->readerRequest($post, agent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) Mobile Safari/604.1')));
        $this->assertSame(2, $this->rowsFor($post), 'A different user agent is a different visitor.');
        $this->assertCounterMatchesRows($post);

        $this->travel(1)->days();

        $this->assertTrue($counter->record($post, $this->readerRequest($post)));
        $this->assertFalse($counter->record($post, $this->readerRequest($post)));
        $this->assertSame(3, $this->rowsFor($post), 'The same visitor the next day is a new view.');
        $this->assertCounterMatchesRows($post);
    }

    public function test_21_23_prefetches_and_editors_add_no_view(): void
    {
        $author = $this->createUserWithPermissions(['blog_posts.view_any', 'blog_posts.view', 'blog_posts.create', 'blog_posts.edit']);
        $editor = $this->createUserWithPermissions(['blog_posts.view_any', 'blog_posts.view', 'blog_posts.edit', 'blog_posts.approve']);
        $post = $this->insertPost('Prefetch And Preview Safe', ['author_id' => $author->getKey()]);
        $counter = app(BlogViewCounter::class);

        foreach (['Sec-Purpose' => 'prefetch;prerender', 'Purpose' => 'prefetch', 'X-Moz' => 'prefetch'] as $header => $value) {
            $this->assertFalse($counter->record($post, $this->readerRequest($post, headers: [$header => $value])), sprintf('%s: %s is not a view.', $header, $value));
        }

        $this->assertFalse($counter->record($post, $this->readerRequest($post, user: $author)), 'The author reading its own post is not a view.');
        $this->assertFalse($counter->record($post, $this->readerRequest($post, user: $editor)), 'An editor who can update the post is not a view.');
        $this->assertSame(0, $this->rowsFor($post));
        $this->assertCounterMatchesRows($post);

        // Through the real page too.
        $this->becomeGuest();
        $this->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->withHeaders(['User-Agent' => self::AGENT, 'Sec-Purpose' => 'prefetch'])
            ->get(route('site.blog.show', ['blogPost' => $post->slug]))
            ->assertOk();

        $this->actingAs($author)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->withHeaders(['User-Agent' => self::AGENT])
            ->get(route('site.blog.show', ['blogPost' => $post->slug]))
            ->assertOk();

        $this->assertSame(0, $this->rowsFor($post), 'Neither a prefetch nor the author adds a row.');
        $this->assertCounterMatchesRows($post);
    }

    public function test_21_24_concurrent_jobs_for_one_visitor_insert_one_row(): void
    {
        $post = $this->insertPost('Racing View Jobs');
        $counter = app(BlogViewCounter::class);

        $job = new RecordBlogPostView(postId: (int) $post->getKey(), ip: self::IP, userAgent: self::AGENT);
        $twin = new RecordBlogPostView(postId: (int) $post->getKey(), ip: self::IP, userAgent: self::AGENT);

        $job->handle($counter);
        $twin->handle($counter);

        $this->assertSame(1, $this->rowsFor($post), 'Two jobs for one visitor/post/day insert one row.');
        $this->assertSame(1, (int) DB::table('blog_posts')->where('id', $post->getKey())->value('views_count'), 'The loser does not increment.');
        $this->assertCounterMatchesRows($post);

        // The race in the other order: the winner's transaction (row + increment) lands first, then the loser runs.
        $second = $this->insertPost('Racing View Jobs Two');
        $request = $this->readerRequest($second);

        DB::transaction(static function () use ($second, $counter, $request): void {
            DB::table('blog_post_views')->insert([
                'blog_post_id' => $second->getKey(),
                'visitor_hash' => $counter->visitorHash($request),
                'viewed_on' => Carbon::now(Format::timezone())->toDateString(),
                'created_at' => Carbon::now(),
            ]);
            DB::table('blog_posts')->where('id', $second->getKey())->increment('views_count');
        });

        $this->assertFalse($counter->record($second, $request), 'The loser returns false and does not throw.');
        $this->assertSame(1, $this->rowsFor($second));
        $this->assertCounterMatchesRows($second);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function readerRequest(BlogPost $post, string $ip = self::IP, string $agent = self::AGENT, array $headers = [], ?User $user = null): Request
    {
        $server = ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $agent];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create('/blog/'.$post->slug, 'GET', [], [], [], $server);
        $request->setUserResolver(static fn () => $user);

        return $request;
    }

    private function rowsFor(BlogPost $post): int
    {
        return DB::table('blog_post_views')->where('blog_post_id', $post->getKey())->count();
    }

    /** §11 test 21. */
    private function assertCounterMatchesRows(BlogPost $post): void
    {
        $this->assertSame(
            $this->rowsFor($post),
            (int) DB::table('blog_posts')->where('id', $post->getKey())->value('views_count'),
            'views_count must always equal COUNT(blog_post_views).',
        );
    }
}
