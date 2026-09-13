<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Admin\Cms\Concerns\RespondsForCms;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\CmsListRequest;
use App\Http\Requests\Cms\ReorderFaqCategoriesRequest;
use App\Http\Requests\Cms\StoreFaqCategoryRequest;
use App\Http\Requests\Cms\UpdateFaqCategoryRequest;
use App\Models\Cms\Faq;
use App\Models\Cms\FaqCategory;
use App\Services\Cms\FaqService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FAQ categories — `admin.website.faq-categories.*` (phase-03 §7.4, §8.11): the rail's list, add,
 * rename / enable, reorder and delete.
 *
 * Deleting a category never deletes its questions: `faqs.faq_category_id` is `nullOnDelete`, so they
 * fall into the uncategorised bucket.
 */
final class FaqCategoryController extends Controller
{
    use RespondsForCms;

    public function __construct(
        private readonly FaqService $faqs,
    ) {}

    public function index(CmsListRequest $request): View
    {
        $this->authorize('faq_categories.view_any');

        $enabled = $request->filterString('enabled');
        $search = $request->searchTerm();

        $categories = FaqCategory::query()
            ->select('faq_categories.*')
            ->selectSub(
                Faq::query()->selectRaw('COUNT(*)')->whereColumn('faqs.faq_category_id', 'faq_categories.id'),
                'faqs_count'
            )
            ->when($enabled !== null, static fn (Builder $query) => $query->where('is_enabled', $enabled === 'enabled'))
            ->when($search !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', $this->like($search))
                    ->orWhere('slug', 'like', $this->like($search))
                    ->orWhere('description', 'like', $this->like($search));
            }))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($this->perPage())
            ->withQueryString();

        $user = $this->actor($request);

        return view('admin.cms.faq-categories.index', [
            'categories' => $categories,
            'filters' => $request->activeFilters(),
            'canReorder' => $request->activeFilters() === [] && $categories->lastPage() === 1 && $user->can('faq_categories.edit'),
            'can' => [
                'create' => $user->can('faq_categories.create'),
                'edit' => $user->can('faq_categories.edit'),
                'delete' => $user->can('faq_categories.delete'),
            ],
        ]);
    }

    public function store(StoreFaqCategoryRequest $request): Response
    {
        $this->authorize('faq_categories.create');

        return $this->attempt($request, function () use ($request): Response {
            $category = $this->faqs->saveCategory($request->categoryPayload());

            return $this->done(
                $request,
                sprintf('Category "%s" added.', $category->name),
                redirect()->route('admin.website.faq-categories.index'),
                ['id' => (int) $category->getKey()],
            );
        }, field: 'slug');
    }

    public function update(UpdateFaqCategoryRequest $request, FaqCategory $category): Response
    {
        $this->authorize('faq_categories.edit');
        $this->authorize('update', $category);

        return $this->attempt($request, function () use ($request, $category): Response {
            $category = $this->faqs->saveCategory($request->categoryPayload(), $category);

            return $this->done(
                $request,
                sprintf('Category "%s" saved.', $category->name),
                redirect()->route('admin.website.faq-categories.index'),
                ['id' => (int) $category->getKey(), 'is_enabled' => (bool) $category->is_enabled],
            );
        }, field: 'slug');
    }

    public function reorder(ReorderFaqCategoriesRequest $request): Response
    {
        $this->authorize('faq_categories.edit');

        return $this->attempt($request, function () use ($request): Response {
            $this->faqs->reorderCategories($request->orderedIds());

            return $this->done($request, 'Category order saved.', null, ['order' => $request->orderedIds()]);
        }, field: 'order');
    }

    public function destroy(Request $request, FaqCategory $category): Response
    {
        $this->authorize('faq_categories.delete');
        $this->authorize('delete', $category);

        $name = (string) $category->name;

        return $this->attempt($request, function () use ($request, $category, $name): Response {
            $this->faqs->deleteCategory($category);

            return $this->done(
                $request,
                sprintf('Category "%s" deleted. Its questions are now uncategorised.', $name),
                redirect()->route('admin.website.faq-categories.index'),
            );
        }, Response::HTTP_FORBIDDEN);
    }
}
