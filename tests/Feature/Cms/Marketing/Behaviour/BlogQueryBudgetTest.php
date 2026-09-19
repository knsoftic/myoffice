<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 test 32 — the public blog index has no N+1.
 *
 * The page is measured with `DB::listen` on a cold public cache twice: once with a handful of posts and once
 * with 30 posts, each carrying a category and 5 tags. A page of cards that eager-loads its relations issues the
 * same number of queries whatever the number of rows; one query per card (or per tag) would grow with them.
 */
final class BlogQueryBudgetTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    /** Queries that may legitimately differ between the two measurements (none are expected). */
    private const TOLERANCE = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        $this->setSetting('website.blog_per_page', 12);
    }

    public function test_32_the_blog_index_query_count_does_not_grow_with_posts(): void
    {
        $this->seedPosts(3, 'Budget Small');

        $this->becomeGuest();
        $this->measure(); // warm every per-process memo (settings, modules, menus) before counting
        $small = $this->measure();

        $this->seedPosts(27, 'Budget Large');
        $large = $this->measure();

        $this->assertGreaterThan(0, $small['queries']);
        $this->assertStringContainsString('Budget Large', $large['html'], 'The larger measurement renders the new posts.');
        $this->assertLessThanOrEqual(
            $small['queries'] + self::TOLERANCE,
            $large['queries'],
            sprintf(
                "The blog index issued %d queries with 3 posts and %d with 30 posts (each with a category and 5 tags): the page has an N+1.\n%s",
                $small['queries'],
                $large['queries'],
                implode("\n", array_slice($large['sql'], 0, 80)),
            ),
        );
    }

    /**
     * @return array{queries: int, sql: list<string>, html: string}
     */
    private function measure(): array
    {
        $this->bumpPublicCache('test 32 measurement');

        $sql = [];
        $listening = true;

        DB::listen(static function (QueryExecuted $query) use (&$sql, &$listening): void {
            if ($listening) {
                $sql[] = $query->sql;
            }
        });

        $html = (string) $this->get(route('site.blog.index'))->assertOk()->getContent();

        $listening = false;

        return ['queries' => count($sql), 'sql' => $sql, 'html' => $html];
    }

    private function seedPosts(int $count, string $prefix): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $category = $this->insertBlogCategory($prefix.' Category '.$i);
            $tags = [];

            for ($t = 1; $t <= 5; $t++) {
                $tags[] = $this->insertBlogTag($prefix.' Tag '.$i.'-'.$t);
            }

            $this->insertPost($prefix.' Post '.$i, ['blog_category_id' => $category->getKey()], $tags);
        }
    }
}
