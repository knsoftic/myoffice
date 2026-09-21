<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\DataObjects\Institute\CatalogueQuery;
use App\Enums\CourseLevel;
use App\Services\Institute\PublicCourseService;
use App\Support\Institute\Sitemap\CourseCategorySitemapProvider;
use App\Support\Institute\Sitemap\CourseSitemapProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * The public catalogue and course page (§89–90, phase-14-17 §6.13, §7.10, §8.20).
 *
 * **Nothing unpublished is reachable, and every refusal is a 404.** A draft, an archived course, a
 * course in a switched-off category and a slug that was never real all answer the same way — a 403
 * would confirm to a stranger that the slug they guessed exists.
 *
 * **The outline shown is the active one.** A topic the institute has stopped teaching is not listed on
 * the page selling the course: that would be a promise it no longer keeps.
 */
final class PublicCatalogueTest extends TestCase
{
    use BuildsCatalogue;
    use InteractsWithRbac;
    use RefreshDatabase;

    private function publicCourses(): PublicCourseService
    {
        return app(PublicCourseService::class);
    }

    /*
    |--------------------------------------------------------------------------
    | What is on the site
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_catalogue_lists_published_courses_and_nothing_else(): void
    {
        $actor = $this->createSuperAdmin();

        $published = $this->publishedCourse(actor: $actor);
        $draft = $this->draftCourse(actor: $actor);
        $archived = $this->courseService()->archive(
            $this->publishedCourse(actor: $actor),
            'Retired for the season',
            $actor,
        );

        $response = $this->get(route('site.courses.index'));

        $response->assertOk();
        $response->assertSee($published->name);
        $response->assertDontSee($draft->name);
        $response->assertDontSee($archived->name);
    }

    #[Test]
    public function a_draft_an_archived_course_and_an_unknown_slug_all_answer_404(): void
    {
        $actor = $this->createSuperAdmin();

        $draft = $this->draftCourse(actor: $actor);
        $archived = $this->courseService()->archive($this->publishedCourse(actor: $actor), 'Retired', $actor);

        $this->get(route('site.courses.show', $draft->slug))->assertNotFound();
        $this->get(route('site.courses.show', $archived->fresh()->slug))->assertNotFound();
        $this->get(route('site.courses.show', 'a-slug-that-was-never-real'))->assertNotFound();
    }

    #[Test]
    public function switching_a_category_off_takes_its_courses_off_the_site(): void
    {
        $actor = $this->createSuperAdmin();
        $category = $this->courseCategory(actor: $actor);
        $course = $this->publishedCourse($category, actor: $actor);

        $this->get(route('site.courses.show', $course->slug))->assertOk();

        $this->categoryService()->setActive($category, false, false, $actor);

        // The course is still published — hiding the grouping changed no status (FT-02) — but the
        // page it is grouped under is gone, so the course is not on the site either.
        $this->assertSame('published', $course->fresh()->status->value);
        $this->get(route('site.courses.show', $course->slug))->assertNotFound();
        $this->get(route('site.courses.category', $category->slug))->assertNotFound();
    }

    #[Test]
    public function the_landing_page_shows_only_the_active_outline(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->draftCourse(actor: $actor);

        $kept = $this->outlineWith($course, $actor);
        $module = $this->firstModule($course);
        $dropped = $this->outlineService()->addTopic($module, ['title' => 'Topic we stopped teaching'], $actor);

        $this->courseService()->publish($course->refresh(), $actor);

        $this->get(route('site.courses.show', $course->fresh()->slug))
            ->assertOk()
            ->assertSee($kept->title)
            ->assertSee('Topic we stopped teaching');

        $this->outlineService()->setActive($dropped, false, $actor);

        $this->get(route('site.courses.show', $course->fresh()->slug))
            ->assertOk()
            ->assertSee($kept->title)
            ->assertDontSee('Topic we stopped teaching');
    }

    #[Test]
    public function editing_the_outline_of_a_published_course_reaches_the_site(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);
        $topic = $course->modules()->firstOrFail()->topics()->firstOrFail();

        // The page is now cached under the current version stamp; without an invalidation the rest of
        // this test would pass against a copy taken before the edit.
        $this->get(route('site.courses.show', $course->slug))->assertOk()->assertSee($topic->title);

        $this->outlineService()->updateTopic($topic, ['title' => 'Renamed in week two'], null, $actor);

        $this->get(route('site.courses.show', $course->slug))
            ->assertOk()
            ->assertSee('Renamed in week two')
            ->assertDontSee('Topic one');
    }

    #[Test]
    public function a_resource_added_to_a_published_syllabus_reaches_the_site(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);
        $topic = $course->modules()->firstOrFail()->topics()->firstOrFail();

        $this->get(route('site.courses.show', $course->slug))->assertOk()->assertDontSee('Recommended book');

        // Adding a resource recounts the TOPIC, which never reaches the course — the case the bump
        // would have missed if it rode on a recount instead of being its own step (D83).
        $this->outlineService()->addResource($topic, [
            'title' => 'Recommended book',
            'type' => 'link',
            'external_url' => 'https://example.test/book',
            'is_public' => true,
        ], null, $actor);

        $this->get(route('site.courses.show', $course->slug))->assertOk()->assertSee('Recommended book');
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_catalogue_filters_narrow_without_leaking_anything_unpublished(): void
    {
        $actor = $this->createSuperAdmin();

        $beginner = $this->publishedCourse(null, ['level' => 'beginner'], $actor);
        $advanced = $this->publishedCourse(null, ['level' => 'advanced'], $actor);

        $results = $this->publicCourses()->catalogue(new CatalogueQuery(level: CourseLevel::Beginner));

        $this->assertTrue($results->pluck('id')->contains($beginner->getKey()));
        $this->assertFalse($results->pluck('id')->contains($advanced->getKey()));
    }

    #[Test]
    public function the_filter_rail_offers_no_category_that_would_be_a_dead_end(): void
    {
        $actor = $this->createSuperAdmin();

        $withCourses = $this->courseCategory('Has courses', $actor);
        $this->publishedCourse($withCourses, actor: $actor);

        $empty = $this->courseCategory('Nothing published', $actor);
        $this->draftCourse($empty, actor: $actor);

        $offered = $this->publicCourses()->filterCategories()->pluck('id');

        $this->assertTrue($offered->contains($withCourses->getKey()));
        $this->assertFalse($offered->contains($empty->getKey()),
            'a category whose only course is a draft would be a click into an empty page');
    }

    /*
    |--------------------------------------------------------------------------
    | The links that carry the attribution
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_apply_link_is_built_by_the_service(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        $url = $this->publicCourses()->applyUrl($course->fresh());

        // Phase 15 owns the admission form; until it ships, Apply lands on the contact page carrying
        // the same parameters rather than on a route that does not exist.
        $this->assertStringContainsString('course='.$course->fresh()->slug, $url);
        $this->assertStringNotContainsString(' ', $url);
    }

    #[Test]
    public function the_whatsapp_link_names_the_course(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        $url = $this->publicCourses()->whatsappUrl($course->fresh());

        $this->assertStringStartsWith('https://wa.me/', $url);
        $this->assertStringContainsString(rawurlencode($course->fresh()->name), $url);
    }

    #[Test]
    public function the_apply_button_disappears_when_admissions_are_closed(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        $this->setting('institute.admission_open', true);
        $this->get(route('site.courses.show', $course->slug))->assertOk()->assertSee('Apply now');

        $this->setting('institute.admission_open', false);
        $this->get(route('site.courses.show', $course->slug))
            ->assertOk()
            ->assertDontSee('Apply now')
            ->assertSee('Admissions for this course are closed');
    }

    /*
    |--------------------------------------------------------------------------
    | The sitemap
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_sitemap_lists_only_what_a_crawler_should_index(): void
    {
        $actor = $this->createSuperAdmin();

        $listed = $this->publishedCourse(actor: $actor);
        $draft = $this->draftCourse(actor: $actor);

        $noindex = $this->publishedCourse(actor: $actor);
        $this->courseService()->update($noindex, ['is_indexable' => false], $actor);

        $urls = collect(iterator_to_array((new CourseSitemapProvider)->urls(), false))
            ->map(static fn ($entry): string => is_array($entry) ? (string) ($entry['loc'] ?? '') : (string) $entry->loc);

        $this->assertTrue($urls->contains(fn (string $u): bool => str_contains($u, $listed->fresh()->slug)));
        $this->assertFalse($urls->contains(fn (string $u): bool => str_contains($u, $draft->slug)));
        $this->assertFalse(
            $urls->contains(fn (string $u): bool => str_contains($u, $noindex->fresh()->slug)),
            'telling a crawler not to index a page and then listing it is two instructions that contradict each other',
        );
    }

    #[Test]
    public function an_empty_category_is_not_in_the_sitemap(): void
    {
        $actor = $this->createSuperAdmin();

        $full = $this->courseCategory('Full', $actor);
        $this->publishedCourse($full, actor: $actor);

        $empty = $this->courseCategory('Empty', $actor);

        $urls = collect(iterator_to_array((new CourseCategorySitemapProvider)->urls(), false))
            ->map(static fn ($entry): string => is_array($entry) ? (string) ($entry['loc'] ?? '') : (string) $entry->loc);

        $this->assertTrue($urls->contains(fn (string $u): bool => str_contains($u, $full->slug)));
        $this->assertFalse($urls->contains(fn (string $u): bool => str_contains($u, $empty->slug)));
    }
}
