<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Admin\Cms\Concerns\RespondsForCms;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\ReorderSectionItemsRequest;
use App\Http\Requests\Cms\StoreSectionItemRequest;
use App\Http\Requests\Cms\ToggleEnabledRequest;
use App\Http\Requests\Cms\UpdateSectionItemRequest;
use App\Models\Cms\WebsiteSection;
use App\Models\Cms\WebsiteSectionItem;
use App\Services\Cms\SectionService;
use App\Support\Cms\SectionRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Repeater items inside a section — statistics, why-choose-us points, history entries, highlights,
 * links and badges (phase-03 §2.3, §7.1, §8.5 `x-cms.repeater`).
 *
 * Every route is `website_sections.edit`: an item change only reaches visitors when its section is
 * published (FT-45). `min` / `max`, the live-metric rule and the parent hash are `SectionService`'s.
 */
final class SectionItemController extends Controller
{
    use RespondsForCms;

    public function __construct(
        private readonly SectionService $sections,
    ) {}

    /**
     * Add an item (`admin.website.sections.items.store`, FT-18 — a ninth hero statistic is a 422).
     */
    public function store(StoreSectionItemRequest $request, WebsiteSection $section): Response
    {
        $this->authorize('website_sections.edit');
        $this->authorize('create', [WebsiteSectionItem::class, $section]);

        return $this->attempt($request, function () use ($request, $section): Response {
            $item = $this->sections->upsertItem($section, (string) $request->group(), $request->itemPayload());

            return $this->done($request, 'Entry added to the draft.', null, $this->state($item));
        }, field: 'item');
    }

    /**
     * Update an item (`admin.website.section-items.update`).
     */
    public function update(UpdateSectionItemRequest $request, WebsiteSectionItem $item): Response
    {
        $this->authorize('website_sections.edit');
        $this->authorize('update', $item);

        $section = $this->sectionOf($item);

        return $this->attempt($request, function () use ($request, $section, $item): Response {
            $item = $this->sections->upsertItem($section, (string) $item->group, $request->itemPayload(), $item);

            return $this->done($request, 'Entry saved to the draft.', null, $this->state($item));
        }, field: 'item');
    }

    /**
     * Show or hide an item (`admin.website.section-items.toggle`).
     */
    public function toggle(ToggleEnabledRequest $request, WebsiteSectionItem $item): Response
    {
        $this->authorize('website_sections.edit');
        $this->authorize('toggle', $item);

        $this->sectionOf($item);

        return $this->attempt($request, function () use ($request, $item): Response {
            $item = $this->sections->toggleItem($item, $request->enabled());

            return $this->done(
                $request,
                $request->enabled() ? 'Entry shown in the draft.' : 'Entry hidden in the draft.',
                null,
                $this->state($item),
            );
        });
    }

    /**
     * Delete an item (`admin.website.section-items.destroy`) — refused below the repeater's `min`.
     */
    public function destroy(Request $request, WebsiteSectionItem $item): Response
    {
        $this->authorize('website_sections.edit');
        $this->authorize('delete', $item);

        $section = $this->sectionOf($item);

        return $this->attempt($request, function () use ($request, $item, $section): Response {
            $this->sections->deleteItem($item);

            return $this->done($request, 'Entry deleted from the draft.', redirect()->route('admin.website.sections.edit', $section));
        }, field: 'item');
    }

    /**
     * Reorder one repeater from the full ordered id list (`admin.website.sections.items.reorder`).
     */
    public function reorder(ReorderSectionItemsRequest $request, WebsiteSection $section, string $group): Response
    {
        $this->authorize('website_sections.edit');
        $this->authorize('reorder', [WebsiteSectionItem::class, $section]);

        $key = (string) $section->section_key;

        abort_unless(SectionRegistry::exists($key) && SectionRegistry::hasRepeater($key, $group), Response::HTTP_NOT_FOUND);

        return $this->attempt($request, function () use ($request, $section, $group): Response {
            $this->sections->reorderItems($section, $group, $request->orderedIds());

            return $this->done($request, 'Order saved to the draft.', null, ['order' => $request->orderedIds()]);
        }, field: 'order');
    }

    /**
     * The live (non-trashed) section an item belongs to; a trashed parent is a 404.
     */
    private function sectionOf(WebsiteSectionItem $item): WebsiteSection
    {
        /** @var WebsiteSection */
        return WebsiteSection::query()->whereKey((int) $item->website_section_id)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function state(WebsiteSectionItem $item): array
    {
        return [
            'id' => (int) $item->getKey(),
            'group' => (string) $item->group,
            'is_enabled' => (bool) $item->is_enabled,
            'sort_order' => (int) $item->sort_order,
        ];
    }
}
