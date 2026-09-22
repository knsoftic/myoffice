<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\CourseResourceType;
use App\Enums\MaterialAccessAction;
use App\Enums\MaterialStatus;
use App\Enums\MaterialTargetType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\StoreCourseMaterialRequest;
use App\Models\Branch;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\CourseMaterial;
use App\Models\Institute\CourseMaterialTarget;
use App\Services\Institute\CourseMaterialService;
use App\Services\Institute\MaterialAccessService;
use App\Support\CsvWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The material library — `admin.course-materials.*` (§79, phase-19-23 §7.1, §8.1–8.3).
 *
 * Nothing here decides anything. `CourseMaterialService` owns the writes and the caches;
 * `MaterialAccessService` owns who may read a file, because that question is INV-19-3 and has exactly
 * one implementation.
 *
 * **The download action runs §6.4's chain even for staff.** The route's `can:course_materials.download`
 * is step 1; the policy is step 3; `MaterialAccessService::stream()` logs before it serves (INV-19-4)
 * and streams through `SecureFileService`. A controller that reached for `Storage::download()` here
 * would skip the log and the hardened headers both.
 */
final class CourseMaterialController extends Controller
{
    public function __construct(
        private readonly CourseMaterialService $materials,
        private readonly MaterialAccessService $access,
    ) {}

