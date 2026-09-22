<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Enums\CourseResourceType;
use App\Models\Institute\Course;
use App\Models\Institute\CourseLecture;
use App\Models\Institute\CourseModule;
use App\Models\Institute\CourseTopic;
use App\Models\Institute\CourseTopicAssignment;
use App\Models\Institute\CourseTopicResource;
use App\Models\User;
use App\Services\Cms\CacheVersion;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The three-level outline (§65, phase-14-17 §6.3).
 *
 * **Every parent id comes from the parent, never from the request.** `addTopic()` takes a module and
 * reads its `course_id`; `addLecture()` takes a topic and reads both. That is the whole of INV-I12's
 * enforcement in practice: there is no code path where a crafted POST can graft a node onto another
 * course's tree, because the ids the row is written with were never in the request body.
 *
 * **`reorder()` verifies ownership before it moves anything.** Every id in the payload must belong to
 * the named parent, and the parent must belong to the course being edited. A payload carrying one
 * foreign id reorders **nothing** — a partial reorder would leave two courses' trees each half-shuffled
 * with no record of what the order was before.
 *
 * **Uploads are checked by content.** `CourseResourceType::allowedMimes()` is compared against what
 * `finfo` says the file is, not against the name or the browser's claim (§111), and the stored filename
 * is hashed so nothing about the upload's own name reaches the disk.
 *
 * **Deactivate, do not delete** (INV-I13). A node any coverage, progress or session row points at is
 * history: removing it would move a student's percentage without anybody deciding to, so the delete is
 * refused and the screen offers the toggle instead.
 */
final class CourseOutlineService
{
    /** Where a syllabus resource lives on the public disk (§2.8). */
    /**
     * The private disk, against §2.8's own line — see D21. A resource that is not `is_public` needs
     * `course_outline.view`, and a file the web server serves directly has no permission in front of
     * it: `Storage::url()` would hand out an address that stays valid after the resource is hidden,
     * after it is deleted, and after the person who was shown it leaves. Two controllers serve these
     * files instead, and each re-runs its own rule.
     */
    private const RESOURCE_DISK = 'local';

