<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Models\Institute\ClassSession;
use App\Models\Institute\CourseLecture;
use App\Models\Institute\CourseTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\TestCase;

/**
 * The three-level outline (§65, phase-14-17 §6.3) — FT-07, FT-08, FT-09.
 *
 * **INV-I12 is a schema fact, not a convention**: a lecture has no column it could use to hang off a
 * module, and every parent id is read from the parent rather than from the request. The first test
 * proves the structure; the rest prove the service refuses to take the client's word about which tree
 * it is editing.
 *
 * **§111 is checked on content**: a `.php` renamed `.pdf` announces `application/pdf` in the request
 * and something else entirely to `finfo`, and it is the second answer that decides.
 */
final class CourseOutlineTest extends TestCase
{
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | The tree is exactly three levels (FT-07)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_outline_has_no_column_that_could_skip_a_level(): void
    {
        $lectureColumns = $this->app['db']->getSchemaBuilder()->getColumnListing('course_lectures');

        $this->assertNotContains('course_module_id', $lectureColumns,
            'a lecture that could point at a module would make the tree two levels deep in places and three in others');

        foreach (['course_modules', 'course_topics', 'course_lectures'] as $table) {
            $this->assertNotContains(
                'parent_id',
                $this->app['db']->getSchemaBuilder()->getColumnListing($table),
                $table.' has a parent_id — the tree is supposed to be flat at three levels (INV-I12)',
            );
        }
    }

    #[Test]
    public function a_child_takes_its_course_from_its_parent_not_from_the_request(): void
    {
        $actor = $this->createSuperAdmin();

        $courseA = $this->draftCourse(actor: $actor);
        $courseB = $this->draftCourse(actor: $actor);

        $moduleA = $this->outlineService()->addModule($courseA, ['title' => 'A'], $actor);

        // The request tries to claim the other course. The service never reads it.
        $topic = $this->outlineService()->addTopic($moduleA, [
            'title' => 'Topic',
            'course_id' => $courseB->getKey(),
            'course_module_id' => 999999,
        ], $actor);

        $this->assertSame((int) $courseA->getKey(), (int) $topic->course_id);
        $this->assertSame((int) $moduleA->getKey(), (int) $topic->course_module_id);

        $lecture = $this->outlineService()->addLecture($topic, [
            'title' => 'Lecture',
            'course_id' => $courseB->getKey(),
            'course_topic_id' => 999999,
        ], $actor);

        $this->assertSame((int) $courseA->getKey(), (int) $lecture->course_id);
        $this->assertSame((int) $topic->getKey(), (int) $lecture->course_topic_id);
    }

    #[Test]
    public function a_reorder_naming_another_courses_ids_moves_nothing(): void
    {
        $actor = $this->createSuperAdmin();

        $courseA = $this->draftCourse(actor: $actor);
        $courseB = $this->draftCourse(actor: $actor);

        $topicA = $this->outlineWith($courseA, $actor);
        $topicB = $this->outlineWith($courseB, $actor);

        $moduleB = $this->firstModule($courseB);
        $before = CourseTopic::query()->whereKey($topicB->getKey())->value('sort_order');

        try {
            // Course A's editor, course B's module and topic.
            $this->outlineService()->reorder($courseA, 'topic', (int) $moduleB->getKey(), [$topicB->getKey()], $actor);
            $this->fail('a reorder crossed courses');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('does not belong to', collect($e->errors())->flatten()->first());
        }

        $this->assertSame(
            $before,
            CourseTopic::query()->whereKey($topicB->getKey())->value('sort_order'),
            'nothing at all moved, not merely the rows that could not',
        );
    }

    #[Test]
    public function a_topic_cannot_move_to_another_courses_module(): void
    {
        $actor = $this->createSuperAdmin();

        $courseA = $this->draftCourse(actor: $actor);
        $courseB = $this->draftCourse(actor: $actor);

        $topicA = $this->outlineWith($courseA, $actor);
        $this->outlineWith($courseB, $actor);

        $this->expectExceptionMessageMatches('/only move between modules of its own course/');

        $this->outlineService()->moveTopic($topicA, $this->firstModule($courseB), null, $actor);
    }

    #[Test]
    public function a_topic_moves_freely_within_its_own_course(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->draftCourse(actor: $actor);

        $topic = $this->outlineWith($course, $actor);
        $first = $this->firstModule($course);
        $second = $this->outlineService()->addModule($course, ['title' => 'Module two'], $actor);

        $moved = $this->outlineService()->moveTopic($topic, $second, null, $actor);

        $this->assertSame((int) $second->getKey(), (int) $moved->course_module_id);
        $this->assertSame(0, (int) $first->fresh()->topics_count, 'the source module recounted');
        $this->assertSame(1, (int) $second->fresh()->topics_count, 'and so did the destination');
    }

