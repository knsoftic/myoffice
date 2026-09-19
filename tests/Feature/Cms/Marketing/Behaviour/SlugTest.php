<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Enums\Cms\ContentStatus;
use App\Models\Cms\Service;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Support\SlugGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 tests 2-6 — permalinks (§6.1 `SlugGenerator` + `HasSlug`).
 *
 *   2. two services named alike get `-2`;
 *   3. a soft-deleted slug stays reserved (`withTrashed`), so the third is `-3` and the unique index never fires;
 *   4. a hand-entered reserved or numeric slug is refused on `slug` (the service-side net behind the Form Request);
 *   5. a source that normalises to nothing falls back to `{model}-{next id}` and saves;
 *   6. changing an already-published post's slug writes `slug_changed` with the old and new value.
 */
final class SlugTest extends TestCase
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

    public function test_02_two_services_with_the_same_name_get_a_numeric_suffix(): void
    {
        $first = $this->makeService('Web Development');
        $second = $this->makeService('Web Development');

        $this->assertSame('web-development', $first->slug);
        $this->assertSame('web-development-2', $second->slug);
        $this->assertSame(2, DB::table('services')->where('slug', 'like', 'web-development%')->count());
    }

    public function test_03_a_soft_deleted_slug_stays_reserved(): void
    {
        $first = $this->makeService('Web Development');
        $this->makeService('Web Development');

        $this->serviceContent()->delete($first);

        $this->assertSoftDeleted('services', ['id' => $first->getKey(), 'slug' => 'web-development']);

        $third = $this->makeService('Web Development');

        $this->assertSame('web-development-3', $third->slug, 'The trashed permalink is walked past, never reused.');
        $this->assertSame(
            ['web-development', 'web-development-2', 'web-development-3'],
            Service::withTrashed()->where('slug', 'like', 'web-development%')->orderBy('id')->pluck('slug')->all(),
        );
    }

    public function test_04_a_reserved_or_purely_numeric_slug_is_refused_on_the_slug_field(): void
    {
        foreach (['admin', 'blog', '0'] as $slug) {
            $this->assertTrue(SlugGenerator::isReserved($slug), sprintf('"%s" is reserved or numeric.', $slug));

            $before = Service::withTrashed()->count();

            try {
                $this->makeService('Reserved '.$slug, ['slug' => $slug]);
                $this->fail(sprintf('The slug "%s" must be refused.', $slug));
            } catch (ContentRuleException $exception) {
                $this->assertSame(422, $exception->status);
                $this->assertArrayHasKey('slug', $exception->errors(), 'The refusal is a field error on slug.');
            }

            $this->assertSame($before, Service::withTrashed()->count(), 'A refused slug stores nothing.');
        }

        // A generated slug never lands on a reserved word either: it is suffixed instead.
        $this->assertSame('blog-2', $this->makeService('Blog')->slug);
    }

    public function test_05_an_emoji_only_title_gets_a_non_empty_fallback_slug(): void
    {
        $next = (int) DB::table('services')->max('id') + 1;

        $service = $this->makeService("\u{1F680}\u{1F525}\u{2728}");

        $this->assertNotSame('', (string) $service->slug);
        $this->assertSame('service-'.$next, $service->slug, 'The fallback is the model name plus the next id.');
        $this->assertDatabaseHas('services', ['id' => $service->getKey(), 'slug' => 'service-'.$next]);
    }

    public function test_06_changing_a_published_posts_slug_writes_a_slug_changed_entry(): void
    {
        $post = $this->blog()->store([
            'title' => 'Launching Our New Platform',
            'content' => '<p>We are proud to launch the new platform today.</p>',
        ], null);

        $post = $this->blog()->publish($post);
        $oldSlug = (string) $post->slug;
        $this->assertSame(ContentStatus::Published, $post->fresh()->status);

        $marker = $this->lastActivityId();

        $this->blog()->update($post->fresh(), ['slug' => 'platform-launch-announcement'], null, []);

        $this->assertSame('platform-launch-announcement', $post->fresh()->slug);

        $entries = $this->activitiesSince($marker, 'blog_posts', 'slug_changed');
        $this->assertCount(1, $entries, 'One slug_changed entry.');

        $properties = $this->propertiesOf($entries->first());
        $this->assertSame($oldSlug, $properties['old']['slug'] ?? null);
        $this->assertSame('platform-launch-announcement', $properties['attributes']['slug'] ?? null);
        $this->assertTrue((bool) ($properties['was_published'] ?? false), 'The entry records that the post was already live.');
        $this->assertSame((int) $post->getKey(), (int) $entries->first()->subject_id);
    }
}