    public function index(Request $request): View
    {
        $materials = $this->filtered($request)
            ->with(['course:id,name,code', 'teacher:id,employee_id', 'branch:id,name'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.course-materials.index', [
            'materials' => $materials,
            'courses' => Course::query()->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => MaterialStatus::cases(),
            'types' => CourseResourceType::cases(),
            'scopes' => MaterialTargetType::cases(),
            'counts' => $this->statusCounts($request),
            'canCreate' => (bool) $request->user()?->can('create', CourseMaterial::class),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', CourseMaterial::class);

        return view('admin.course-materials.create', $this->formData($request));
    }

    public function store(StoreCourseMaterialRequest $request): RedirectResponse
    {
        $material = $this->materials->create(
            $request->safe()->except(['file', 'targets']),
            $request->file('file'),
            $this->targetsFrom($request),
            $request->user(),
        );

        return redirect()
            ->route('admin.course-materials.show', $material)
            ->with('toast', [
                'type' => 'success',
                'message' => 'Material saved as a draft. Choose who it is for, then publish it.',
            ]);
    }

    public function show(Request $request, CourseMaterial $material): View
    {
        Gate::authorize('view', $material);

        return view('admin.course-materials.show', [
            'material' => $material->load(['course:id,name,code', 'topic:id,title', 'teacher:id,employee_id', 'branch:id,name']),
            'targets' => $material->targets()->with(['targetBatch:id,code,name', 'targetStudent:id,name,student_code', 'targetCourse:id,name'])->get(),
            'recent' => $material->accessLog()->with(['student:id,name', 'user:id,name'])->limit(10)->get(),
            'canEdit' => (bool) $request->user()?->can('update', $material),
            'canAssign' => (bool) $request->user()?->can('assign', $material),
            'canPublish' => (bool) $request->user()?->can('changeStatus', $material),
            'canDownload' => (bool) $request->user()?->can('download', $material),
            'canSeeEngagement' => (bool) $request->user()?->can('viewReports', $material),
        ]);
    }

    public function edit(Request $request, CourseMaterial $material): View
    {
        Gate::authorize('update', $material);

        return view('admin.course-materials.edit', array_merge($this->formData($request), [
            'material' => $material,
        ]));
    }

    public function update(StoreCourseMaterialRequest $request, CourseMaterial $material): RedirectResponse
    {
        $this->materials->update($material, $request->safe()->except(['file', 'targets', 'course_id', 'type']), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Material updated.']);
    }

    /** Swapping the bytes — `upload`, not `edit`, because it is a different kind of change. */
    public function replaceFile(StoreCourseMaterialRequest $request, CourseMaterial $material): RedirectResponse
    {
        Gate::authorize('upload', $material);

        $file = $request->file('file');

        if ($file === null) {
            return back()->with('toast', ['type' => 'error', 'message' => 'Choose the replacement file.']);
        }

        $this->materials->replaceFile($material, $file, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'File replaced. The access log is kept — it records who opened the material, not which bytes they got.',
        ]);
    }

    /**
     * §6.4 steps 3–7. The grant, the log and the stream all live in `MaterialAccessService`, so a staff
     * download is recorded exactly as a student's is.
     */
    public function download(Request $request, CourseMaterial $material): StreamedResponse
    {
        Gate::authorize('download', $material);

        return $this->access->stream($material, $request->user(), MaterialAccessAction::Download);
    }

    public function storeTargets(Request $request, CourseMaterial $material): RedirectResponse
    {
        Gate::authorize('assign', $material);

        $result = $this->materials->setTargets($material, $this->targetsFrom($request), $request->user());

        return back()->with('toast', [
            'type' => $result->changed() ? 'success' : 'info',
            'message' => $result->summary(),
        ]);
    }

    public function destroyTarget(Request $request, CourseMaterial $material, CourseMaterialTarget $target): RedirectResponse
    {
        Gate::authorize('assign', $material);

        // A target id from another material is a 404 rather than a 403: confirming it exists is half
        // of what somebody probing ids wanted to know.
        abort_unless((int) $target->getAttribute('course_material_id') === (int) $material->getKey(), Response::HTTP_NOT_FOUND);

        $keep = $material->targets()
            ->whereKeyNot($target->getKey())
            ->get()
            ->map(static fn (CourseMaterialTarget $t): array => ['type' => $t->target_type->value, 'id' => (int) $t->targetId()])
            ->all();

        $this->materials->setTargets($material, $keep, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Audience removed.']);
    }

    public function status(Request $request, CourseMaterial $material): RedirectResponse
    {
        Gate::authorize('changeStatus', $material);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:published,draft,archived'],
            'reason' => ['nullable', 'string', 'min:5', 'max:255'],
        ]);

        $reason = (string) ($validated['reason'] ?? '');
        $actor = $request->user();

        $material = match ($validated['status']) {
            'published' => $material->status === MaterialStatus::Draft && $material->getAttribute('published_at') === null
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

    /**
     * A soft delete. **The bytes stay on disk** (§2.3) — the row is hidden, so a material removed by
     * mistake comes back whole instead of being re-uploaded from somebody's laptop.
     */
    public function destroy(Request $request, CourseMaterial $material): RedirectResponse
    {
        Gate::authorize('delete', $material);

        $material->delete();

        return redirect()
            ->route('admin.course-materials.index')
            ->with('toast', ['type' => 'success', 'message' => 'Material removed. The file is kept, so it can be restored.']);
    }

    /** Who opened it, and who never did — the question a teacher actually asks (§8.3). */
    public function engagement(Request $request, CourseMaterial $material): View
    {
        Gate::authorize('viewReports', $material);

        return view('admin.course-materials.engagement', [
            'material' => $material->load('course:id,name'),
            'log' => $material->accessLog()
                ->with(['student:id,name,student_code', 'user:id,name'])
                ->paginate(30)
                ->withQueryString(),
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        Gate::authorize('export', CourseMaterial::class);

        abort_unless($format === 'csv', Response::HTTP_NOT_FOUND);

        return (new CsvWriter)->download(
            'course-materials-'.app_date(now(), 'Y-m-d').'.csv',
            ['Title', 'Course', 'Kind', 'Status', 'Audience', 'Targets', 'Views', 'Downloads', 'Students', 'Published'],
            CsvWriter::rowsFrom(
                $this->filtered($request)->with(['course:id,name']),
                static fn (CourseMaterial $m): array => [
                    (string) $m->getAttribute('title'),
                    (string) $m->course?->getAttribute('name'),
                    $m->type->label(),
                    $m->status->label(),
                    $m->audience_scope->label(),
                    (string) $m->getAttribute('targets_count'),
                    (string) $m->getAttribute('view_count'),
                    (string) $m->getAttribute('download_count'),
                    (string) $m->getAttribute('unique_students_count'),
                    $m->getAttribute('published_at') ? app_date($m->getAttribute('published_at')) : '',
                ],
            ),
        );
    }

    // -------------------------------------------------------------------------------------------

    /**
     * The one filtered query the index, the counts and the export all read, so a figure at the top of
     * the page cannot disagree with the rows underneath it.
     */
    private function filtered(Request $request): Builder
    {
        return CourseMaterial::query()
            ->when($request->string('q')->toString() !== '', function (Builder $q) use ($request): void {
                $term = '%'.$request->string('q')->toString().'%';
                $q->where(static fn (Builder $w) => $w
                    ->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term));
            })
            ->when($request->integer('course_id') > 0, fn (Builder $q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->integer('branch_id') > 0, fn (Builder $q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->string('status')->toString() !== '', fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->string('type')->toString() !== '', fn (Builder $q) => $q->where('type', $request->string('type')->toString()))
            ->when($request->boolean('trashed'), fn (Builder $q) => $q->onlyTrashed());
    }

    /** @return array<string, int> */
    private function statusCounts(Request $request): array
    {
        $counts = [];

        foreach (MaterialStatus::cases() as $status) {
            $counts[$status->value] = (clone $this->filtered($request))->where('status', $status->value)->count();
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request): array
    {
        return [
            'courses' => Course::query()->orderBy('name')->get(['id', 'name', 'code']),
            'batches' => Batch::query()->with('course:id,name')->orderByDesc('id')->get(['id', 'code', 'name', 'course_id']),
            'types' => $this->allowedTypes(),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * Only the kinds the institute has switched on (`institute.material_allowed_types`). A kind that is
     * off is absent from the picker rather than present and refused on save.
     *
     * @return list<CourseResourceType>
     */
    private function allowedTypes(): array
    {
        $allowed = settings_repo()->get('institute.material_allowed_types');

        if (! is_array($allowed) || $allowed === []) {
            return CourseResourceType::cases();
        }

        return array_values(array_filter(
            CourseResourceType::cases(),
            static fn (CourseResourceType $t): bool => in_array($t->value, $allowed, true),
        ));
    }

    /**
     * @return list<array{type: string, id: int}>
     */
    private function targetsFrom(Request $request): array
    {
        $targets = $request->input('targets', []);

        if (! is_array($targets)) {
            return [];
        }

        $clean = [];

        foreach ($targets as $row) {
            if (! is_array($row) || ! isset($row['type'], $row['id'])) {
                continue;
            }

            $clean[] = ['type' => (string) $row['type'], 'id' => (int) $row['id']];
        }

        return $clean;
    }
}