    /*
    |--------------------------------------------------------------------------
    | Deactivate rather than delete (FT-08)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_node_can_be_switched_off_and_keeps_its_history(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->draftCourse(actor: $actor);
        $topic = $this->outlineWith($course, $actor);

        $this->outlineService()->setActive($topic, false, $actor);

        $fresh = $topic->fresh();

        $this->assertFalse($fresh->is_active);
        $this->assertNull($fresh->deleted_at, 'switching off is not deleting: the row and its history stay');
    }

    #[Test]
    public function an_unreferenced_node_is_deletable_and_the_caches_follow(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->draftCourse(actor: $actor);
        $topic = $this->outlineWith($course, $actor);

        $this->assertSame(1, (int) $course->fresh()->topics_count);

        $this->outlineService()->delete($topic, $actor);

        $this->assertSoftDeleted('course_topics', ['id' => $topic->getKey()]);
        $this->assertSame(0, (int) $course->fresh()->topics_count, 'the course cache was rewritten from the tree');
        $this->assertSame(0, (int) $this->firstModule($course)->fresh()->topics_count);
    }

    #[Test]
    public function the_policy_refuses_to_delete_a_topic_a_session_has_taught(): void
    {
        // Phase 14 wrote this against a table that did not exist yet; Phase 16 built it, so the class
        // is now a real one — generated from a real timetable slot on a real batch, which is the only
        // way a class ever comes to carry a topic.
        $actor = $this->createSuperAdmin();
        $course = $this->draftCourse(actor: $actor);
        $topic = $this->outlineWith($course, $actor);

        $batch = $this->batch($course, [], $actor);
        $this->slot($batch, actor: $actor);

        $session = ClassSession::query()
            ->where('batch_id', $batch->getKey())
            ->orderBy('session_date')
            ->firstOrFail();

        $session->forceFill(['course_topic_id' => $topic->getKey()])->save();

        $this->assertTrue($topic->fresh()->isReferenced());

        // Asked of somebody the policy actually runs for: `Gate::before` allows a Super Admin
        // everything, so asking them would test the short-circuit rather than the rule.
        $editor = $this->createUserWithPermissions([
            'course_outline.view_any', 'course_outline.view', 'course_outline.edit', 'course_outline.delete',
        ]);

        $this->assertFalse($editor->can('delete', $topic->fresh()),
            'a topic a class has taught is what the syllabus report points at');
    }

    /*
    |--------------------------------------------------------------------------
    | Uploads are checked on content (FT-09)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_php_file_renamed_pdf_is_refused(): void
    {
        Storage::fake('local');

        $actor = $this->createSuperAdmin();
        $course = $this->draftCourse(actor: $actor);
        $topic = $this->outlineWith($course, $actor);

        // A **real** temp file, not `UploadedFile::fake()`: the fake reports a MIME guessed from its
        // own name, which is exactly the thing this rule refuses to trust. The bytes below are PHP and
        // the name says PDF, and `finfo` is what decides.
        $path = tempnam(sys_get_temp_dir(), 'probe').'.pdf';
        file_put_contents($path, "<?php echo 'hello'; ?>
");

        $file = new UploadedFile($path, 'notes.pdf', 'application/pdf', null, true);

        $caught = null;

        try {
            $this->outlineService()->addResource($topic, [
                'title' => 'Sneaky',
                'type' => 'pdf',
            ], $file, $actor);
        } catch (ValidationException $e) {
            $caught = $e;
        } finally {
            @unlink($path);
        }

        $this->assertNotNull($caught, 'a PHP file was accepted as a PDF');
        $this->assertStringContainsString(
            'Renaming a file does not change what it is',
            collect($caught->errors())->flatten()->first(),
        );

        $this->assertDatabaseMissing('course_topic_resources', ['title' => 'Sneaky']);
    }

    #[Test]
    public function a_url_only_resource_is_accepted_and_one_pointing_at_nothing_is_not(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->draftCourse(actor: $actor);
        $topic = $this->outlineWith($course, $actor);

        $resource = $this->outlineService()->addResource($topic, [
            'title' => 'Further reading',
            'type' => 'link',
            'external_url' => 'https://example.test/further',
        ], null, $actor);

        $this->assertNull($resource->file_path);
        $this->assertSame('https://example.test/further', $resource->external_url);

        $this->expectExceptionMessageMatches('/points at something/');

        $this->outlineService()->addResource($topic, ['title' => 'Nowhere', 'type' => 'link'], null, $actor);
    }

    #[Test]
    public function an_uploaded_file_is_stored_under_a_hashed_name(): void
    {
        Storage::fake('local');

        $actor = $this->createSuperAdmin();
        $course = $this->draftCourse(actor: $actor);
        $topic = $this->outlineWith($course, $actor);

        $file = UploadedFile::fake()->image('my holiday photo.png', 10, 10);

        $resource = $this->outlineService()->addResource($topic, [
            'title' => 'Diagram',
            'type' => 'image',
        ], $file, $actor);

        $this->assertNotNull($resource->file_path);
        $this->assertStringNotContainsString('holiday', $resource->file_path,
            'nothing the uploader chose reaches the disk — a crafted filename cannot become a path');
        $this->assertStringStartsWith('courses/'.$course->getKey().'/resources/', $resource->file_path);
        $this->assertSame('image/png', $resource->mime_type, 'the verdict is stored, not the upload\'s own claim');
        $this->assertGreaterThan(0, (int) $resource->file_size);

        Storage::disk('local')->assertExists($resource->file_path);
        Storage::disk('public')->assertMissing($resource->file_path);
    }

    /*
    |--------------------------------------------------------------------------
    | A syllabus file is private, and is served by the application (D85)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_admin_download_is_the_only_way_to_a_syllabus_file(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $actor = $this->createSuperAdmin();
        $course = $this->draftCourse(actor: $actor);
        $topic = $this->outlineWith($course, $actor);

        $resource = $this->outlineService()->addResource($topic, [
            'title' => 'Slides',
            'type' => 'image',
        ], UploadedFile::fake()->image('deck.png', 10, 10), $actor);

        // Nothing on the public disk: an address the web server serves has no permission in front of
        // it, and it would outlive hiding the resource, deleting it, and the person shown it.
        Storage::disk('public')->assertMissing($resource->file_path);
        Storage::disk('local')->assertExists($resource->file_path);

        $stranger = $this->createUserWithPermissions(['course_outline.view']);

        $this->actingAs($stranger)
            ->get(route('admin.course-resources.download', $resource))
            ->assertForbidden();

        $reader = $this->createUserWithPermissions(['course_outline.view', 'course_outline.download']);

        $this->actingAs($reader)
            ->get(route('admin.course-resources.download', $resource))
            ->assertOk();
    }

    #[Test]
    public function the_public_download_answers_only_for_a_public_downloadable_resource(): void
    {
        Storage::fake('local');

        $actor = $this->createSuperAdmin();
        $course = $this->draftCourse(actor: $actor);
        $topic = $this->outlineWith($course, $actor);

        $hidden = $this->outlineService()->addResource($topic, [
            'title' => 'Internal notes',
            'type' => 'image',
            'is_public' => false,
        ], UploadedFile::fake()->image('notes.png', 10, 10), $actor);

        $open = $this->outlineService()->addResource($topic, [
            'title' => 'Syllabus',
            'type' => 'image',
            'is_public' => true,
            'is_downloadable' => true,
        ], UploadedFile::fake()->image('syllabus.png', 10, 10), $actor);

        // While the course is a draft, neither exists as far as a visitor is concerned.
        $this->get(route('site.courses.resource', [$course->slug, $open->getKey()]))->assertNotFound();

        $this->courseService()->publish($course->refresh(), $actor);
        $slug = $course->fresh()->slug;

        $this->get(route('site.courses.resource', [$slug, $open->getKey()]))->assertOk();
        $this->get(route('site.courses.resource', [$slug, $hidden->getKey()]))->assertNotFound();

        // A resource that is public but not downloadable is listed, not served.
        $this->outlineService()->updateResource($open, [
            'title' => 'Syllabus',
            'is_public' => true,
            'is_downloadable' => false,
        ], $actor);

        $this->get(route('site.courses.resource', [$slug, $open->getKey()]))->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | The caches are re-derivable
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_outline_caches_are_rebuilt_from_the_tree(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->draftCourse(actor: $actor);
        $topic = $this->outlineWith($course, $actor);

        $this->outlineService()->addLecture($topic, ['title' => 'Second', 'duration_minutes' => 30], $actor);

        $expected = [
            'modules_count' => 1,
            'topics_count' => 1,
            'lectures_count' => 2,
            'outline_minutes' => 90,
        ];

        foreach ($expected as $column => $value) {
            $this->assertSame($value, (int) $course->fresh()->{$column}, $column.' was maintained as the tree grew');
        }

        // Corrupt every cache, then prove a recount restores each one.
        $this->app['db']->table('courses')->where('id', $course->getKey())->update([
            'modules_count' => 99, 'topics_count' => 99, 'lectures_count' => 99, 'outline_minutes' => 9999,
        ]);

        $this->outlineService()->recountTree($course->fresh());

        foreach ($expected as $column => $value) {
            $this->assertSame($value, (int) $course->fresh()->{$column}, $column.' was re-derived from the rows');
        }
    }

    #[Test]
    public function duplicating_a_module_copies_its_topics_and_lectures(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->draftCourse(actor: $actor);
        $this->outlineWith($course, $actor);

        $module = $this->firstModule($course);
        $copy = $this->outlineService()->duplicateModule($module, $actor);

        $this->assertNotSame((int) $module->getKey(), (int) $copy->getKey());
        $this->assertSame((int) $module->course_id, (int) $copy->course_id, 'a module copy stays in its own course');
        $this->assertSame(1, (int) $copy->fresh()->topics_count);
        $this->assertSame(2, (int) $course->fresh()->modules_count);

        $this->assertSame(
            1,
            CourseLecture::query()->whereIn(
                'course_topic_id',
                CourseTopic::query()->where('course_module_id', $copy->getKey())->pluck('id'),
            )->count(),
        );
    }
}
