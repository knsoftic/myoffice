<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\PageLayout;
use App\Enums\Cms\SectionPlacement;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForCms;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\CmsListRequest;
use App\Http\Requests\Cms\PublishContentRequest;
use App\Http\Requests\Cms\RemoveSectionRequest;
use App\Http\Requests\Cms\ReorderSectionsRequest;
use App\Http\Requests\Cms\StoreSectionRequest;
use App\Http\Requests\Cms\ToggleSectionRequest;
use App\Http\Requests\Cms\UnpublishContentRequest;
use App\Http\Requests\Cms\UpdateSectionRequest;
use App\Models\Cms\CmsRevision;
use App\Models\Cms\CtaBlock;
use App\Models\Cms\Faq;
use App\Models\Cms\FaqCategory;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\Menu;
use App\Models\Cms\Page;
use App\Models\Cms\WebsiteSection;
use App\Models\User;
use App\Services\Cms\ContentPublisher;
use App\Services\Cms\SectionService;
use App\Services\Cms\StatisticsProvider;
use App\Support\Cms\SectionRegistry;
use DateTimeInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The section manager and editor (phase-03 §7.1, §8.4, §8.5): place, list, edit, reorder, publish,
 * unpublish, enable/disable, duplicate and remove the sections of a placement.
 *
 * Thin by contract: every write is one call into `SectionService` (drafts, order, toggles, lifecycle) or
 * `ContentPublisher` (publish / unpublish). Authorization is explicit in every action — the same
 * permission the route's `can:` names, plus the policy where a record-level rule exists
 * (`WebsiteSectionPolicy::delete()` refuses a required type, INV-7). The services enforce every rule
 * again, because a Super Admin bypasses policies.
 */
final class SectionController extends Controller
{
    use RespondsForCms;

    /** A placement is bounded by the registry; this page size keeps the full set on one sortable page. */
    private const PLACEMENT_PAGE_SIZE = 100;

    /** How many questions the FAQ section's picker lists. */
    private const FAQ_CHOICES_LIMIT = 500;

    public function __construct(
        private readonly SectionService $sections,
        private readonly ContentPublisher $publisher,
    ) {}

