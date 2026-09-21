<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\CourseLevel;
use App\Enums\CourseStatus;
use App\Enums\DeliveryMode;
use App\Enums\DurationUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\StoreCourseRequest;
use App\Http\Requests\Admin\Institute\UpdateCourseRequest;
use App\Models\Branch;
use App\Models\Institute\Course;
use App\Models\Institute\CourseCategory;
use App\Services\Institute\CourseService;
use App\Support\CsvWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The catalogue work surface — `admin.courses.*` (§62, phase-14-17 §7.1, §8.2–8.4).
 *
 * **Every fee is gated separately** (§4.2). `courses.view_any` opens the register; `courses.view_financial`
 * fills in the three fee columns, and a reader without it gets a list with no fee column at all —
 * absent, not blank. The Form Request strips the same fields on the way in, so hiding the tab is the
 * courtesy and the strip is the control (FT-10).
 *
 * **A branch user sees their branch plus everything offered everywhere.** `branch_id = null` means "at
 * every branch", so it is visible to all rather than to none.
 *
 * Nothing here computes: `CourseService` owns the code, the slug, the status ladder, the fee arithmetic
 * and the outline caches.
 */
final class CourseController extends Controller
{
    public function __construct(
        private readonly CourseService $courses,
    ) {}

    public function index(Request $request): View
    {
        $seesMoney = (bool) $request->user()?->can('viewFinancial', Course::class);

        $courses = $this->filtered($request)
            ->with(['category:id,name,slug', 'branch:id,name'])
            ->catalogueOrder()
            ->paginate(20)
            ->withQueryString();

        return view('admin.courses.index', [
            'courses' => $courses,
            'seesMoney' => $seesMoney,
            'categories' => CourseCategory::query()->ordered()->get(['id', 'name']),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => CourseStatus::cases(),
            'levels' => CourseLevel::cases(),
            'modes' => DeliveryMode::cases(),
            'counts' => $this->statusCounts($request),
            'canCreate' => (bool) $request->user()?->can('create', Course::class),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.courses.create', $this->formData($request));
    }

    public function store(StoreCourseRequest $request): RedirectResponse
    {
        $course = $this->courses->create($request->validated(), $request->user());

        return redirect()->route('admin.courses.show', $course)->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was created as a draft. Add an outline, then publish it.', $course->name),
        ]);
    }

    public function show(Request $request, Course $course): View
    {
        $course->load([
            'category',
            'branch',
            'modules' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'modules.topics' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'modules.topics.lectures' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'modules.topics.resources' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'modules.topics.assignmentBlueprints' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
        ]);

        return view('admin.courses.show', [
            'course' => $course,
            'seesMoney' => (bool) $request->user()?->can('viewFinancial', Course::class),
            'gaps' => $course->publishingGaps(),
            'canEdit' => (bool) $request->user()?->can('update', $course),
            'canChangeStatus' => (bool) $request->user()?->can('changeStatus', $course),
            'canDelete' => (bool) $request->user()?->can('delete', $course),
            'canEditOutline' => (bool) $request->user()?->can('course_outline.edit'),
            'canCreateOutline' => (bool) $request->user()?->can('course_outline.create'),
            'canDeleteOutline' => (bool) $request->user()?->can('course_outline.delete'),
            'canToggleOutline' => (bool) $request->user()?->can('course_outline.change_status'),
            'canUpload' => (bool) $request->user()?->can('course_outline.upload'),
            'faqs' => $course->faqs()->orderBy('sort_order')->get(),
        ]);
    }

    public function edit(Request $request, Course $course): View
    {
        return view('admin.courses.edit', array_merge($this->formData($request), ['course' => $course]));
    }

