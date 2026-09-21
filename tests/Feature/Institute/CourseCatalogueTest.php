<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Enums\CourseStatus;
use App\Models\Institute\Course;
use App\Models\Institute\CourseCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * Categories and the course lifecycle (§62, §64, phase-14-17 §2.30.1, §6.1, §6.2) — FT-01 to FT-06, FT-10.
 *
 * The four things worth proving first. **Reordering writes any permutation** and rejects a foreign id
 * without moving anything. **Switching a category off never touches a course** unless somebody asks.
 * **A course cannot be published with a gap in it**, and the refusal names the gap. And **money is a
 * separate permission** — a reader without it sees no fee on the screen, and a fee they POST is
 * ignored rather than trusted.
 */
final class CourseCatalogueTest extends TestCase
{
    use BuildsCatalogue;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function category_reorder_accepts_any_permutation(): void
    {
        $actor = $this->createSuperAdmin();

        $ids = collect(range(1, 5))
            ->map(fn (int $i): CourseCategory => $this->courseCategory('Category '.$i, $actor))
            ->pluck('id')
            ->all();

        $reversed = array_reverse($ids);

        $this->categoryService()->reorder($reversed, $actor);

        $this->assertSame(
            $reversed,
            CourseCategory::query()->whereKey($ids)->ordered()->pluck('id')->all(),
            'a whole new order is writable in one go — which is why sort_order carries no unique index',
        );
    }

    #[Test]
    public function a_reorder_naming_an_unknown_id_moves_nothing(): void
    {
        $actor = $this->createSuperAdmin();

        $a = $this->courseCategory('First', $actor);
        $b = $this->courseCategory('Second', $actor);

        $before = CourseCategory::query()->whereKey([$a->id, $b->id])->ordered()->pluck('id')->all();

        $this->expectExceptionMessageMatches('/do not belong to the category list/');

        try {
            $this->categoryService()->reorder([$b->id, 987654321], $actor);
        } finally {
            $this->assertSame(
                $before,
                CourseCategory::query()->whereKey([$a->id, $b->id])->ordered()->pluck('id')->all(),
                'a payload with one foreign id reorders nothing at all, not merely the rows it could',
            );
        }
    }

    #[Test]
    public function disabling_a_category_does_not_touch_its_courses(): void
    {
        $actor = $this->createSuperAdmin();
        $category = $this->courseCategory(actor: $actor);

        $published = $this->publishedCourse($category, actor: $actor);
        $draft = $this->draftCourse($category, actor: $actor);

        $this->categoryService()->setActive($category, false, false, $actor);

        $this->assertFalse($category->fresh()->is_active);
        $this->assertSame(CourseStatus::Published, $published->fresh()->status,
            'hiding a grouping and retiring what is inside it are two decisions');
        $this->assertSame(CourseStatus::Draft, $draft->fresh()->status);
    }

    #[Test]
    public function the_cascade_moves_published_courses_to_draft_when_it_is_asked_for(): void
    {
        $actor = $this->createSuperAdmin();
        $category = $this->courseCategory(actor: $actor);

        $published = $this->publishedCourse($category, actor: $actor);

        $this->categoryService()->setActive($category, false, true, $actor);

        $this->assertSame(CourseStatus::Draft, $published->fresh()->status);
    }