    /**
     * The sections of one placement (`admin.website.sections.index`).
     */
    public function index(CmsListRequest $request, SectionPlacement $placement): View
    {
        $this->authorize('website_sections.view_any');

        $page = $this->pageFor($placement, $request->filterId('page_id'));
        $status = $request->filterEnum('status', ContentStatus::class);
        $enabled = $request->filterString('enabled');
        $unpublished = $request->filterBool('unpublished');
        $search = $request->searchTerm();

        $sections = WebsiteSection::query()
            ->where('placement', $placement->value)
            ->when($page === null, static fn (Builder $query) => $query->whereNull('page_id'))
            ->when($page !== null, static fn (Builder $query) => $query->where('page_id', $page->getKey()))
            ->when($status instanceof ContentStatus, static fn (Builder $query) => $query->where('status', $status->value))
            ->when($enabled !== null, static fn (Builder $query) => $query->where('is_enabled', $enabled === 'enabled'))
            ->when($unpublished !== null, static fn (Builder $query) => $query->where('has_unpublished_changes', $unpublished))
            ->when($search !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', $this->like($search))
                    ->orWhere('section_key', 'like', $this->like($search))
                    ->orWhere('anchor', 'like', $this->like($search));
            }))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(self::PLACEMENT_PAGE_SIZE)
            ->withQueryString();

        $filtered = $request->activeFilters() !== [] && array_keys($request->activeFilters()) !== ['page_id'];

        return view('admin.cms.sections.index', [
            'placement' => $placement,
            'page' => $page,
            'sections' => $sections,
            'types' => $this->typeLabels(),
            'publishers' => $this->userNames($sections->getCollection()->pluck('published_by')->all()),
            'placementTabs' => $this->placementTabs(),
            'addable' => $this->addableTypes($placement, $page),
            // Reordering posts the exact current set (INV-5): impossible from a filtered or partial list.
            'canReorder' => ! $filtered && $sections->lastPage() === 1 && (SectionRegistry::placements()[$placement->value]['allows_custom_order'] ?? true),
            'statusOptions' => ContentStatus::options(),
            'filters' => $request->activeFilters(),
            'can' => [
                'create' => $request->user()?->can('website_sections.create') === true,
                'edit' => $request->user()?->can('website_sections.edit') === true,
                'publish' => $request->user()?->can('website_sections.change_status') === true,
                'delete' => $request->user()?->can('website_sections.delete') === true,
                'revisions' => $request->user()?->can('website_sections.view_logs') === true,
            ],
        ]);
    }

    /**
     * The add-section list for a placement (`admin.website.sections.available`, §8.4): every type the
     * registry allows here, grouped, with a unique type that is already placed shown **disabled with the
     * reason** rather than hidden.
     */
    public function available(CmsListRequest $request, SectionPlacement $placement): View|JsonResponse
    {
        $this->authorize('website_sections.create');

        $types = $this->addableTypes($placement, $this->pageFor($placement, $request->filterId('page_id')));

        if ($request->expectsJson()) {
            return new JsonResponse(['placement' => $placement->value, 'groups' => SectionRegistry::groups(), 'types' => array_values($types)]);
        }

        return view('admin.cms.sections.available', [
            'placement' => $placement,
            'groups' => SectionRegistry::groups(),
            'types' => $types,
        ]);
    }

    /**
     * Place a section (`admin.website.sections.store`, FT-01..FT-05).
     */
    public function store(StoreSectionRequest $request, SectionPlacement $placement): Response
    {
        $this->authorize('website_sections.create');

        $page = $this->pageFor($placement, $request->pageId());

        return $this->attempt($request, function () use ($request, $placement, $page): Response {
            $section = $this->sections->place($request->sectionKey(), $placement, $page, $request->sectionName());

            return $this->done(
                $request,
                sprintf('%s section added as a draft.', SectionRegistry::label((string) $section->section_key)),
                redirect()->route('admin.website.sections.edit', $section),
                ['id' => (int) $section->getKey(), 'edit_url' => route('admin.website.sections.edit', $section)],
            );
        }, field: 'section_key');
    }

    /**
     * The section editor (`admin.website.sections.edit`, §8.5-§8.8).
     */
    public function edit(Request $request, WebsiteSection $section): View
    {
        $this->authorize('website_sections.view');
        $this->authorize('view', $section);

        $key = (string) $section->section_key;
        $orphaned = ! SectionRegistry::exists($key);
        $canonical = $this->sections->canonicalPayload($section);
        $user = $this->actor($request);

        $assetIds = [];

        foreach ((array) ($canonical['media'] ?? []) as $ids) {
            array_push($assetIds, ...array_map('intval', (array) $ids));
        }

        foreach ((array) ($canonical['items'] ?? []) as $items) {
            foreach ((array) $items as $item) {
                if (($item['media_asset_id'] ?? null) !== null) {
                    $assetIds[] = (int) $item['media_asset_id'];
                }
            }
        }

        $fieldTypes = $orphaned ? [] : array_column(SectionRegistry::fields($key), 'type');

        return view('admin.cms.sections.edit', [
            'section' => $section,
            'placement' => $this->placementOf($section),
            'orphaned' => $orphaned,
            'type' => $orphaned ? null : SectionRegistry::type($key),
            'fields' => $orphaned ? [] : SectionRegistry::fields($key),
            'repeaters' => $orphaned ? [] : SectionRegistry::repeaters($key),
            'mediaRoles' => $orphaned ? [] : SectionRegistry::mediaRoles($key),
            'tabs' => SectionRegistry::TABS,
            'draft' => $canonical,
            'assets' => MediaAsset::query()->whereIn('id', array_values(array_unique($assetIds)))->get()->keyBy('id'),
            'mediaLibrary' => $this->mediaLibrary(),
            'options' => [
                'cta_blocks' => in_array(SectionRegistry::TYPE_CTA_REF, $fieldTypes, true)
                    ? CtaBlock::query()->orderBy('name')->get(['id', 'key', 'name', 'status'])
                    : collect(),
                'menus' => in_array(SectionRegistry::TYPE_MENU_REF, $fieldTypes, true)
                    ? Menu::query()->orderBy('name')->get(['id', 'name', 'slug', 'location', 'is_active'])
                    : collect(),
                'faq_categories' => in_array(SectionRegistry::TYPE_FAQ_CATEGORY_REF, $fieldTypes, true)
                    ? FaqCategory::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'slug', 'is_enabled'])
                    : collect(),
                'pages' => in_array(SectionRegistry::TYPE_PAGE_REF, $fieldTypes, true)
                    ? Page::query()->orderBy('title')->get(['id', 'title', 'slug', 'status'])
                    : collect(),
                // The hand-picked questions picker of a `faq` section (§2.11, `faq_website_section`).
                'faqs' => $key === 'faq' && ! $orphaned
                    ? Faq::query()
                        ->with('category:id,name')
                        ->orderBy('faq_category_id')
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->limit(self::FAQ_CHOICES_LIMIT)
                        ->get(['id', 'question', 'status', 'faq_category_id'])
                    : collect(),
            ],
            // Resolved live counts for the statistics repeaters' inline "live" chip (§8.7, INV-12).
            'statistics' => ! $orphaned && (SectionRegistry::hasRepeater($key, 'statistic'))
                ? app(StatisticsProvider::class)->all()
                : [],
            'publisher' => $section->published_by === null ? null : User::query()->whereKey((int) $section->published_by)->value('name'),
            'revisionCount' => CmsRevision::query()
                ->where('revisionable_type', $section->getMorphClass())
                ->where('revisionable_id', $section->getKey())
                ->count(),
            'previewUrl' => Route::has('site.preview.section') ? route('site.preview.section', $section) : null,
            'can' => [
                'edit' => $user->can('update', $section),
                'publish' => $user->can('publish', $section),
                'duplicate' => $user->can('duplicate', $section),
                'delete' => $user->can('delete', $section),
                'revisions' => $user->can('website_sections.view_logs'),
            ],
        ]);
    }

    /**
     * Save the draft, the name / anchor, and optionally publish (`admin.website.sections.update`).
     */
    public function update(UpdateSectionRequest $request, WebsiteSection $section): Response
    {
        $this->authorize('website_sections.edit');
        $this->authorize('update', $section);

        if ($request->wantsPublish()) {
            $this->authorize('website_sections.change_status');
            $this->authorize('publish', $section);
        }

        return $this->attempt($request, function () use ($request, $section): Response {
            if ($request->touchesDraft()) {
                $section = $this->sections->saveDraft($section, $request->contentPayload(), $request->mediaPayload());
            }

            // A `faq` section's hand-picked questions (§2.11): a draft write, live only on publish.
            if ($request->touchesFaqs()) {
                $section = $this->sections->syncFaqs($section, $request->faqPayload());
            }

            if ($request->touchesName()) {
                $section = $this->sections->rename(
                    $section,
                    $request->has('name') ? $request->validated('name') : $section->name,
                    $request->has('anchor') ? $request->validated('anchor') : $section->anchor,
                );
            }

            if ($request->wantsPublish()) {
                $section = $this->publisher->publish($section);

                return $this->done($request, 'Draft saved and published.', null, $this->state($section));
            }

            return $this->done($request, 'Draft saved. Visitors still see the live version until it is published.', null, $this->state($section));
        });
    }

    /**
     * Reorder a placement from the full ordered id list (`admin.website.sections.reorder`, INV-5, FT-15).
     */
    public function reorder(ReorderSectionsRequest $request): Response
    {
        $this->authorize('website_sections.edit');

        $placement = $request->placement();
        $page = $this->pageFor($placement, $request->pageId());

        return $this->attempt($request, function () use ($request, $placement, $page): Response {
            $this->sections->reorder($placement, $page, $request->orderedIds());

            return $this->done($request, 'Section order saved.', null, ['order' => $request->orderedIds()]);
        }, field: 'order');
    }

    /**
     * Publish the draft (`admin.website.sections.publish`, FT-06, FT-09, FT-13).
     */
    public function publish(PublishContentRequest $request, WebsiteSection $section): Response
    {
        $this->authorize('website_sections.change_status');
        $this->authorize('publish', $section);

        return $this->attempt($request, function () use ($request, $section): Response {
            $section = $this->publisher->publish($section, $request->label());

            return $this->done($request, 'Section published. The public site shows it now.', null, $this->state($section));
        });
    }

    /**
     * Take the section off the public site, keeping its snapshot (`admin.website.sections.unpublish`, FT-10).
     */
    public function unpublish(UnpublishContentRequest $request, WebsiteSection $section): Response
    {
        $this->authorize('website_sections.change_status');
        $this->authorize('unpublish', $section);

        return $this->attempt($request, function () use ($request, $section): Response {
            $section = $this->publisher->unpublish($section, (string) $request->reason());

            return $this->done($request, 'Section unpublished. The last published version is kept.', null, $this->state($section));
        });
    }

    /**
     * Enable or disable without touching `status` (`admin.website.sections.toggle`, FT-16).
     */
    public function toggle(ToggleSectionRequest $request, WebsiteSection $section): Response
    {
        $this->authorize('website_sections.change_status');
        $this->authorize('toggle', $section);

        return $this->attempt($request, function () use ($request, $section): Response {
            $section = $this->sections->toggle($section, $request->enabled(), $request->reason());

            return $this->done($request, $request->enabled() ? 'Section enabled.' : 'Section disabled. It no longer renders on the public site.', null, $this->state($section));
        });
    }

    /**
     * Copy a repeatable section as a new draft (`admin.website.sections.duplicate`).
     */
    public function duplicate(Request $request, WebsiteSection $section): Response
    {
        $this->authorize('website_sections.create');
        $this->authorize('duplicate', $section);

        return $this->attempt($request, function () use ($request, $section): Response {
            $copy = $this->sections->duplicate($section);

            return $this->done(
                $request,
                'Section duplicated as a draft.',
                redirect()->route('admin.website.sections.edit', $copy),
                ['id' => (int) $copy->getKey(), 'edit_url' => route('admin.website.sections.edit', $copy)],
            );
        });
    }

    /**
     * Remove a placed section (`admin.website.sections.destroy`). Required types are disable-only (INV-7).
     */
    public function destroy(RemoveSectionRequest $request, WebsiteSection $section): Response
    {
        $this->authorize('website_sections.delete');
        $this->authorize('delete', $section);

        $placement = $this->placementOf($section);
        $pageId = $section->page_id === null ? null : (int) $section->page_id;

        return $this->attempt($request, function () use ($request, $section, $placement, $pageId): Response {
            $this->sections->remove($section, (string) $request->reason());

            return $this->done(
                $request,
                'Section removed.',
                redirect()->route('admin.website.sections.index', array_filter([
                    'placement' => $placement->value,
                    'page_id' => $pageId,
                ])),
            );
        }, Response::HTTP_FORBIDDEN);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The page a `page` placement belongs to (404 when absent or not composed of sections); null for
     * every other placement.
     */
    private function pageFor(SectionPlacement $placement, ?int $pageId): ?Page
    {
        if (! $placement->allowsPage()) {
            return null;
        }

        abort_if($pageId === null, Response::HTTP_NOT_FOUND);

        /** @var Page */
        return Page::query()
            ->whereKey($pageId)
            ->where('layout', PageLayout::Sections->value)
            ->firstOrFail();
    }

    private function placementOf(WebsiteSection $section): SectionPlacement
    {
        $placement = $section->placement;

        return $placement instanceof SectionPlacement ? $placement : SectionPlacement::from((string) $placement);
    }

    /**
     * The registry types allowed in a placement, each flagged when a unique instance already exists.
     *
     * @return array<string, array<string, mixed>>
     */
    private function addableTypes(SectionPlacement $placement, ?Page $page): array
    {
        $placed = WebsiteSection::query()
            ->where('placement', $placement->value)
            ->when($page === null, static fn (Builder $query) => $query->whereNull('page_id'))
            ->when($page !== null, static fn (Builder $query) => $query->where('page_id', $page->getKey()))
            ->get(['id', 'section_key'])
            ->keyBy('section_key');

        $types = [];

        foreach (SectionRegistry::forPlacement($placement) as $key => $type) {
            $existing = $type['unique'] ? $placed->get($key) : null;

            $types[$key] = [
                'key' => $key,
                'label' => $type['label'],
                'description' => $type['description'],
                'icon' => $type['icon'],
                'group' => $type['group'],
                'unique' => $type['unique'],
                'required' => $type['required'],
                'disabled' => $existing !== null,
                'reason' => $existing !== null
                    ? sprintf('Only one %s per page — edit the existing one.', mb_strtolower((string) $type['label']))
                    : null,
                'existing_url' => $existing !== null ? route('admin.website.sections.edit', $existing) : null,
            ];
        }

        return $types;
    }

    /**
     * Home, Header and Footer, then one tab per page composed of sections (§8.4).
     *
     * @return list<array<string, mixed>>
     */
    private function placementTabs(): array
    {
        $tabs = [];

        foreach (SectionRegistry::placements() as $value => $placement) {
            if ($value === SectionPlacement::Page->value) {
                continue;
            }

            $tabs[] = ['placement' => $value, 'page_id' => null, 'label' => $placement['label']];
        }

        foreach (Page::query()->where('layout', PageLayout::Sections->value)->orderBy('title')->get(['id', 'title']) as $page) {
            $tabs[] = ['placement' => SectionPlacement::Page->value, 'page_id' => (int) $page->getKey(), 'label' => (string) $page->title];
        }

        return $tabs;
    }

    /**
     * `section_key` => registry label, for rows (an orphaned key simply has no entry).
     *
     * @return array<string, string>
     */
    private function typeLabels(): array
    {
        $labels = [];

        foreach (SectionRegistry::types() as $key => $type) {
            $labels[$key] = (string) $type['label'];
        }

        return $labels;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($ids, 'is_numeric')))));

        return $ids === [] ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * The row state a `fetch()` caller re-renders its badges from.
     *
     * @return array<string, mixed>
     */
    private function state(WebsiteSection $section): array
    {
        $status = $section->status;
        $publishedAt = $section->published_at;

        return [
            'id' => (int) $section->getKey(),
            'status' => $status instanceof ContentStatus ? $status->value : (string) $status,
            'is_enabled' => (bool) $section->is_enabled,
            'has_unpublished_changes' => (bool) $section->has_unpublished_changes,
            'published_at' => $publishedAt instanceof DateTimeInterface ? $publishedAt->format(DATE_ATOM) : null,
        ];
    }
}
