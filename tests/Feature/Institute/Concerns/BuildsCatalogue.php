<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Concerns;

use App\Models\Institute\Course;
use App\Models\Institute\CourseCategory;
use App\Models\Institute\CourseModule;
use App\Models\Institute\CourseTopic;
use App\Models\User;
use App\Services\Institute\CourseCategoryService;
use App\Services\Institute\CourseOutlineService;
use App\Services\Institute\CourseService;

/**
 * Fixtures for the Phase 14 acceptance suite.
 *
 * Everything goes through the service that owns it — no direct inserts into `courses` or the outline
 * tables. A fixture that wrote a row directly would be testing a shape the application never produces,
 * and the first thing it would stop catching is a service forgetting to set `course_id` from the
 * parent (INV-I12).
 */
trait BuildsCatalogue
{
    private int $catalogueSequence = 0;

    protected function categoryService(): CourseCategoryService
    {
        return app(CourseCategoryService::class);
    }

    protected function courseService(): CourseService
    {
        return app(CourseService::class);
    }

    protected function outlineService(): CourseOutlineService
    {
        return app(CourseOutlineService::class);
    }

    protected function courseCategory(string $name = 'Web Development', ?User $actor = null): CourseCategory
    {
        $this->catalogueSequence++;

        return $this->categoryService()->create([
            'name' => $name.' '.$this->catalogueSequence,
        ], $actor);
    }

    /**
     * A draft course with a fee and no outline — the state publishing is supposed to refuse.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function draftCourse(?CourseCategory $category = null, array $overrides = [], ?User $actor = null): Course
    {
        $this->catalogueSequence++;
        $category ??= $this->courseCategory(actor: $actor);

        return $this->courseService()->create(array_merge([
            'course_category_id' => (int) $category->getKey(),
            'code' => 'TST-'.str_pad((string) $this->catalogueSequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Test course '.$this->catalogueSequence,
            'course_fee' => '30000.00',
            'admission_fee' => '2000.00',
            'registration_fee' => '1000.00',
            'level' => 'beginner',
            'delivery_mode' => 'physical',
            'duration_unit' => 'weeks',
            'duration_value' => 8,
        ], $overrides), $actor);
    }

    /**
     * A course complete enough to publish, and published.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function publishedCourse(?CourseCategory $category = null, array $overrides = [], ?User $actor = null): Course
    {
        $course = $this->draftCourse($category, $overrides, $actor);

        $this->outlineWith($course, $actor);

        return $this->courseService()->publish($course->refresh(), $actor);
    }

    /**
     * One module, one topic, one lecture — the smallest tree worth asserting about.
     */
    protected function outlineWith(Course $course, ?User $actor = null): CourseTopic
    {
        $module = $this->outlineService()->addModule($course, ['title' => 'Module one'], $actor);
        $topic = $this->outlineService()->addTopic($module, ['title' => 'Topic one', 'weight' => 2], $actor);

        $this->outlineService()->addLecture($topic, [
            'title' => 'Lecture one',
            'duration_minutes' => 60,
        ], $actor);

        $this->outlineService()->addResource($topic, [
            'title' => 'Reading',
            'type' => 'link',
            'external_url' => 'https://example.test/reading',
            'is_public' => true,
        ], null, $actor);

        $this->outlineService()->addAssignmentBlueprint($topic, [
            'title' => 'Practice',
            'estimated_marks' => '10.00',
        ], $actor);

        return $topic->refresh();
    }

    protected function firstModule(Course $course): CourseModule
    {
        return $course->modules()->firstOrFail();
    }

    /**
     * Write a setting the way the system context does.
     */
    protected function setting(string $key, mixed $value): void
    {
        settings_repo()->asSystem(fn ($settings) => $settings->set($key, $value));
    }
}
