<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\Cms\ContentStatus;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForCms;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\ChangeContentStatusRequest;
use App\Http\Requests\Cms\CmsListRequest;
use App\Http\Requests\Cms\ReorderFaqsRequest;
use App\Http\Requests\Cms\StoreFaqRequest;
use App\Http\Requests\Cms\UpdateFaqRequest;
use App\Models\Cms\Faq;
use App\Models\Cms\FaqCategory;
use App\Services\Cms\FaqService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FAQs — `admin.website.faqs.*` (phase-03 §7.4, §8.11): a category rail on the left, the selected
 * category's questions on the right, sortable within their category (or within the uncategorised
 * bucket).
 *
 * Status-gated and live (§2.15): a `draft` FAQ simply never renders. Answers are sanitised by
 * `FaqService` on write and again on render (INV-13). The `faqable` course hook is shown as a filter but
 * never written from this screen (§6.13).
 */
final class FaqController extends Controller
{
    use RespondsForCms;

    public function __construct(
        private readonly FaqService $faqs,
    ) {}

    public function index(CmsListRequest $request): View
    {
        $this->authorize('faqs.view_any');

        $category = $request->filterString('category');
        $status = $request->filterEnum('status', ContentStatus::class);
        $featured = $request->filterBool('featured');
        $attached = $request->filterBool('attached');
        $search = $request->searchTerm();

        $selected = $category !== null && $category !== 'uncategorised'
            ? FaqCategory::query()->find((int) $category)
            : null;

        abort_if($category !== null && $category !== 'uncategorised' && $selected === null, Response::HTTP_NOT_FOUND);

        $questions = Faq::query()
            ->when($selected !== null, static fn (Builder $query) => $query->where('faq_category_id', $selected->getKey()))
            ->when($category === 'uncategorised', static fn (Builder $query) => $query->whereNull('faq_category_id'))
            ->when($status instanceof ContentStatus, static fn (Builder $query) => $query->where('status', $status->value))
            ->when($featured !== null, static fn (Builder $query) => $query->where('is_featured', $featured))
            ->when($attached === true, static fn (Builder $query) => $query->whereNotNull('faqable_type'))
            ->when($attached === false, static fn (Builder $query) => $query->whereNull('faqable_type'))
            ->when($search !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($search): void {
                $inner->where('question', 'like', $this->like($search))
                    ->orWhere('answer', 'like', $this->like($search));
            }))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($this->perPage())
            ->withQueryString();

        $user = $this->actor($request);
        $onlyCategoryFilter = array_diff(array_keys($request->activeFilters()), ['category']) === [];

        return view('admin.cms.faqs.index', [
            'questions' => $questions,
            'categories' => FaqCategory::query()
                ->select('faq_categories.*')
                ->selectSub(
                    Faq::query()->selectRaw('COUNT(*)')->whereColumn('faqs.faq_category_id', 'faq_categories.id'),
                    'faqs_count'
                )
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'uncategorisedCount' => Faq::query()->whereNull('faq_category_id')->count(),
            'selectedCategory' => $selected,
            'category' => $category,
            'statusOptions' => ContentStatus::options(),
            'filters' => $request->activeFilters(),
            // Reordering posts the exact set of one bucket: only from an unfiltered, single-page bucket.
            'canReorder' => $category !== null && $onlyCategoryFilter && $questions->lastPage() === 1 && $user->can('faqs.edit'),
            'can' => [
                'create' => $user->can('faqs.create'),
                'edit' => $user->can('faqs.edit'),
                'toggle' => $user->can('faqs.change_status'),
                'delete' => $user->can('faqs.delete'),
                'categories' => $user->can('faq_categories.view_any'),
            ],
        ]);
    }

    public function store(StoreFaqRequest $request): Response
    {
        $this->authorize('faqs.create');

        return $this->attempt($request, function () use ($request): Response {
            $faq = $this->faqs->save($request->faqPayload());

            return $this->done(
                $request,
                'Question added as a draft.',
                redirect()->route('admin.website.faqs.index', array_filter(['category' => $faq->faq_category_id])),
                ['id' => (int) $faq->getKey()],
            );
        }, field: 'faq_category_id');
    }

    public function update(UpdateFaqRequest $request, Faq $faq): Response
    {
        $this->authorize('faqs.edit');
        $this->authorize('update', $faq);

        return $this->attempt($request, function () use ($request, $faq): Response {
            $faq = $this->faqs->save($request->faqPayload(), $faq);

            return $this->done(
                $request,
                'Question saved.',
                redirect()->route('admin.website.faqs.index', array_filter(['category' => $faq->faq_category_id])),
                ['id' => (int) $faq->getKey()],
            );
        }, field: 'faq_category_id');
    }

    public function toggle(ChangeContentStatusRequest $request, Faq $faq): Response
    {
        $this->authorize('faqs.change_status');
        $this->authorize('toggle', $faq);

        $status = $request->contentStatus();

        return $this->attempt($request, function () use ($request, $faq, $status): Response {
            $faq = $this->faqs->toggle($faq, $status);

            return $this->done(
                $request,
                sprintf('Question is now %s.', mb_strtolower($status->label())),
                null,
                ['id' => (int) $faq->getKey(), 'status' => $status->value],
            );
        });
    }

    public function reorder(ReorderFaqsRequest $request): Response
    {
        $this->authorize('faqs.edit');

        $categoryId = $request->categoryId();
        $category = $categoryId === null ? null : FaqCategory::query()->findOrFail($categoryId);

        return $this->attempt($request, function () use ($request, $category): Response {
            $this->faqs->reorder($category, $request->orderedIds());

            return $this->done($request, 'Question order saved.', null, ['order' => $request->orderedIds()]);
        }, field: 'order');
    }

    public function destroy(Request $request, Faq $faq): Response
    {
        $this->authorize('faqs.delete');
        $this->authorize('delete', $faq);

        $categoryId = $faq->faq_category_id;

        return $this->attempt($request, function () use ($request, $faq, $categoryId): Response {
            $this->faqs->delete($faq);

            return $this->done(
                $request,
                'Question deleted.',
                redirect()->route('admin.website.faqs.index', array_filter(['category' => $categoryId])),
            );
        }, Response::HTTP_FORBIDDEN);
    }
}
