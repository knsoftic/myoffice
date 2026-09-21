<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\StoreCourseCategoryRequest;
use App\Models\Institute\CourseCategory;
use App\Services\Institute\CourseCategoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The catalogue's top level — `admin.course-categories.*` (§64, phase-14-17 §7.1, §8.1).
 *
 * One screen, because there are rarely more than a dozen categories and a separate create page for a
 * name and an icon would be a page load for two fields. Create and edit are modals over the list.
 *
 * Nothing here computes anything: `CourseCategoryService` owns the slug, the ordering and the cascade,
 * and the controller collects a form and reports what came back.
 */
final class CourseCategoryController extends Controller
{
    public function __construct(
        private readonly CourseCategoryService $categories,
    ) {}

    public function index(Request $request): View
    {
        $categories = CourseCategory::query()
            ->search($request->input('q'))
            ->when($request->input('state') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->input('state') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->ordered()
            // A live count beside the cached one: the cache is what the column shows, and this is what
            // the delete guard actually asks.
            ->withCount('courses')
            ->get();

        return view('admin.course-categories.index', [
            'categories' => $categories,
            'canCreate' => (bool) $request->user()?->can('create', CourseCategory::class),
            'canEdit' => (bool) $request->user()?->can('reorder', CourseCategory::class),
            'canChangeStatus' => (bool) $request->user()?->can('course_categories.change_status'),
            'canDelete' => (bool) $request->user()?->can('course_categories.delete'),
        ]);
    }

    public function store(StoreCourseCategoryRequest $request): RedirectResponse
    {
        $category = $this->categories->create($request->validated(), $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s added. Courses can be filed under it straight away.', $category->name),
        ]);
    }

    public function edit(CourseCategory $category): View
    {
        return view('admin.course-categories.edit', ['category' => $category]);
    }

    public function update(StoreCourseCategoryRequest $request, CourseCategory $category): RedirectResponse
    {
        $this->categories->update($category, $request->validated(), $request->user());

        return redirect()->route('admin.course-categories.index')->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was updated.', $category->fresh()->name),
        ]);
    }

    /**
     * The whole new arrangement, in one post.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ]);

        $this->categories->reorder($validated['order'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'The order was saved.']);
    }

    /**
     * Switch a category on or off — and only take its courses with it when explicitly asked.
     */
    public function toggle(Request $request, CourseCategory $category): RedirectResponse
    {
        $validated = $request->validate([
            'active' => ['required', 'boolean'],
            'cascade_courses' => ['nullable', 'boolean'],
        ]);

        $active = (bool) $validated['active'];
        $cascade = (bool) ($validated['cascade_courses'] ?? false);

        $moved = $active || ! $cascade
            ? 0
            : $category->courses()->where('status', 'published')->count();

        $this->categories->setActive($category, $active, $cascade, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $active
                ? sprintf('%s is visible again.', $category->name)
                : sprintf(
                    '%s is hidden from the site.%s',
                    $category->name,
                    $moved > 0
                        ? sprintf(' %d published %s moved to draft.', $moved, $moved === 1 ? 'course was' : 'courses were')
                        : ' Every course inside it kept the status it had.',
                ),
        ]);
    }

    public function destroy(CourseCategory $category): RedirectResponse
    {
        $this->categories->delete($category);

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was removed. Nothing was filed under it.', $category->name),
        ]);
    }
}
