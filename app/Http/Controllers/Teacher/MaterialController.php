<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Enums\CourseResourceType;
use App\Enums\MaterialAccessAction;
use App\Enums\MaterialStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teacher\Concerns\ResolvesTheSignedInTeacher;
use App\Http\Requests\Admin\Institute\StoreCourseMaterialRequest;
use App\Models\Institute\Batch;
use App\Models\Institute\CourseMaterial;
use App\Models\Institute\Teacher;
use App\Services\Institute\CourseMaterialService;
use App\Services\Institute\MaterialAccessService;
use App\Support\Institute\TeacherScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A teacher sharing material with their own batches — `teacher.materials.*`
 * (§73, phase-19-23 §7.10, §8.10).
 *
 * **Scoped by `TeacherScope`, so another teacher's batch is a 404.** The scope counts all four ways a
 * teacher reaches a batch — named teacher, timetable entry, taught a session, or was the teacher a
 * session was moved off — because leaving one out means a substitute who actually took the class
 * cannot hand out the handout they used.
 *
 * **A teacher may only target their own batches.** The service asserts that every target resolves to
 * the material's course; this adds that it also resolves to a batch this teacher reaches, which the
 * service cannot know and a form field must never be trusted for.
 */
final class MaterialController extends Controller
{
    use ResolvesTheSignedInTeacher;

    public function __construct(
        private readonly CourseMaterialService $materials,
        private readonly MaterialAccessService $access,
    ) {}

    public function index(Request $request): View
    {
        $teacher = $this->teacher($request);

        $materials = $this->access->visibleToTeacher($teacher, $request->user())
            ->with(['course:id,name', 'topic:id,title'])
            ->when($request->string('q')->toString() !== '', function ($q) use ($request): void {
                $q->where('title', 'like', '%'.$request->string('q')->toString().'%');
            })
            ->when($request->string('status')->toString() !== '', fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('teacher.materials.index', [
            'materials' => $materials,
            'statuses' => MaterialStatus::cases(),
            'canUpload' => (bool) $request->user()?->can('teacher_portal.materials_upload'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('teacher.materials.create', $this->formData($request));
    }

    public function store(StoreCourseMaterialRequest $request): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $targets = $this->ownTargetsOnly($teacher, $request);

        $material = $this->materials->create(
            array_merge($request->safe()->except(['file', 'targets']), [
                // The teacher is the one sharing it, whatever the form says.
                'teacher_id' => $teacher->getKey(),
            ]),
            $request->file('file'),
            $targets,
            $request->user(),
        );

        return redirect()
            ->route('teacher.materials.index')
            ->with('toast', [
                'type' => 'success',
                'message' => 'Saved as a draft. Publish it when the batch should see it.',
            ]);
    }

    public function update(StoreCourseMaterialRequest $request, CourseMaterial $material): RedirectResponse
    {
        $this->assertMine($request, $material);

        $this->materials->update(
            $material,
            $request->safe()->except(['file', 'targets', 'course_id', 'type', 'teacher_id']),
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Material updated.']);
    }

    public function targets(Request $request, CourseMaterial $material): RedirectResponse
    {
        $this->assertMine($request, $material);

        $result = $this->materials->setTargets(
            $material,
            $this->ownTargetsOnly($this->teacher($request), $request),
            $request->user(),
        );

        return back()->with('toast', [
            'type' => $result->changed() ? 'success' : 'info',
            'message' => $result->summary(),
        ]);
    }

    public function status(Request $request, CourseMaterial $material): RedirectResponse
    {
        $this->assertMine($request, $material);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:published,draft,archived'],
            'reason' => ['nullable', 'string', 'min:5', 'max:255'],
        ]);

        $reason = (string) ($validated['reason'] ?? 'Changed by the teacher');
        $actor = $request->user();

        $material = match ($validated['status']) {
            'published' => $material->getAttribute('published_at') === null
                ? $this->materials->publish($material, $actor)
                : $this->materials->restoreToPublished($material, $reason, $actor),
            'draft' => $this->materials->unpublish($material, $reason, $actor),
            'archived' => $this->materials->archive($material, $reason, $actor),
        };

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Material is now '.$material->status->label().'.',
        ]);
    }

    public function download(Request $request, CourseMaterial $material): StreamedResponse
    {
        // No `assertMine` here: `grantFor()` already allows a teacher of a targeted batch, and a
        // teacher reading material somebody else shared with their own batch is the normal case.
        return $this->access->stream($material, $request->user(), MaterialAccessAction::Download);
    }

    // -------------------------------------------------------------------------------------------

    /** Their own drafts and material shared with their batches; anything else is a 404. */
    private function assertMine(Request $request, CourseMaterial $material): void
    {
        abort_unless(
            $this->access->visibleToTeacher($this->teacher($request), $request->user())
                ->whereKey($material->getKey())
                ->exists(),
            Response::HTTP_NOT_FOUND,
        );
    }

    /**
     * Targets narrowed to batches this teacher actually reaches. A batch id in the form that is not
     * theirs is dropped rather than refused — it cannot have come from the picker, so naming it in an
     * error message would only confirm the id exists.
     *
     * @return list<array{type: string, id: int}>
     */
    private function ownTargetsOnly(Teacher $teacher, Request $request): array
    {
        $mine = TeacherScope::batchIds($teacher);
        $targets = $request->input('targets', []);
        $clean = [];

        if (! is_array($targets)) {
            return [];
        }

        foreach ($targets as $row) {
            if (! is_array($row) || ! isset($row['type'], $row['id'])) {
                continue;
            }

            // A teacher targets batches. Course-wide and per-student targeting is a staff act: one
            // reaches students they do not teach, the other names an individual.
            if ((string) $row['type'] !== 'batch' || ! in_array((int) $row['id'], $mine, true)) {
                continue;
            }

            $clean[] = ['type' => 'batch', 'id' => (int) $row['id']];
        }

        return $clean;
    }

    /** @return array<string, mixed> */
    private function formData(Request $request): array
    {
        $teacher = $this->teacher($request);

        return [
            'batches' => Batch::query()
                ->whereIn('id', TeacherScope::batchIds($teacher))
                ->with('course:id,name')
                ->get(['id', 'code', 'name', 'course_id']),
            'types' => CourseResourceType::cases(),
        ];
    }
}
