<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\CourseResourceType;
use App\Enums\LectureType;
use App\Http\Controllers\Controller;
use App\Models\Institute\Course;
use App\Models\Institute\CourseLecture;
use App\Models\Institute\CourseModule;
use App\Models\Institute\CourseTopic;
use App\Models\Institute\CourseTopicAssignment;
use App\Models\Institute\CourseTopicResource;
use App\Services\Institute\CourseOutlineService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The three-level outline — `admin.course-outline.*` and the four node resources
 * (§65, phase-14-17 §7.2, §8.4).
 *
 * **Every parent comes from the route, not the body.** `storeTopic()` is bound to a module and
 * `storeLecture()` to a topic, so the ids the row is written with were never in the request. That is
 * INV-I12 in practice: there is no code path a crafted POST could use to graft a node onto another
 * course's tree.
 *
 * **Reorder and move re-verify ownership server-side** (§6.3). The drag-and-drop posts
 * `{level, parentId, orderedIds}` and the service checks every id against the parent and the parent
 * against the course — the client's word is never taken, and a payload with one foreign id reorders
 * nothing at all.
 */
final class CourseOutlineController extends Controller
{
    public function __construct(
        private readonly CourseOutlineService $outline,
    ) {}

    public function index(Request $request, Course $course): View
    {
        $course->load([
            'modules' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'modules.topics' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'modules.topics.lectures' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'modules.topics.resources' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'modules.topics.assignmentBlueprints' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
        ]);

        return view('admin.course-outline.index', [
            'course' => $course,
            'lectureTypes' => LectureType::cases(),
            'resourceTypes' => CourseResourceType::cases(),
            'canCreate' => (bool) $request->user()?->can('course_outline.create'),
            'canEdit' => (bool) $request->user()?->can('course_outline.edit'),
            'canDelete' => (bool) $request->user()?->can('course_outline.delete'),
            'canToggle' => (bool) $request->user()?->can('course_outline.change_status'),
            'canUpload' => (bool) $request->user()?->can('course_outline.upload'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    */

    public function storeModule(Request $request, Course $course): RedirectResponse
    {
        $this->outline->addModule($course, $this->moduleRules($request), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Module added.']);
    }

    public function updateModule(Request $request, CourseModule $module): RedirectResponse
    {
        $this->outline->updateModule($module, $this->moduleRules($request), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Module updated.']);
    }

    public function destroyModule(Request $request, CourseModule $module): RedirectResponse
    {
        $this->outline->delete($module, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Module removed.']);
    }

    public function duplicateModule(Request $request, CourseModule $module): RedirectResponse
    {
        $copy = $this->outline->duplicateModule($module, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('"%s" was copied with its topics and lectures.', $copy->title),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Topics
    |--------------------------------------------------------------------------
    */

    public function storeTopic(Request $request, CourseModule $module): RedirectResponse
    {
        $this->outline->addTopic($module, $this->topicRules($request), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Topic added.']);
    }

    public function updateTopic(Request $request, CourseTopic $topic): RedirectResponse
    {
        $this->outline->updateTopic(
            $topic,
            $this->topicRules($request),
            $request->string('reason')->toString(),
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Topic updated.']);
    }

    public function destroyTopic(Request $request, CourseTopic $topic): RedirectResponse
    {
        $this->outline->delete($topic, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Topic removed.']);
    }

    /**
     * Move a topic to another module of the same course.
     */
    public function moveTopic(Request $request, CourseTopic $topic): RedirectResponse
    {
        $validated = $request->validate([
            'course_module_id' => ['required', 'integer', 'exists:course_modules,id'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $target = CourseModule::query()->findOrFail($validated['course_module_id']);

        $this->outline->moveTopic($topic, $target, $validated['position'] ?? null, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('Moved to "%s".', $target->title),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Lectures
    |--------------------------------------------------------------------------
    */

    public function storeLecture(Request $request, CourseTopic $topic): RedirectResponse
    {
        $this->outline->addLecture($topic, $this->lectureRules($request), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Lecture added.']);
    }

    public function updateLecture(Request $request, CourseLecture $lecture): RedirectResponse
    {
        $this->outline->updateLecture($lecture, $this->lectureRules($request), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Lecture updated.']);
    }

    public function destroyLecture(Request $request, CourseLecture $lecture): RedirectResponse
    {
        $this->outline->delete($lecture, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Lecture removed.']);
    }

    /*
    |--------------------------------------------------------------------------
    | Resources and assignment blueprints
    |--------------------------------------------------------------------------
    */

    public function storeResource(Request $request, CourseTopic $topic): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'type' => ['required', Rule::enum(CourseResourceType::class)],
            'external_url' => ['nullable', 'url', 'max:500'],
            'is_public' => ['nullable', 'boolean'],
            'is_downloadable' => ['nullable', 'boolean'],
            // `file` only: the real MIME check runs on the content in the service (§111), because a
            // `mimes:` rule reads the extension and an extension is whatever the file was named.
            'file' => ['nullable', 'file', 'max:25600'],
        ]);

        $this->outline->addResource($topic, $validated, $request->file('file'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Resource added to the syllabus.']);
    }

    public function updateResource(Request $request, CourseTopicResource $resource): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'external_url' => ['nullable', 'url', 'max:500'],
            'is_public' => ['nullable', 'boolean'],
            'is_downloadable' => ['nullable', 'boolean'],
        ]);

        $this->outline->updateResource($resource, $validated, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Resource updated.']);
    }

    /**
     * The only way to a syllabus file (D21).
     *
     * The file is on the private disk, so the route's `course_outline.download` is re-run every time one
     * is served — where a public disk would have given out an address no permission could take back.
     */
    public function downloadResource(CourseTopicResource $resource): StreamedResponse
    {
        abort_if($resource->file_path === null, 404);
        abort_unless(Storage::disk('local')->exists($resource->file_path), 404);

        $name = Str::slug($resource->title);

        return Storage::disk('local')->download(
            $resource->file_path,
            ($name === '' ? 'resource' : $name).'.'.pathinfo($resource->file_path, PATHINFO_EXTENSION),
        );
    }

    public function destroyResource(Request $request, CourseTopicResource $resource): RedirectResponse
    {
        $this->outline->delete($resource, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Resource removed.']);
    }

    public function storeAssignment(Request $request, CourseTopic $topic): RedirectResponse
    {
        $this->outline->addAssignmentBlueprint($topic, $this->blueprintRules($request), $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Assignment blueprint added. A batch turns it into a real assignment with a deadline.',
        ]);
    }

    public function updateAssignment(Request $request, CourseTopicAssignment $blueprint): RedirectResponse
    {
        $this->outline->updateAssignmentBlueprint($blueprint, $this->blueprintRules($request), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Blueprint updated.']);
    }

    public function destroyAssignment(Request $request, CourseTopicAssignment $blueprint): RedirectResponse
    {
        $this->outline->delete($blueprint, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Blueprint removed.']);
    }

    /*
    |--------------------------------------------------------------------------
    | Arranging and switching off
    |--------------------------------------------------------------------------
    */

    public function reorder(Request $request, Course $course): RedirectResponse
    {
        $validated = $request->validate([
            'level' => ['required', 'string', 'in:module,topic,lecture,resource,assignment'],
            'parent_id' => ['required', 'integer'],
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ]);

        $this->outline->reorder(
            $course,
            $validated['level'],
            (int) $validated['parent_id'],
            $validated['order'],
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'The order was saved.']);
    }

    /**
     * Deactivate or reactivate a node — the alternative to deleting one that has been taught from.
     */
    public function toggle(Request $request, Course $course, string $level, int $node): RedirectResponse
    {
        $validated = $request->validate(['active' => ['required', 'boolean']]);

        $model = match ($level) {
            'module' => CourseModule::class,
            'topic' => CourseTopic::class,
            'lecture' => CourseLecture::class,
            'assignment' => CourseTopicAssignment::class,
            default => abort(404),
        };

        // Scoped to the course in the URL: a node id from another course answers 404 rather than
        // toggling something the caller was not looking at.
        $row = $model::query()->where('course_id', $course->getKey())->findOrFail($node);

        $this->outline->setActive($row, (bool) $validated['active'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $validated['active']
                ? 'Switched back on.'
                : 'Switched off. It keeps its history and drops out of every percentage.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function moduleRules(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function topicRules(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            // 1..100, matching `chk_ct_weight`: a weighting has to stay a weighting rather than
            // drowning every other topic in the denominator.
            'weight' => ['nullable', 'integer', 'min:1', 'max:100'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lectureRules(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'lecture_type' => ['required', Rule::enum(LectureType::class)],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'video_url' => ['nullable', 'url', 'max:255'],
            'is_preview' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function blueprintRules(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'estimated_marks' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
