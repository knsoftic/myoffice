<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\Cms\ButtonStyle;
use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\CtaVariant;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForCms;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\ChangeContentStatusRequest;
use App\Http\Requests\Cms\CmsListRequest;
use App\Http\Requests\Cms\StoreCtaBlockRequest;
use App\Http\Requests\Cms\UpdateCtaBlockRequest;
use App\Models\Cms\CtaBlock;
use App\Models\Cms\MediaAsset;
use App\Services\Cms\CtaBlockService;
use App\Services\Cms\Exceptions\ContentActionNotAllowedException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reusable CTA blocks — `admin.website.cta-blocks.*` (phase-03 §7.4, §8.11).
 *
 * Referenced, never copied: a section points at a block by `cta_block_id`, so changing a button once
 * changes it everywhere. Delete is refused while the block is used, **with the usage list** in the
 * refusal (`CtaBlockPolicy::delete()`, `CtaBlockService::delete()`).
 */
final class CtaBlockController extends Controller
{
    use RespondsForCms;

    public function __construct(
        private readonly CtaBlockService $blocks,
    ) {}

    public function index(CmsListRequest $request): View
    {
        $this->authorize('website_cta_blocks.view_any');

        $status = $request->filterEnum('status', ContentStatus::class);
        $variant = $request->filterEnum('variant', CtaVariant::class);
        $unused = $request->filterBool('unused');

        $blocks = CtaBlock::query()
            ->search($request->searchTerm())
            ->when($status instanceof ContentStatus, static fn (Builder $query) => $query->where('status', $status->value))
            ->when($variant instanceof CtaVariant, static fn (Builder $query) => $query->where('variant', $variant->value))
            ->when($unused === true, static fn (Builder $query) => $query->unused())
            ->when($unused === false, static fn (Builder $query) => $query->where('usage_count', '>', 0))
            ->ordered()
            ->paginate($this->perPage())
            ->withQueryString();

        $user = $this->actor($request);

        return view('admin.cms.cta-blocks.index', [
            'blocks' => $blocks,
            'backgrounds' => MediaAsset::query()
                ->whereIn('id', $blocks->getCollection()->pluck('background_media_id')->filter()->unique()->values())
                ->get()
                ->keyBy('id'),
            'statusOptions' => ContentStatus::options(),
            'variantOptions' => CtaVariant::options(),
            'styleOptions' => ButtonStyle::options(),
            'filters' => $request->activeFilters(),
            'can' => [
                'create' => $user->can('website_cta_blocks.create'),
                'edit' => $user->can('website_cta_blocks.edit'),
                'toggle' => $user->can('website_cta_blocks.change_status'),
                'delete' => $user->can('website_cta_blocks.delete'),
            ],
        ]);
    }

    public function store(StoreCtaBlockRequest $request): Response
    {
        $this->authorize('website_cta_blocks.create');

        return $this->attempt($request, function () use ($request): Response {
            $block = $this->blocks->save($request->ctaPayload());

            return $this->done(
                $request,
                sprintf('CTA block "%s" was created as a draft.', $block->name),
                redirect()->route('admin.website.cta-blocks.index'),
                ['id' => (int) $block->getKey()],
            );
        }, field: 'key');
    }

    public function edit(Request $request, CtaBlock $ctaBlock): View
    {
        $this->authorize('website_cta_blocks.view');
        $this->authorize('view', $ctaBlock);

        return view('admin.cms.cta-blocks.edit', [
            'block' => $ctaBlock,
            'background' => $ctaBlock->background_media_id === null ? null : MediaAsset::query()->find((int) $ctaBlock->background_media_id),
            'usage' => $this->blocks->usage($ctaBlock),
            'variantOptions' => CtaVariant::options(),
            'styleOptions' => ButtonStyle::options(),
            'canEdit' => $request->user()?->can('update', $ctaBlock) === true,
            'canChangeKey' => $request->user()?->can('changeKey', $ctaBlock) === true,
        ]);
    }

    public function update(UpdateCtaBlockRequest $request, CtaBlock $ctaBlock): Response
    {
        $this->authorize('website_cta_blocks.edit');
        $this->authorize('update', $ctaBlock);

        if ($request->has('key') && $request->input('key') !== $ctaBlock->key) {
            $this->authorize('changeKey', $ctaBlock);
        }

        return $this->attempt($request, function () use ($request, $ctaBlock): Response {
            $block = $this->blocks->save($request->ctaPayload(), $ctaBlock);

            return $this->done($request, sprintf('CTA block "%s" was saved.', $block->name), redirect()->route('admin.website.cta-blocks.index'));
        }, field: 'key');
    }

    /**
     * Change the block's status — through `CtaBlockService::save()` with the status alone.
     */
    public function toggle(ChangeContentStatusRequest $request, CtaBlock $ctaBlock): Response
    {
        $this->authorize('website_cta_blocks.change_status');
        $this->authorize('toggle', $ctaBlock);

        $status = $request->contentStatus();

        return $this->attempt($request, function () use ($request, $ctaBlock, $status): Response {
            $block = $this->blocks->save(['status' => $status->value], $ctaBlock);

            return $this->done(
                $request,
                sprintf('CTA block "%s" is now %s.', $block->name, mb_strtolower($status->label())),
                null,
                ['id' => (int) $block->getKey(), 'status' => $status->value],
            );
        });
    }

    /**
     * The sections and pages referencing the block (§8.11 "used in 3 places" popover).
     */
    public function usage(Request $request, CtaBlock $ctaBlock): View|JsonResponse
    {
        $this->authorize('website_cta_blocks.view');
        $this->authorize('usage', $ctaBlock);

        $usage = $this->blocks->usage($ctaBlock)->values();

        if ($request->expectsJson()) {
            return new JsonResponse(['id' => (int) $ctaBlock->getKey(), 'usage' => $usage->all()]);
        }

        return view('admin.cms.cta-blocks.usage', [
            'block' => $ctaBlock,
            'usage' => $usage,
        ]);
    }

    public function destroy(Request $request, CtaBlock $ctaBlock): Response
    {
        $this->authorize('website_cta_blocks.delete');

        if ($request->user()?->cannot('delete', $ctaBlock)) {
            $usage = $this->blocks->usage($ctaBlock);

            abort_if($usage->isEmpty(), Response::HTTP_FORBIDDEN);

            return $this->inUse($request, sprintf('"%s" is still used and cannot be deleted. Remove it there first.', $ctaBlock->name), $usage);
        }

        try {
            $this->blocks->delete($ctaBlock);
        } catch (ContentActionNotAllowedException $exception) {
            // A Super Admin bypasses the policy; the service still refuses, and the refusal lists the places.
            return $this->inUse($request, $exception->getMessage(), $this->blocks->usage($ctaBlock));
        }

        return $this->done($request, sprintf('CTA block "%s" was deleted.', $ctaBlock->name), redirect()->route('admin.website.cta-blocks.index'));
    }
}