    /** 25 MB. Bigger than any slide deck and smaller than a video somebody should be linking instead. */
    private const MAX_RESOURCE_BYTES = 25 * 1024 * 1024;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CacheVersion $cache,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Adding nodes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     */
    public function addModule(Course $course, array $data, ?User $actor = null): CourseModule
    {
        return $this->db->transaction(function () use ($course, $data, $actor): CourseModule {
            $module = new CourseModule;

            $module->fill($this->only($data, ['title', 'description', 'duration_minutes', 'is_active']));
            $module->forceFill([
                'course_id' => $course->getKey(),
                'sort_order' => $this->nextPosition(CourseModule::class, 'course_id', $course->getKey()),
                'created_by' => $actor?->getKey(),
            ])->save();

            $this->recountCourse($course);
            $this->announce($course);

            return $module->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addTopic(CourseModule $module, array $data, ?User $actor = null): CourseTopic
    {
        return $this->db->transaction(function () use ($module, $data, $actor): CourseTopic {
            $topic = new CourseTopic;

            $topic->fill($this->only($data, ['title', 'description', 'weight', 'estimated_minutes', 'is_active']));
            $topic->forceFill([
                'course_module_id' => $module->getKey(),
                // From the module, never the request — the line INV-I12 actually rests on.
                'course_id' => $module->course_id,
                'sort_order' => $this->nextPosition(CourseTopic::class, 'course_module_id', $module->getKey()),
                'created_by' => $actor?->getKey(),
            ])->save();

            $this->recountModule($module);
            $this->recountCourse($module->course);
            $this->announce($module->course);

            return $topic->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addLecture(CourseTopic $topic, array $data, ?User $actor = null): CourseLecture
    {
        return $this->db->transaction(function () use ($topic, $data, $actor): CourseLecture {
            $lecture = new CourseLecture;

            $lecture->fill($this->only($data, [
                'title', 'description', 'lecture_type', 'duration_minutes', 'video_url', 'is_preview', 'is_active',
            ]));
            $lecture->forceFill([
                'course_topic_id' => $topic->getKey(),
                'course_id' => $topic->course_id,
                'sort_order' => $this->nextPosition(CourseLecture::class, 'course_topic_id', $topic->getKey()),
                'created_by' => $actor?->getKey(),
            ])->save();

            $this->recountTopic($topic);
            $this->recountModule($topic->module);
            $this->recountCourse($topic->course);
            $this->announce($topic->course);

            return $lecture->refresh();
        }, 3);
    }

    /**
     * A syllabus resource: a file, or a link, and always one of the two.
     *
     * @param  array<string, mixed>  $data
     */
    public function addResource(
        CourseTopic $topic,
        array $data,
        ?UploadedFile $file = null,
        ?User $actor = null,
    ): CourseTopicResource {
        $type = $data['type'] instanceof CourseResourceType
            ? $data['type']
            : (CourseResourceType::tryFrom((string) ($data['type'] ?? '')) ?? CourseResourceType::Link);

        $url = trim((string) ($data['external_url'] ?? ''));

        if ($file === null && $url === '') {
            throw CourseRuleException::refuse('external_url',
                'A resource points at something. Attach a file or give a link.');
        }

        $stored = $file === null ? null : $this->storeResourceFile($file, $topic, $type);

        return $this->db->transaction(function () use ($topic, $data, $type, $url, $stored, $actor): CourseTopicResource {
            $resource = new CourseTopicResource;

            $resource->fill($this->only($data, ['title', 'is_public', 'is_downloadable']));
            $resource->forceFill(array_merge([
                'course_topic_id' => $topic->getKey(),
                'course_id' => $topic->course_id,
                'type' => $type->value,
                'external_url' => $url === '' ? null : $url,
                'sort_order' => $this->nextPosition(CourseTopicResource::class, 'course_topic_id', $topic->getKey()),
                'created_by' => $actor?->getKey(),
            ], $stored ?? ['file_path' => null, 'file_size' => null, 'mime_type' => null]))->save();

            $this->recountTopic($topic);
            $this->announce($topic->course);

            return $resource->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addAssignmentBlueprint(CourseTopic $topic, array $data, ?User $actor = null): CourseTopicAssignment
    {
        return $this->db->transaction(function () use ($topic, $data, $actor): CourseTopicAssignment {
            $blueprint = new CourseTopicAssignment;

            $blueprint->fill($this->only($data, [
                'title', 'description', 'instructions', 'estimated_marks', 'estimated_hours',
                'attachment_path', 'is_active',
            ]));
            $blueprint->forceFill([
                'course_topic_id' => $topic->getKey(),
                'course_id' => $topic->course_id,
                'sort_order' => $this->nextPosition(CourseTopicAssignment::class, 'course_topic_id', $topic->getKey()),
                'created_by' => $actor?->getKey(),
            ])->save();

            $this->recountTopic($topic);
            $this->announce($topic->course);

            return $blueprint->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Moving things around
    |--------------------------------------------------------------------------
    */

    /**
     * Reorder one level under one parent.
     *
     * @param  'module'|'topic'|'lecture'|'resource'|'assignment'  $level
     * @param  list<int>  $orderedIds
     */
    /*
    |--------------------------------------------------------------------------
    | Editing a node
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateModule(CourseModule $module, array $data, ?User $actor = null): CourseModule
    {
        return $this->db->transaction(function () use ($module, $data, $actor): CourseModule {
            $module->fill($this->only($data, ['title', 'description', 'duration_minutes', 'is_active']));
            $module->updated_by = $actor?->getKey();
            $module->save();

            $this->recountCourse($module->course);
            $this->announce($module->course);

            return $module->refresh();
        }, 3);
    }

    /**
     * A weight change moves every student's percentage on this course, so it carries the reason.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateTopic(
        CourseTopic $topic,
        array $data,
        ?string $reason = null,
        ?User $actor = null,
    ): CourseTopic {
        return $this->db->transaction(function () use ($topic, $data, $reason, $actor): CourseTopic {
            $reason = trim((string) $reason);
            $weightMoved = array_key_exists('weight', $data)
                && (int) $data['weight'] !== (int) $topic->weight;

            if ($weightMoved && $reason !== '') {
                $topic->withReason($reason);
            }

            $topic->fill($this->only($data, [
                'title', 'description', 'weight', 'estimated_minutes', 'is_active',
            ]));
            $topic->updated_by = $actor?->getKey();
            $topic->save();

            $this->recountCourse($topic->course);
            $this->announce($topic->course);

            return $topic->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateLecture(CourseLecture $lecture, array $data, ?User $actor = null): CourseLecture
    {
        return $this->db->transaction(function () use ($lecture, $data, $actor): CourseLecture {
            $lecture->fill($this->only($data, [
                'title', 'description', 'lecture_type', 'duration_minutes', 'video_url', 'is_preview', 'is_active',
            ]));
            $lecture->updated_by = $actor?->getKey();
            $lecture->save();

            $this->recountCourse($lecture->course);
            $this->announce($lecture->course);

            return $lecture->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateResource(
        CourseTopicResource $resource,
        array $data,
        ?User $actor = null,
    ): CourseTopicResource {
        return $this->db->transaction(function () use ($resource, $data, $actor): CourseTopicResource {
            // Flipping `is_public` publishes a file to anyone who opens the course page, so the
            // activity row says who decided that and which way.
            if (array_key_exists('is_public', $data)
                && (bool) $data['is_public'] !== (bool) $resource->is_public) {
                $resource->withReason((bool) $data['is_public']
                    ? 'Made visible on the public course page.'
                    : 'Hidden from the public course page.');
            }

            $resource->fill($this->only($data, [
                'title', 'external_url', 'is_public', 'is_downloadable',
            ]));
            $resource->updated_by = $actor?->getKey();
            $resource->save();

            $this->announce($resource->course);

            return $resource->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAssignmentBlueprint(
        CourseTopicAssignment $blueprint,
        array $data,
        ?User $actor = null,
    ): CourseTopicAssignment {
        return $this->db->transaction(function () use ($blueprint, $data, $actor): CourseTopicAssignment {
            $blueprint->fill($this->only($data, [
                'title', 'description', 'instructions', 'estimated_marks', 'estimated_hours',
                'attachment_path', 'is_active',
            ]));
            $blueprint->updated_by = $actor?->getKey();
            $blueprint->save();

            $this->announce($blueprint->course);

            return $blueprint->refresh();
        }, 3);
    }

    public function reorder(Course $course, string $level, int $parentId, array $orderedIds, ?User $actor = null): void
    {
        [$model, $parentColumn] = $this->levelFor($level);

        $orderedIds = array_values(array_unique(array_map('intval', $orderedIds)));

        if ($orderedIds === []) {
            return;
        }

        // The parent must belong to the course being edited. Without this, a valid-looking payload for
        // another course's module would reorder that course's topics.
        $this->assertParentBelongsToCourse($level, $parentId, $course);

        $owned = $model::query()
            ->where($parentColumn, $parentId)
            ->whereKey($orderedIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if (array_diff($orderedIds, $owned) !== []) {
            throw CourseRuleException::refuse('order', sprintf(
                'Some of the ids sent do not belong to that %s, so nothing was reordered.',
                $level,
            ));
        }

        $this->db->transaction(function () use ($model, $orderedIds, $actor, $course): void {
            foreach ($orderedIds as $position => $id) {
                $model::query()->whereKey($id)->update([
                    'sort_order' => ($position + 1) * 10,
                    'updated_by' => $actor?->getKey(),
                    'updated_at' => now(),
                ]);
            }

            // No count moved, so there is nothing to recount — but the order a visitor reads did.
            $this->announce($course);
        }, 3);
    }

    /**
     * Move a topic to another module of the **same course**.
     *
     * Later phases point at a topic by id, so nothing downstream needs repointing when it changes
     * module — except the two rows that cache the module alongside it, which are rewritten here so a
     * progress report does not show a topic filed under a module it left.
     */
    public function moveTopic(CourseTopic $topic, CourseModule $target, ?int $position = null, ?User $actor = null): CourseTopic
    {
        if ((int) $target->course_id !== (int) $topic->course_id) {
            throw CourseRuleException::refuse('course_module_id',
                'A topic can only move between modules of its own course. Moving it to another course '
                .'would take every student\'s progress on it somewhere nobody is looking.');
        }

        return $this->db->transaction(function () use ($topic, $target, $position, $actor): CourseTopic {
            $source = $topic->module;

            $topic->forceFill([
                'course_module_id' => $target->getKey(),
                'sort_order' => $position !== null
                    ? max(0, $position) * 10
                    : $this->nextPosition(CourseTopic::class, 'course_module_id', $target->getKey()),
                'updated_by' => $actor?->getKey(),
            ])->save();

            $this->repointCachedModule($topic, $target);

            $this->recountModule($source);
            $this->recountModule($target);
            $this->recountCourse($topic->course);
            $this->announce($topic->course);

            return $topic->refresh();
        }, 3);
    }

    /**
     * Deep-copy a module within its own course.
     */
    public function duplicateModule(CourseModule $module, ?User $actor = null): CourseModule
    {
        return $this->db->transaction(function () use ($module, $actor): CourseModule {
            $copy = new CourseModule;

            $copy->forceFill(array_merge(
                $module->only(['title', 'description', 'duration_minutes', 'is_active']),
                [
                    'title' => $module->title.' (copy)',
                    'course_id' => $module->course_id,
                    'sort_order' => $this->nextPosition(CourseModule::class, 'course_id', $module->course_id),
                    'created_by' => $actor?->getKey(),
                ],
            ))->save();

            foreach ($module->topics as $topic) {
                $newTopic = new CourseTopic;
                $newTopic->forceFill(array_merge(
                    $topic->only(['title', 'description', 'sort_order', 'weight', 'estimated_minutes', 'is_active']),
                    [
                        'course_module_id' => $copy->getKey(),
                        'course_id' => $module->course_id,
                        'created_by' => $actor?->getKey(),
                    ],
                ))->save();

                foreach ($topic->lectures as $lecture) {
                    (new CourseLecture)->forceFill(array_merge(
                        $lecture->only(['title', 'description', 'lecture_type', 'sort_order',
                            'duration_minutes', 'video_url', 'is_preview', 'is_active']),
                        [
                            'course_topic_id' => $newTopic->getKey(),
                            'course_id' => $module->course_id,
                            'created_by' => $actor?->getKey(),
                        ],
                    ))->save();
                }
            }

            $this->recountModule($copy->refresh());
            $this->recountCourse($module->course);
            $this->announce($module->course);

            return $copy->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Switching off and removing
    |--------------------------------------------------------------------------
    */

    /**
     * The alternative to deletion for a node somebody has already taught from (INV-I13).
     */
    public function setActive(Model $node, bool $active, ?User $actor = null): void
    {
        $this->assertOutlineNode($node);

        $this->db->transaction(function () use ($node, $active, $actor): void {
            $node->forceFill(['is_active' => $active, 'updated_by' => $actor?->getKey()])->save();

            // Deactivating a topic takes its weight out of every progress denominator, so the
            // percentages that depend on it have to be recomputed. Phase 17 owns that service; until
            // it ships there is nothing to call, and the recount command rebuilds from scratch anyway.
            if ($node instanceof CourseTopic && class_exists(CourseProgressService::class)) {
                app(CourseProgressService::class)->recomputeForCourse($node->course);
            }

            // Switching a node off takes it off the public outline, and that path runs no recount.
            $this->announce($node->course);
        }, 3);
    }

    /**
     * Refused while anything downstream points at it — the screen then offers Deactivate.
     */
    public function delete(Model $node, ?User $actor = null): void
    {
        $this->assertOutlineNode($node);

        $blockers = $this->referencesFor($node);

        if ($blockers !== []) {
            throw CourseRuleException::refuse('node', sprintf(
                'This %s has already been taught or marked against — %s. Deleting it would move every '
                .'affected percentage without anybody deciding to. Switch it off instead: it keeps the '
                .'history and takes it out of the denominator.',
                $this->nodeLabel($node),
                $this->describe($blockers),
            ));
        }

        $this->db->transaction(function () use ($node): void {
            $course = $node->course;
            $module = $node instanceof CourseTopic ? $node->module : null;
            $topic = method_exists($node, 'topic') ? $node->topic : null;

            $node->delete();

            if ($topic instanceof CourseTopic) {
                $this->recountTopic($topic);
            }

            if ($module instanceof CourseModule) {
                $this->recountModule($module);
            }

            if ($node instanceof CourseLecture && $node->topic?->module instanceof CourseModule) {
                $this->recountModule($node->topic->module);
            }

            if ($course instanceof Course) {
                $this->recountCourse($course);
            }

            $this->announce($course);
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | The caches
    |--------------------------------------------------------------------------
    */

    public function recountTopic(?CourseTopic $topic): void
    {
        if ($topic === null) {
            return;
        }

        CourseTopic::query()->whereKey($topic->getKey())->update([
            'lectures_count' => CourseLecture::query()->where('course_topic_id', $topic->getKey())->count(),
            'resources_count' => CourseTopicResource::query()->where('course_topic_id', $topic->getKey())->count(),
            'assignments_count' => CourseTopicAssignment::query()->where('course_topic_id', $topic->getKey())->count(),
            'updated_at' => now(),
        ]);
    }

    public function recountModule(?CourseModule $module): void
    {
        if ($module === null) {
            return;
        }

        $topicIds = CourseTopic::query()->where('course_module_id', $module->getKey())->pluck('id');

        CourseModule::query()->whereKey($module->getKey())->update([
            'topics_count' => $topicIds->count(),
            'lectures_count' => $topicIds->isEmpty()
                ? 0
                : CourseLecture::query()->whereIn('course_topic_id', $topicIds)->count(),
            'updated_at' => now(),
        ]);
    }

    public function recountCourse(?Course $course): void
    {
        if ($course === null) {
            return;
        }

        app(CourseService::class)->recountOutline($course);
    }

    /**
     * Take the site's copy of a course whose outline just changed out of circulation (§7.10).
     *
     * Its own step, deliberately, rather than something riding on a recount: `recountTopic()` never
     * reaches the course, so a resource added to a published syllabus would have recounted perfectly
     * and left the landing page serving yesterday's list — and `reorder()` recounts nothing at all
     * while changing the order a visitor reads. The public cache is a version stamp (D22), so one bump
     * invalidates every cached page at once; it is cheap, and a stale public page is not.
     */
    private function announce(?Course $course): void
    {
        if ($course === null || ! $course->status->isPublic()) {
            return;
        }

        $this->cache->bumpAfterCommit(sprintf('Outline of course #%d changed', $course->getKey()));
    }

    /**
     * Rebuild every cache in one course's tree — what `courses:recount-outline` calls.
     */
    public function recountTree(Course $course): void
    {
        foreach (CourseTopic::query()->where('course_id', $course->getKey())->get() as $topic) {
            $this->recountTopic($topic);
        }

        foreach (CourseModule::query()->where('course_id', $course->getKey())->get() as $module) {
            $this->recountModule($module);
        }

        $this->recountCourse($course);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Store an upload, after checking what it actually is.
     *
     * @return array{file_path: string, file_size: int, mime_type: string}
     */
    private function storeResourceFile(UploadedFile $file, CourseTopic $topic, CourseResourceType $type): array
    {
        if (! $type->isFile()) {
            throw CourseRuleException::refuse('file', sprintf(
                'A "%s" resource carries no file — give the address instead.',
                $type->label(),
            ));
        }

        if ($file->getSize() > self::MAX_RESOURCE_BYTES) {
            throw CourseRuleException::refuse('file', sprintf(
                'That file is %s. The limit for a syllabus resource is %s — link to anything larger.',
                $this->humanBytes((int) $file->getSize()),
                $this->humanBytes(self::MAX_RESOURCE_BYTES),
            ));
        }

        // What the file IS, from its content — never `getClientMimeType()`, which is whatever the
        // browser was told to send, and never the extension, which is whatever the file was named.
        $mime = (string) $file->getMimeType();
        $allowed = $type->allowedMimes();

        if (! in_array($mime, $allowed, true)) {
            throw CourseRuleException::refuse('file', sprintf(
                'That file is a %s, which is not a %s. Renaming a file does not change what it is, and '
                .'this is checked on the contents.',
                $mime === '' ? 'file of an unrecognised kind' : $mime,
                $type->label(),
            ));
        }

        // A hashed name: nothing the uploader chose reaches the disk, so a crafted filename cannot
        // become a path or a script name.
        $name = Str::random(40).'.'.strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = sprintf('courses/%d/resources/%s', $topic->course_id, $name);

        Storage::disk(self::RESOURCE_DISK)->putFileAs(dirname($path), $file, basename($path));

        return [
            'file_path' => $path,
            'file_size' => (int) $file->getSize(),
            'mime_type' => $mime,
        ];
    }

    /**
     * @return array{0: class-string<Model>, 1: string}
     */
    private function levelFor(string $level): array
    {
        return match ($level) {
            'module' => [CourseModule::class, 'course_id'],
            'topic' => [CourseTopic::class, 'course_module_id'],
            'lecture' => [CourseLecture::class, 'course_topic_id'],
            'resource' => [CourseTopicResource::class, 'course_topic_id'],
            'assignment' => [CourseTopicAssignment::class, 'course_topic_id'],
            default => throw CourseRuleException::refuse('level', sprintf(
                '"%s" is not a level of the outline. It is module, topic, lecture, resource or assignment.',
                $level,
            )),
        };
    }

    private function assertParentBelongsToCourse(string $level, int $parentId, Course $course): void
    {
        $belongs = match ($level) {
            'module' => $parentId === (int) $course->getKey(),
            'topic' => CourseModule::query()->whereKey($parentId)->where('course_id', $course->getKey())->exists(),
            'lecture', 'resource', 'assignment' => CourseTopic::query()
                ->whereKey($parentId)->where('course_id', $course->getKey())->exists(),
            default => false,
        };

        if (! $belongs) {
            throw CourseRuleException::refuse('parent', sprintf(
                'That %s does not belong to %s, so nothing was reordered.',
                $level === 'module' ? 'course' : ($level === 'topic' ? 'module' : 'topic'),
                $course->name,
            ));
        }
    }

    private function assertOutlineNode(Model $node): void
    {
        $known = [CourseModule::class, CourseTopic::class, CourseLecture::class,
            CourseTopicResource::class, CourseTopicAssignment::class];

        if (! in_array($node::class, $known, true)) {
            throw CourseRuleException::refuse('node', 'That is not a node of a course outline.');
        }
    }

    /**
     * @return array<string, int>
     */
    private function referencesFor(Model $node): array
    {
        if ($node instanceof CourseTopic) {
            return $node->references();
        }

        if ($node instanceof CourseModule) {
            $found = [];

            foreach ($node->topics as $topic) {
                foreach ($topic->references() as $table => $count) {
                    $found[$table] = ($found[$table] ?? 0) + $count;
                }
            }

            return $found;
        }

        if ($node instanceof CourseLecture && $this->db->getSchemaBuilder()->hasTable('class_sessions')) {
            $count = $this->db->table('class_sessions')->where('course_lecture_id', $node->getKey())->count();

            return $count > 0 ? ['class_sessions' => $count] : [];
        }

        // A resource and a blueprint are leaves nothing downstream measures against, so removing one
        // moves no percentage and needs no guard.
        return [];
    }

    /**
     * @param  array<string, int>  $blockers
     */
    private function describe(array $blockers): string
    {
        $labels = [
            'batch_topic_coverage' => 'covered in %d %s',
            'student_topic_progress' => 'marked on %d student %s',
            'class_sessions' => 'taught in %d %s',
        ];

        $parts = [];

        foreach ($blockers as $table => $count) {
            $parts[] = sprintf(
                $labels[$table] ?? '%d %s',
                $count,
                match ($table) {
                    'batch_topic_coverage' => $count === 1 ? 'batch' : 'batches',
                    'student_topic_progress' => $count === 1 ? 'record' : 'records',
                    'class_sessions' => $count === 1 ? 'session' : 'sessions',
                    default => 'rows',
                },
            );
        }

        return implode(', ', $parts);
    }

    private function nodeLabel(Model $node): string
    {
        return match ($node::class) {
            CourseModule::class => 'module',
            CourseTopic::class => 'topic',
            CourseLecture::class => 'lecture',
            CourseTopicResource::class => 'resource',
            default => 'assignment blueprint',
        };
    }

    /**
     * Keep the two later-phase caches pointing at the module the topic is actually in.
     */
    private function repointCachedModule(CourseTopic $topic, CourseModule $target): void
    {
        foreach (['student_topic_progress', 'batch_topic_coverage'] as $table) {
            if (! $this->db->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            if (! $this->db->getSchemaBuilder()->hasColumn($table, 'course_module_id')) {
                continue;
            }

            $this->db->table($table)
                ->where('course_topic_id', $topic->getKey())
                ->update(['course_module_id' => $target->getKey()]);
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function nextPosition(string $model, string $column, mixed $parentId): int
    {
        return ((int) $model::query()->where($column, $parentId)->max('sort_order')) + 10;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function only(array $data, array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }

        return $out;
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? round($bytes / (1024 * 1024), 1).' MB'
            : round($bytes / 1024).' KB';
    }
}