    #[Test]
    public function a_category_with_courses_cannot_be_deleted(): void
    {
        $actor = $this->createSuperAdmin();
        $category = $this->courseCategory(actor: $actor);
        $this->draftCourse($category, actor: $actor);

        try {
            $this->categoryService()->delete($category);
            $this->fail('a category holding a course was deleted');
        } catch (\Throwable $e) {
            $this->assertMatchesRegularExpression('/still holds 1 course/', collect($e->errors())->flatten()->first());
        }

        $this->assertDatabaseHas('course_categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    /*
    |--------------------------------------------------------------------------
    | The course lifecycle
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function publishing_requires_completeness_and_names_what_is_missing(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->draftCourse(actor: $actor);

        try {
            $this->courseService()->publish($course, $actor);
            $this->fail('a course with no module was published');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('at least one module', collect($e->errors())->flatten()->first());
        }

        $this->assertSame(CourseStatus::Draft, $course->fresh()->status);

        $this->outlineWith($course, $actor);

        $published = $this->courseService()->publish($course->refresh(), $actor);

        $this->assertSame(CourseStatus::Published, $published->status);
        $this->assertNotNull($published->published_at);
    }

    #[Test]
    public function publishing_is_refused_without_a_fee(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->draftCourse(null, ['course_fee' => '0.00'], $actor);
        $this->outlineWith($course, $actor);

        $this->expectExceptionMessageMatches('/course fee/');

        $this->courseService()->publish($course->refresh(), $actor);
    }

    #[Test]
    public function an_archived_course_cannot_go_straight_back_to_the_site(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        $archived = $this->courseService()->archive($course, 'Replaced by the 2027 syllabus', $actor);
        $this->assertSame(CourseStatus::Archived, $archived->status);

        try {
            $this->courseService()->publish($archived->refresh(), $actor);
            $this->fail('archived went straight to published');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('cannot go from Archived to Published', collect($e->errors())->flatten()->first());
        }

        // The way back is through draft, and it takes a reason.
        $revived = $this->courseService()->revive($archived->refresh(), 'The demand came back', $actor);
        $this->assertSame(CourseStatus::Draft, $revived->status);
    }

    #[Test]
    public function archiving_demands_a_reason(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        $this->expectExceptionMessageMatches('/Say why/');

        $this->courseService()->archive($course, '   ', $actor);
    }

    #[Test]
    public function a_code_is_unique_and_a_published_slug_does_not_move_without_a_reason(): void
    {
        $actor = $this->createSuperAdmin();
        $first = $this->publishedCourse(actor: $actor);

        try {
            $this->draftCourse(null, ['code' => $first->code], $actor);
            $this->fail('a duplicate code was accepted');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('already belongs to another course', collect($e->errors())->flatten()->first());
        }

        $originalSlug = $first->slug;

        try {
            $this->courseService()->update($first, ['slug' => 'a-different-address'], $actor);
            $this->fail('a published slug moved with no reason given');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('bookmarks', collect($e->errors())->flatten()->first());
        }

        $this->assertSame($originalSlug, $first->fresh()->slug);

        $moved = $this->courseService()->update($first->fresh(), [
            'slug' => 'a-different-address',
            'slug_change_reason' => 'The course was renamed for the new syllabus',
        ], $actor);

        $this->assertSame('a-different-address', $moved->slug);
    }

    #[Test]
    public function a_draft_slug_moves_freely(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->draftCourse(actor: $actor);

        $moved = $this->courseService()->update($course, ['slug' => 'renamed-while-draft'], $actor);

        $this->assertSame('renamed-while-draft', $moved->slug,
            'nothing is linking to a draft, so its address is not a promise yet');
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicating
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function duplicating_copies_the_tree_and_no_student_data(): void
    {
        $actor = $this->createSuperAdmin();
        $original = $this->publishedCourse(actor: $actor);

        $copy = $this->courseService()->duplicate($original->refresh(), [], $actor);

        $this->assertSame($original->modules_count, $copy->modules_count);
        $this->assertSame($original->topics_count, $copy->topics_count);
        $this->assertSame($original->lectures_count, $copy->lectures_count);
        $this->assertSame(
            $original->resources()->count(),
            $copy->resources()->count(),
        );
        $this->assertSame(
            $original->assignmentBlueprints()->count(),
            $copy->assignmentBlueprints()->count(),
        );

        $this->assertSame(CourseStatus::Draft, $copy->status, 'a copy is never born published');
        $this->assertNull($copy->published_at);
        $this->assertFalse($copy->is_featured, 'featured is a decision about one course, not a property to inherit');
        $this->assertNotSame($original->code, $copy->code);
        $this->assertNotSame($original->slug, $copy->slug);

        // Nothing that was ever sold comes across — there is nothing to copy it from.
        foreach (['batches', 'student_admissions', 'student_batch_enrollments'] as $table) {
            if ($this->app['db']->getSchemaBuilder()->hasTable($table)) {
                $this->assertDatabaseMissing($table, ['course_id' => $copy->getKey()]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Money is a separate permission (FT-10)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_course_list_shows_no_fee_without_view_financial(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(null, ['course_fee' => '44444.00'], $actor);

        $reader = $this->createUserWithPermissions(['courses.view_any', 'courses.view']);

        $response = $this->actingAs($reader)->get(route('admin.courses.index'));

        $response->assertOk();
        $response->assertSee($course->name);
        // Absent, not blank: the column header is not rendered because there is no cell to fill.
        $response->assertDontSee('Course fee</th>', false);
        $response->assertDontSee('44,444', false);
    }

    #[Test]
    public function a_posted_fee_is_ignored_from_somebody_who_may_not_see_one(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->draftCourse(null, ['course_fee' => '30000.00'], $actor);

        $editor = $this->createUserWithPermissions([
            'courses.view_any', 'courses.view', 'courses.edit',
        ]);

        $this->actingAs($editor)->put(route('admin.courses.update', $course), [
            'course_category_id' => $course->course_category_id,
            'code' => $course->code,
            'name' => 'Renamed by somebody who cannot see fees',
            'level' => 'beginner',
            'delivery_mode' => 'physical',
            'duration_unit' => 'weeks',
            // The crafted part: a tab this user never saw.
            'course_fee' => '1.00',
            'admission_fee' => '1.00',
            'registration_fee' => '1.00',
        ])->assertRedirect();

        $fresh = $course->fresh();

        $this->assertSame('Renamed by somebody who cannot see fees', $fresh->name, 'the edit they may make went through');
        $this->assertSame('30000.00', (string) $fresh->course_fee, 'and the price they may not see did not');
        $this->assertSame('2000.00', (string) $fresh->admission_fee);
        $this->assertSame('1000.00', (string) $fresh->registration_fee);
    }

    #[Test]
    public function the_export_carries_the_same_columns_the_screen_does(): void
    {
        $actor = $this->createSuperAdmin();
        $this->publishedCourse(null, ['course_fee' => '44444.00'], $actor);

        $reader = $this->createUserWithPermissions([
            'courses.view_any', 'courses.view', 'courses.export',
        ]);

        $response = $this->actingAs($reader)->get(route('admin.courses.export', ['format' => 'csv']));
        $response->assertOk();

        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();

        $this->assertStringNotContainsString('Course fee', $csv);
        $this->assertStringNotContainsString('44444.00', $csv);
    }

    /*
    |--------------------------------------------------------------------------
    | Deleting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_archived_course_is_not_deletable(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);
        $archived = $this->courseService()->archive($course, 'Retired for the season', $actor);

        $deleter = $this->createUserWithPermissions([
            'courses.view_any', 'courses.view', 'courses.delete',
        ]);

        $this->assertFalse($deleter->can('delete', $archived->fresh()),
            'archiving is how a course is retired — deleting it would take its history with it');

        $draft = $this->draftCourse(actor: $actor);
        $this->assertTrue($deleter->can('delete', $draft), 'a draft nothing was sold against is removable');
    }

    #[Test]
    public function effective_admission_open_needs_the_institute_switch_too(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        $this->setting('institute.admission_open', true);
        $this->assertTrue($this->courseService()->effectiveAdmissionOpen($course->fresh()));

        $this->setting('institute.admission_open', false);
        $this->assertFalse($this->courseService()->effectiveAdmissionOpen($course->fresh()),
            'one switch for the institute, one per course, and both have to be on');

        $this->setting('institute.admission_open', true);
        $this->courseService()->update($course, ['admission_open' => false], $actor);
        $this->assertFalse($this->courseService()->effectiveAdmissionOpen($course->fresh()));
    }
}