    public function update(UpdateCourseRequest $request, Course $course): RedirectResponse
    {
        $this->courses->update($course, $request->validated(), $request->user());

        return redirect()->route('admin.courses.show', $course->fresh())->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was updated.', $course->fresh()->name),
        ]);
    }

    public function destroy(Course $course): RedirectResponse
    {
        if ($course->hasSalesHistory()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => sprintf(
                    '%s has batches, admissions or fees against it. A course that has been sold is '
                    .'archived, never deleted — the history has to keep pointing somewhere.',
                    $course->name,
                ),
            ]);
        }

        $course->delete();

        return redirect()->route('admin.courses.index')->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was deleted. Nothing had been sold against it.', $course->name),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The status ladder (§2.30.1)
    |--------------------------------------------------------------------------
    */

    public function publish(Request $request, Course $course): RedirectResponse
    {
        $published = $this->courses->publish($course, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s is live at /courses/%s.', $published->name, $published->slug),
        ]);
    }

    public function unpublish(Request $request, Course $course): RedirectResponse
    {
        $this->courses->unpublish($course, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was taken off the site. It keeps its address for when it goes back.', $course->name),
        ]);
    }

    public function archive(Request $request, Course $course): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'Retiring a course is something somebody will ask about later.',
        ]);

        $this->courses->archive($course, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                '%s is retired. Every batch, admission and fee against it is untouched.',
                $course->name,
            ),
        ]);
    }

    public function revive(Request $request, Course $course): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $this->courses->revive($course, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s is back as a draft. Check it over before publishing it again.', $course->name),
        ]);
    }

    public function featured(Request $request, Course $course): RedirectResponse
    {
        $validated = $request->validate(['featured' => ['required', 'boolean']]);

        $this->courses->setFeatured($course, (bool) $validated['featured'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $validated['featured']
                ? sprintf('%s is pinned to the top of the catalogue.', $course->name)
                : sprintf('%s is no longer featured.', $course->name),
        ]);
    }

    public function duplicate(Request $request, Course $course): RedirectResponse
    {
        $copy = $this->courses->duplicate($course, [], $request->user());

        return redirect()->route('admin.courses.edit', $copy)->with('toast', [
            'type' => 'success',
            'message' => 'A copy was made as a draft, with the whole outline and none of the students.',
        ]);
    }

    public function reorder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ]);

        $this->courses->reorder($validated['order'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'The catalogue order was saved.']);
    }

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    */

    public function export(Request $request, string $format): StreamedResponse
    {
        abort_unless($format === 'csv', 404);

        $seesMoney = (bool) $request->user()?->can('viewFinancial', Course::class);

        $headers = ['Code', 'Name', 'Category', 'Level', 'Mode', 'Duration', 'Status', 'Modules', 'Topics', 'Lectures'];

        if ($seesMoney) {
            // Absent, not blank: the file has the same shape as the screen the person was looking at.
            $headers = array_merge($headers, ['Course fee', 'Admission fee', 'Registration fee', 'Total']);
        }

        return (new CsvWriter)->download(
            'courses-'.app_date(now(), 'Y-m-d').'.csv',
            $headers,
            CsvWriter::rowsFrom(
                $this->filtered($request)->with(['category:id,name']),
                static function (Course $course) use ($seesMoney): array {
                    $row = [
                        (string) $course->code,
                        (string) $course->name,
                        (string) $course->category?->name,
                        $course->level->label(),
                        $course->delivery_mode->label(),
                        (string) $course->durationLabel(),
                        $course->status->label(),
                        (string) $course->modules_count,
                        (string) $course->topics_count,
                        (string) $course->lectures_count,
                    ];

                    return $seesMoney
                        ? array_merge($row, [
                            (string) $course->course_fee,
                            (string) $course->admission_fee,
                            (string) $course->registration_fee,
                            $course->totalFee(),
                        ])
                        : $row;
                },
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request): array
    {
        return [
            'categories' => CourseCategory::query()->active()->ordered()->get(['id', 'name']),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'levels' => CourseLevel::cases(),
            'modes' => DeliveryMode::cases(),
            'units' => DurationUnit::cases(),
            'seesMoney' => (bool) $request->user()?->can('viewFinancial', Course::class),
            'defaultClassDuration' => (int) setting('institute.default_class_duration', 60),
        ];
    }

    /**
     * @return Builder<Course>
     */
    private function filtered(Request $request): Builder
    {
        return Course::query()
            ->forBranch($request->user()?->branch_id === null ? null : (int) $request->user()->branch_id)
            ->search($request->input('q'))
            ->when($request->filled('category'), fn (Builder $q) => $q->where('course_category_id', (int) $request->input('category')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('level'), fn (Builder $q) => $q->where('level', (string) $request->input('level')))
            ->when($request->filled('mode'), fn (Builder $q) => $q->where('delivery_mode', (string) $request->input('mode')))
            ->when($request->filled('branch'), fn (Builder $q) => $q->where('branch_id', (int) $request->input('branch')))
            ->when($request->boolean('certificate'), fn (Builder $q) => $q->where('certificate_available', true))
            ->when($request->boolean('installments'), fn (Builder $q) => $q->where('installment_available', true))
            ->when($request->boolean('featured'), fn (Builder $q) => $q->where('is_featured', true));
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(Request $request): array
    {
        $branchId = $request->user()?->branch_id === null ? null : (int) $request->user()->branch_id;

        $counts = Course::query()
            ->forBranch($branchId)
            ->toBase()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $out = [];

        // Every case, including the empty ones: a card that disappeared when it hit zero would make the
        // row change shape, and "no drafts" is information.
        foreach (CourseStatus::cases() as $status) {
            $out[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        $out['featured'] = Course::query()->forBranch($branchId)->where('is_featured', true)->count();

        return $out;
    }
}
