<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\FaqSource;
use App\Enums\Cms\RevisionEvent;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\Page;
use App\Models\Cms\WebsiteSection;
use App\Models\Cms\WebsiteSectionItem;
use App\Services\Cms\Exceptions\ContentActionNotAllowedException;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use App\Services\Cms\Exceptions\UnknownSectionTypeException;
use App\Services\Cms\Exceptions\UnsupportedUploadException;
use App\Support\Cms\SectionRegistry;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use JsonException;
use stdClass;

/**
 * Everything that happens to a placed section **before** it goes live (phase-03 §6.2): placing,
 * saving drafts, enabling and disabling, reordering, duplicating, removing and restoring, and the
 * repeater items inside it — plus the publish-readiness check `ContentPublisher` runs. Publishing,
 * unpublishing and reverting live in `ContentPublisher`.
 *
 * One public entry point per operation:
 *
 *   place()  saveDraft()  rename()  enable()  disable()  toggle()  reorder()  duplicate()
 *   remove()  restore()  upsertItem()  toggleItem()  reorderItems()  deleteItem()  syncFaqs()
 *   validateContent()  assertPublishable()  draftPayload()  publishedPayload()
 *
 * Invariants:
 *
 *   · **INV-1 — a draft write never touches the live copy.** No method here writes
 *     `published_content`, `published_hash`, `published_at` or `published_by`.
 *   · **INV-2 — only registry types.** An undeclared `section_key` cannot be placed
 *     (`UnknownSectionTypeException`), nor a type in a placement it does not allow.
 *   · **INV-3 — JSON never holds a foreign key.** Reference fields land on `cta_block_id` / `menu_id`;
 *     media land on the `website_section_media` pivot; `content` holds scalars and composites only.
 *   · **INV-4 — the hash is recomputed after every draft-affecting write** (fields, items, media,
 *     FAQ picks), inside the same transaction, so `has_unpublished_changes` cannot lie. Saving
 *     identical data leaves the hash — and the badge — untouched, and writes no revision.
 *   · **INV-5 — reordering is exact and contiguous.** The id list must equal the current live set
 *     (no additions, omissions or duplicates — a stale tab cannot drop a section); `sort_order` is
 *     rewritten 10, 20, 30 ... in one statement inside one transaction.
 *   · **INV-7 — required types are disable-only.** `remove()` refuses `header`, `hero`, `footer`.
 *   · **Unique types are a database fact.** `instance_key` + `uq_ws_instance` decides; a removed unique
 *     section releases its key so the type can be placed again, and `restore()` reclaims it.
 *   · **INV-16 — every act is audited** with old and new values, the actor and, where discretionary,
 *     the reason. A change that can alter the public page bumps the cache version after commit.
 */
final class SectionService
{
    private const MODULE = 'website_sections';

    private const ANCHOR_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';

    /** A FAQ section's snapshot shows at most this many questions (`SnapshotBuilder::faqs()`). */
    private const FAQ_PICK_LIMIT = 100;

    /** @var array<string, bool> */
    private array $tables = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SectionValidator $validator,
        private readonly ContentHasher $hasher,
        private readonly RevisionRecorder $revisions,
        private readonly SnapshotBuilder $snapshots,
        private readonly MediaService $media,
        private readonly CacheVersion $cache,
        private readonly CmsAuditor $auditor,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Placing, drafting, naming
    |--------------------------------------------------------------------------
    */

    /**
     * Place a new section of a registry type (§6.2 `place()`, FT-01..FT-05).
     *
     * @throws UnknownSectionTypeException
     * @throws ContentActionNotAllowedException when a unique type is already placed there
     */
    public function place(string $key, SectionPlacement $placement, ?Page $page = null, ?string $name = null): WebsiteSection
    {
        if (! SectionRegistry::exists($key)) {
            throw UnknownSectionTypeException::key($key, SectionRegistry::keys());
        }

        if (! SectionRegistry::allowedIn($key, $placement)) {
            throw UnknownSectionTypeException::notAllowedInPlacement($key, $placement->value);
        }

        $pageId = $this->assertPlacementPage($placement, $page);
        $instanceKey = SectionRegistry::instanceKey($key, $placement, $pageId);

        try {
            $id = $this->connection()->transaction(function () use ($key, $placement, $pageId, $instanceKey, $name): int {
                $now = Carbon::now();
                $actor = $this->auditor->actorId();

                $max = (int) $this->liveInPlacement($placement, $pageId)->lockForUpdate()->max('sort_order');

                $attributes = array_merge(SectionRegistry::columnDefaults($key), [
                    'section_key' => $key,
                    'placement' => $placement->value,
                    'page_id' => $pageId,
                    'instance_key' => $instanceKey,
                    'name' => $this->cleanName($name),
                    'anchor' => null,
                    'content' => $this->encode(SectionRegistry::defaults($key)),
                    'is_enabled' => true,
                    'status' => ContentStatus::Draft->value,
                    'sort_order' => $max + 10,
                    'draft_updated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'created_by' => $actor,
                    'updated_by' => $actor,
                ]);

                $id = (int) $this->connection()->table('website_sections')->insertGetId($attributes);
                $section = $this->find($id);
                $canonical = $this->canonicalPayload($section);

                $this->connection()->table('website_sections')->where('id', $id)
                    ->update(['content_hash' => $this->hasher->hash($canonical)]);

                $this->revisions->record($section, RevisionEvent::Created, $canonical);

                $this->auditor->record(
                    module: self::MODULE,
                    description: sprintf('Section placed: %s in %s', SectionRegistry::label($key), $placement->label()),
                    subject: $section,
                    properties: ['attributes' => ['section_key' => $key, 'placement' => $placement->value, 'page_id' => $pageId, 'sort_order' => $max + 10]],
                    event: 'placed',
                );

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            throw ContentActionNotAllowedException::uniqueSectionDuplicated(SectionRegistry::label($key), $placement->label());
        }

        return $this->find($id);
    }

    /**
     * Save the draft: fields, column references and (optionally) media slots (§6.2 `saveDraft()`).
     *
     * Keys omitted from `$content` keep their stored value; media roles omitted from `$media` are left
     * untouched, and an empty list clears a role.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $media
     *
     * @throws InvalidSectionContentException
     */
    public function saveDraft(WebsiteSection $section, array $content, array $media = []): WebsiteSection
    {
        $affectedAssets = [];
        $affectedCtas = [];

        $this->connection()->transaction(function () use ($section, $content, $media, &$affectedAssets, &$affectedCtas): void {
            $row = $this->lockRow((int) $section->getKey());
            $key = (string) $row->section_key;

            $this->assertEditable($row);

            $validated = $this->validator->content($key, $content, $this->currentFields($row));
            $roles = $this->validator->media($key, $media);

            $before = $this->hasher->hash($this->canonicalPayload($section));
            $update = ['content' => $this->encode($validated['content'])];

            foreach ($validated['columns'] as $column => $value) {
                $update[$column] = $value;

                if ($column === 'cta_block_id') {
                    $affectedCtas = array_filter([$row->cta_block_id, $value]);
                }
            }

            $affectedAssets = $this->syncMedia((int) $row->id, $roles);

            $this->touchDraft((int) $row->id, $update);
            $after = $this->refreshHash($section);

            if ($after === $before) {
                return;
            }

            $this->revisions->record($section, RevisionEvent::DraftSaved, $this->canonicalPayload($section));

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('Section draft saved: %s', $this->label($row)),
                subject: $section,
                properties: $this->auditor->diff(
                    array_merge($this->decode($row->content), ['content_hash' => $row->content_hash]),
                    array_merge($validated['content'], ['content_hash' => $after])
                ),
                event: 'draft_saved',
            );
        });

        $this->afterReferencesChanged($affectedAssets, $affectedCtas);

        return $this->find((int) $section->getKey());
    }

    /**
     * The stored form of an `#anchor` as typed: lower-case, no leading `#`, null when empty. The controller
     * compares with it to decide whether a save changes a live anchor (`WebsiteSectionPolicy::changeAnchor`).
     */
    public static function normaliseAnchor(?string $anchor): ?string
    {
        $anchor = $anchor === null ? null : strtolower(trim(ltrim(trim($anchor), '#')));

        return $anchor === '' ? null : $anchor;
    }

    /**
     * The admin-facing name and the public `#anchor` a menu item can link to (§102).
     *
     * The anchor is a live attribute (the public read selects it directly), so a change bumps the
     * cache. It is unique per placement and page, checked here because MariaDB's `uq_ws_anchor` treats
     * the NULL `page_id` of the home page as distinct.
     */
    public function rename(WebsiteSection $section, ?string $name, ?string $anchor): WebsiteSection
    {
        $this->connection()->transaction(function () use ($section, $name, $anchor): void {
            $row = $this->lockRow((int) $section->getKey());
            $name = $this->cleanName($name);
            $anchor = self::normaliseAnchor($anchor);

            if ($anchor !== null && preg_match(self::ANCHOR_PATTERN, $anchor) !== 1) {
                throw InvalidSectionContentException::withErrors('The anchor is not valid.', [
                    'anchor' => ['Use lowercase letters, numbers and hyphens, up to 64 characters.'],
                ]);
            }

            if ($anchor !== null && $this->liveInPlacement(SectionPlacement::from((string) $row->placement), $row->page_id === null ? null : (int) $row->page_id)
                ->where('anchor', $anchor)->where('id', '!=', $row->id)->exists()) {
                throw InvalidSectionContentException::withErrors('The anchor is already used.', [
                    'anchor' => [sprintf('Another section on this page already uses #%s.', $anchor)],
                ]);
            }

            if ($name === $row->name && $anchor === $row->anchor) {
                return;
            }

            try {
                $this->connection()->table('website_sections')->where('id', $row->id)->update([
                    'name' => $name,
                    'anchor' => $anchor,
                    'updated_at' => Carbon::now(),
                    'updated_by' => $this->auditor->actorId(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw InvalidSectionContentException::withErrors('The anchor is already used.', [
                    'anchor' => [sprintf('Another section on this page already uses #%s.', $anchor)],
                ]);
            }

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('Section renamed: %s', $this->label($row)),
                subject: $section,
                properties: $this->auditor->diff(
                    ['name' => $row->name, 'anchor' => $row->anchor],
                    ['name' => $name, 'anchor' => $anchor]
                ),
                event: 'renamed',
            );

            if ($anchor !== $row->anchor) {
                $this->cache->bumpAfterCommit(sprintf('Section #%d anchor changed', $row->id));
            }
        });

        return $this->find((int) $section->getKey());
    }

    /*
    |--------------------------------------------------------------------------
    | Enable, disable, order
    |--------------------------------------------------------------------------
    */

    /**
     * Show a section again. Never changes `status` (§2.2: enable is independent of publish).
     */
    public function enable(WebsiteSection $section): WebsiteSection
    {
        return $this->setEnabled($section, true, null);
    }

    /**
     * Hide a section without unpublishing it (§6.2 `toggle()`, FT-16) — the one thing a required
     * section may have done to it.
     */
    public function disable(WebsiteSection $section, ?string $reason = null): WebsiteSection
    {
        return $this->setEnabled($section, false, $reason);
    }

    /**
     * The contract's name for enable/disable (§6.2).
     */
    public function toggle(WebsiteSection $section, bool $enabled, ?string $reason = null): WebsiteSection
    {
        return $enabled ? $this->enable($section) : $this->disable($section, $reason);
    }

    /**
     * Reorder every live section of one placement (§6.2 `reorder()`, INV-5, FT-15).
     *
     * @param  list<int|string>  $orderedIds
     *
     * @throws InvalidSectionContentException when the list is not exactly the current set
     */
    public function reorder(SectionPlacement $placement, ?Page $page, array $orderedIds): void
    {
        $pageId = $this->assertPlacementPage($placement, $page);
        $given = $this->ids($orderedIds);

        $this->connection()->transaction(function () use ($placement, $page, $pageId, $given): void {
            $current = $this->liveInPlacement($placement, $pageId)
                ->orderBy('sort_order')->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $this->assertExactSet($current, $given);

            if ($current === $given) {
                return;
            }

            $this->writeOrder('website_sections', $given);

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('Sections reordered in %s', $placement->label()),
                subject: $page,
                properties: ['old' => ['order' => $current], 'attributes' => ['order' => $given], 'placement' => $placement->value],
                event: 'reordered',
            );

            $this->cache->bumpAfterCommit(sprintf('Sections reordered in %s', $placement->value));
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate, remove, restore
    |--------------------------------------------------------------------------
    */

    /**
     * Copy a repeatable section with its items, media slots and FAQ picks, as a new draft (§6.2).
     *
     * @throws ContentActionNotAllowedException for a unique type
     */
    public function duplicate(WebsiteSection $section): WebsiteSection
    {
        $assets = [];
        $ctas = [];

        $id = $this->connection()->transaction(function () use ($section, &$assets, &$ctas): int {
            $row = $this->lockRow((int) $section->getKey());
            $key = (string) $row->section_key;

            if (! SectionRegistry::exists($key)) {
                throw UnknownSectionTypeException::key($key, SectionRegistry::keys());
            }

            if (SectionRegistry::isUnique($key)) {
                throw ContentActionNotAllowedException::notDuplicable($this->label($row));
            }

            $placement = SectionPlacement::from((string) $row->placement);
            $pageId = $row->page_id === null ? null : (int) $row->page_id;
            $max = (int) $this->liveInPlacement($placement, $pageId)->lockForUpdate()->max('sort_order');
            $now = Carbon::now();
            $actor = $this->auditor->actorId();

            $copyId = (int) $this->connection()->table('website_sections')->insertGetId([
                'section_key' => $key,
                'placement' => $placement->value,
                'page_id' => $pageId,
                'instance_key' => null,
                'name' => mb_substr($this->label($row).' (copy)', 0, 150),
                'anchor' => null,
                'cta_block_id' => $row->cta_block_id,
                'menu_id' => $row->menu_id,
                'content' => $row->content,
                'is_enabled' => true,
                'status' => ContentStatus::Draft->value,
                'sort_order' => $max + 10,
                'draft_updated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $actor,
                'updated_by' => $actor,
            ]);

            $items = $this->connection()->table('website_section_items')
                ->where('website_section_id', $row->id)->whereNull('deleted_at')
                ->orderBy('id')->get();

            if ($items->isNotEmpty()) {
                $this->connection()->table('website_section_items')->insert($items->map(static fn (object $item): array => [
                    'website_section_id' => $copyId,
                    'group' => $item->group,
                    'content' => $item->content,
                    'metric' => $item->metric,
                    'value_mode' => $item->value_mode,
                    'manual_value' => $item->manual_value,
                    'media_asset_id' => $item->media_asset_id,
                    'is_enabled' => $item->is_enabled,
                    'sort_order' => $item->sort_order,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'created_by' => $actor,
                    'updated_by' => $actor,
                ])->all());
            }

            $pivot = $this->connection()->table('website_section_media')->where('website_section_id', $row->id)->get();

            if ($pivot->isNotEmpty()) {
                $this->connection()->table('website_section_media')->insert($pivot->map(static fn (object $media): array => [
                    'website_section_id' => $copyId,
                    'media_asset_id' => $media->media_asset_id,
                    'role' => $media->role,
                    'sort_order' => $media->sort_order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }

            if ($this->hasTable('faq_website_section')) {
                $faqs = $this->connection()->table('faq_website_section')->where('website_section_id', $row->id)->get();

                if ($faqs->isNotEmpty()) {
                    $this->connection()->table('faq_website_section')->insert($faqs->map(static fn (object $faq): array => [
                        'faq_id' => $faq->faq_id,
                        'website_section_id' => $copyId,
                        'sort_order' => $faq->sort_order,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all());
                }
            }

            $copy = $this->find($copyId);
            $canonical = $this->canonicalPayload($copy);

            $this->connection()->table('website_sections')->where('id', $copyId)
                ->update(['content_hash' => $this->hasher->hash($canonical)]);

            $this->revisions->record($copy, RevisionEvent::Created, $canonical, label: sprintf('Copied from section #%d', $row->id));

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('Section duplicated: %s', $this->label($row)),
                subject: $copy,
                properties: ['attributes' => ['copied_from' => (int) $row->id, 'sort_order' => $max + 10]],
                event: 'duplicated',
            );

            $assets = array_merge($pivot->pluck('media_asset_id')->all(), $items->pluck('media_asset_id')->filter()->all());
            $ctas = array_filter([$row->cta_block_id]);

            return $copyId;
        });

        $this->afterReferencesChanged($assets, $ctas);

        return $this->find($id);
    }

    /**
     * Soft-delete a placed section (§6.2 `remove()`). Its items and pivots stay; the parent is trashed.
     *
     * @throws ContentActionNotAllowedException for a required type (INV-7, FT-16)
     * @throws InvalidSectionContentException without a reason
     */
    public function remove(WebsiteSection $section, string $reason): void
    {
        $reason = trim($reason);
        $assets = [];
        $ctas = [];

        $this->connection()->transaction(function () use ($section, $reason, &$assets, &$ctas): void {
            $row = $this->lockRow((int) $section->getKey());
            $key = (string) $row->section_key;

            if (SectionRegistry::exists($key) && SectionRegistry::isRequired($key)) {
                throw ContentActionNotAllowedException::requiredSection($this->label($row));
            }

            if ($reason === '') {
                throw InvalidSectionContentException::reasonRequired('remove this section');
            }

            if ($row->deleted_at !== null) {
                return;
            }

            $this->connection()->table('website_sections')->where('id', $row->id)->update([
                'deleted_at' => Carbon::now(),
                // Release the unique slot so the type can be placed again; restore() reclaims it.
                'instance_key' => null,
                'updated_at' => Carbon::now(),
                'updated_by' => $this->auditor->actorId(),
            ]);

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('Section removed: %s', $this->label($row)),
                subject: $section,
                properties: ['old' => ['deleted_at' => null, 'instance_key' => $row->instance_key], 'attributes' => ['deleted_at' => Carbon::now()->toDateTimeString(), 'instance_key' => null]],
                reason: $reason,
                event: 'removed',
            );

            $assets = array_merge(
                $this->connection()->table('website_section_media')->where('website_section_id', $row->id)->pluck('media_asset_id')->all(),
                $this->connection()->table('website_section_items')->where('website_section_id', $row->id)->whereNotNull('media_asset_id')->pluck('media_asset_id')->all()
            );
            $ctas = array_filter([$row->cta_block_id]);

            $this->cache->bumpAfterCommit(sprintf('Section #%d removed', $row->id));
        });

        $this->afterReferencesChanged($assets, $ctas);
    }

    /**
     * Bring a removed section back. A unique type reclaims its slot, and is refused when another
     * instance has been placed meanwhile.
     */
    public function restore(WebsiteSection $section): WebsiteSection
    {
        $assets = [];
        $stored = $this->row((int) $section->getKey());
        $typeLabel = $this->label($stored);
        $placementLabel = SectionPlacement::tryFrom((string) $stored->placement)?->label() ?? (string) $stored->placement;

        try {
            $this->connection()->transaction(function () use ($section, &$assets): void {
                $row = $this->lockRow((int) $section->getKey());

                if ($row->deleted_at === null) {
                    return;
                }

                $key = (string) $row->section_key;
                $placement = SectionPlacement::from((string) $row->placement);
                $instanceKey = SectionRegistry::exists($key)
                    ? SectionRegistry::instanceKey($key, $placement, $row->page_id === null ? null : (int) $row->page_id)
                    : null;

                $this->connection()->table('website_sections')->where('id', $row->id)->update([
                    'deleted_at' => null,
                    'instance_key' => $instanceKey,
                    'updated_at' => Carbon::now(),
                    'updated_by' => $this->auditor->actorId(),
                ]);

                $this->revisions->record($section, RevisionEvent::Restored, $this->canonicalPayload($section));

                $this->auditor->record(
                    module: self::MODULE,
                    description: sprintf('Section restored: %s', $this->label($row)),
                    subject: $section,
                    properties: ['old' => ['deleted_at' => (string) $row->deleted_at], 'attributes' => ['deleted_at' => null]],
                    event: 'restored',
                );

                $assets = $this->connection()->table('website_section_media')->where('website_section_id', $row->id)->pluck('media_asset_id')->all();

                $this->cache->bumpAfterCommit(sprintf('Section #%d restored', $row->id));
            });
        } catch (UniqueConstraintViolationException) {
            throw ContentActionNotAllowedException::uniqueSectionDuplicated($typeLabel, $placementLabel);
        }

        $this->afterReferencesChanged($assets, []);

        return $this->find((int) $section->getKey());
    }

    /*
    |--------------------------------------------------------------------------
    | Repeater items (website_section_items)
    |--------------------------------------------------------------------------
    */

    /**
     * Add or update one repeater item (§6.2 `upsertItem()`, FT-18).
     *
     * @param  array<string, mixed>  $data  item field keys plus an optional `is_enabled`
     *
     * @throws InvalidSectionContentException
     */
    public function upsertItem(WebsiteSection $section, string $group, array $data, ?WebsiteSectionItem $item = null): WebsiteSectionItem
    {
        $assets = [];

        $id = $this->connection()->transaction(function () use ($section, $group, $data, $item, &$assets): int {
            $row = $this->lockRow((int) $section->getKey());
            $key = (string) $row->section_key;

            $this->assertEditable($row);

            $existing = $item === null ? null : $this->itemRow((int) $item->getKey(), (int) $row->id, $group);
            $repeater = SectionRegistry::exists($key) ? SectionRegistry::repeater($key, $group) : null;

            if ($repeater !== null && $existing === null) {
                $count = $this->connection()->table('website_section_items')
                    ->where('website_section_id', $row->id)->where('group', $group)->whereNull('deleted_at')
                    ->lockForUpdate()->count();

                if ($count >= (int) $repeater['max']) {
                    throw InvalidSectionContentException::repeaterMax($group, (int) $repeater['max']);
                }
            }

            $validated = $this->validator->item($key, $group, $data, $existing === null ? [] : $this->currentItemFields($key, $group, $existing));

            $now = Carbon::now();
            $actor = $this->auditor->actorId();
            $values = array_merge($validated['columns'], [
                'content' => $this->encode($validated['content']),
                'is_enabled' => $validated['is_enabled'],
                'updated_at' => $now,
                'updated_by' => $actor,
            ]);

            if ($existing === null) {
                $max = (int) $this->connection()->table('website_section_items')
                    ->where('website_section_id', $row->id)->where('group', $group)->whereNull('deleted_at')
                    ->max('sort_order');

                $itemId = (int) $this->connection()->table('website_section_items')->insertGetId(array_merge($values, [
                    'website_section_id' => $row->id,
                    'group' => $group,
                    'sort_order' => $max + 10,
                    'created_at' => $now,
                    'created_by' => $actor,
                ]));
            } else {
                $itemId = (int) $existing->id;
                $this->connection()->table('website_section_items')->where('id', $itemId)->update($values);
            }

            $hash = $this->refreshHash($section);

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('%s entry %s: %s', ucfirst(str_replace('_', ' ', $group)), $existing === null ? 'added' : 'updated', $this->label($row)),
                subject: $section,
                properties: $this->auditor->diff(
                    $existing === null ? [] : array_merge($this->decode($existing->content), [
                        'metric' => $existing->metric, 'value_mode' => $existing->value_mode,
                        'manual_value' => $existing->manual_value, 'media_asset_id' => $existing->media_asset_id,
                        'is_enabled' => (bool) $existing->is_enabled,
                    ]),
                    array_merge($validated['content'], $validated['columns'], ['is_enabled' => $validated['is_enabled'], 'item_id' => $itemId, 'content_hash' => $hash])
                ),
                event: 'item_saved',
            );

            $assets = array_filter([$existing?->media_asset_id, $validated['columns']['media_asset_id'] ?? null]);

            return $itemId;
        });

        $this->afterReferencesChanged($assets, []);

        /** @var WebsiteSectionItem */
        return WebsiteSectionItem::query()->withoutGlobalScopes()->findOrFail($id);
    }

    /**
     * Show or hide one item without deleting it. A hidden item leaves the next snapshot (FT-45).
     */
    public function toggleItem(WebsiteSectionItem $item, bool $enabled): WebsiteSectionItem
    {
        $this->connection()->transaction(function () use ($item, $enabled): void {
            // Lock order is always section row, then item row — the same as upsertItem().
            $sectionId = (int) $this->connection()->table('website_section_items')->where('id', $item->getKey())->value('website_section_id');
            $this->assertEditable($this->lockRow($sectionId));

            $current = $this->connection()->table('website_section_items')
                ->where('id', $item->getKey())->whereNull('deleted_at')->lockForUpdate()->first();

            if ($current === null) {
                throw InvalidSectionContentException::withErrors('That entry no longer exists.', ['item' => ['It may have been deleted in another tab.']]);
            }

            if ((bool) $current->is_enabled === $enabled) {
                return;
            }

            $section = $this->find($sectionId);

            $this->connection()->table('website_section_items')->where('id', $current->id)->update([
                'is_enabled' => $enabled,
                'updated_at' => Carbon::now(),
                'updated_by' => $this->auditor->actorId(),
            ]);

            $this->refreshHash($section);

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('%s entry %s', ucfirst(str_replace('_', ' ', (string) $current->group)), $enabled ? 'enabled' : 'disabled'),
                subject: $section,
                properties: ['old' => ['is_enabled' => (bool) $current->is_enabled], 'attributes' => ['is_enabled' => $enabled, 'item_id' => (int) $current->id]],
                event: 'item_toggled',
            );
        });

        /** @var WebsiteSectionItem */
        return WebsiteSectionItem::query()->withoutGlobalScopes()->findOrFail($item->getKey());
    }

    /**
     * Reorder the live items of one repeater — the same exact-set and contiguity guarantees as
     * `reorder()` (§6.2 `reorderItems()`).
     *
     * @param  list<int|string>  $orderedIds
     */
    public function reorderItems(WebsiteSection $section, string $group, array $orderedIds): void
    {
        $given = $this->ids($orderedIds);

        $this->connection()->transaction(function () use ($section, $group, $given): void {
            $row = $this->lockRow((int) $section->getKey());
            $this->assertEditable($row);

            $current = $this->connection()->table('website_section_items')
                ->where('website_section_id', $row->id)->where('group', $group)->whereNull('deleted_at')
                ->orderBy('sort_order')->orderBy('id')->lockForUpdate()
                ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

            $this->assertExactSet($current, $given);

            if ($current === $given) {
                return;
            }

            $this->writeOrder('website_section_items', $given);
            $this->refreshHash($section);

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('%s entries reordered: %s', ucfirst(str_replace('_', ' ', $group)), $this->label($row)),
                subject: $section,
                properties: ['old' => ['order' => $current], 'attributes' => ['order' => $given], 'group' => $group],
                event: 'items_reordered',
            );
        });
    }

    /**
     * Soft-delete one item, refused when the repeater would drop below its `min` (FT-18).
     */
    public function deleteItem(WebsiteSectionItem $item): void
    {
        $assets = [];

        $this->connection()->transaction(function () use ($item, &$assets): void {
            $sectionId = $this->connection()->table('website_section_items')->where('id', $item->getKey())->value('website_section_id');

            if ($sectionId === null) {
                return;
            }

            $row = $this->lockRow((int) $sectionId);
            $this->assertEditable($row);

            $current = $this->connection()->table('website_section_items')
                ->where('id', $item->getKey())->whereNull('deleted_at')->lockForUpdate()->first();

            if ($current === null) {
                return;
            }

            $key = (string) $row->section_key;
            $repeater = SectionRegistry::exists($key) ? SectionRegistry::repeater($key, (string) $current->group) : null;

            if ($repeater !== null) {
                $count = $this->connection()->table('website_section_items')
                    ->where('website_section_id', $row->id)->where('group', $current->group)->whereNull('deleted_at')
                    ->count();

                if ($count - 1 < (int) $repeater['min']) {
                    throw InvalidSectionContentException::repeaterMin((string) $current->group, (int) $repeater['min']);
                }
            }

            $this->connection()->table('website_section_items')->where('id', $current->id)->update([
                'deleted_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'updated_by' => $this->auditor->actorId(),
            ]);

            $section = $this->find((int) $row->id);
            $this->refreshHash($section);

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('%s entry deleted: %s', ucfirst(str_replace('_', ' ', (string) $current->group)), $this->label($row)),
                subject: $section,
                properties: ['old' => array_merge($this->decode($current->content), ['item_id' => (int) $current->id])],
                event: 'item_deleted',
            );

            $assets = array_filter([$current->media_asset_id]);
        });

        $this->afterReferencesChanged($assets, []);
    }

    /*
    |--------------------------------------------------------------------------
    | Hand-picked FAQs (faq_website_section)
    |--------------------------------------------------------------------------
    */

    /**
     * Replace the hand-picked questions of a `faq` section — the pivot read when its `source` is
     * `selected` (§2.11, integration K-10). The list is the full ordered set; an empty list clears it.
     *
     * A draft write like `saveDraft()` (INV-1): the pivot is folded into the canonical payload, so the hash
     * is recomputed in the same transaction (INV-4) and the live page changes only on publish. Picking the
     * same list again writes nothing and no revision.
     *
     * @param  array<int, int|string>  $faqIds
     *
     * @throws InvalidSectionContentException for a section that is not a FAQ section, an unknown or
     *                                        trashed question, a duplicate, or more than the snapshot shows
     */
    public function syncFaqs(WebsiteSection $section, array $faqIds): WebsiteSection
    {
        $given = [];

        foreach (array_values($faqIds) as $faqId) {
            if (! is_int($faqId) && ! (is_string($faqId) && ctype_digit($faqId))) {
                throw InvalidSectionContentException::withErrors('The chosen questions are not valid.', ['faqs' => ['Choose questions from the list.']]);
            }

            $given[] = (int) $faqId;
        }

        if (count($given) !== count(array_unique($given))) {
            throw InvalidSectionContentException::withErrors('A question was chosen twice.', ['faqs' => ['Each question may appear once.']]);
        }

        if (count($given) > self::FAQ_PICK_LIMIT) {
            throw InvalidSectionContentException::withErrors('Too many questions were chosen.', [
                'faqs' => [sprintf('A FAQ section shows at most %d questions.', self::FAQ_PICK_LIMIT)],
            ]);
        }

        $this->connection()->transaction(function () use ($section, $given): void {
            $row = $this->lockRow((int) $section->getKey());
            $this->assertEditable($row);

            if ((string) $row->section_key !== 'faq' || ! $this->hasTable('faq_website_section') || ! $this->hasTable('faqs')) {
                throw InvalidSectionContentException::withErrors('Only a FAQ section holds hand-picked questions.', [
                    'faqs' => ['This section does not list questions.'],
                ]);
            }

            $live = $given === [] ? [] : $this->connection()->table('faqs')
                ->whereIn('id', $given)->whereNull('deleted_at')
                ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

            $missing = array_values(array_diff($given, $live));

            if ($missing !== []) {
                throw InvalidSectionContentException::withErrors('A chosen question no longer exists.', [
                    'faqs' => [sprintf('Question #%s was deleted or is in the trash.', implode(', #', $missing))],
                ]);
            }

            $current = $this->connection()->table('faq_website_section')
                ->where('website_section_id', $row->id)
                ->orderBy('sort_order')->orderBy('faq_id')
                ->lockForUpdate()
                ->pluck('faq_id')->map(static fn (mixed $id): int => (int) $id)->all();

            if ($current === $given) {
                return;
            }

            $before = $row->content_hash;
            $now = Carbon::now();

            $this->connection()->table('faq_website_section')->where('website_section_id', $row->id)->delete();

            if ($given !== []) {
                $this->connection()->table('faq_website_section')->insert(array_map(
                    static fn (int $faqId, int $index): array => [
                        'faq_id' => $faqId,
                        'website_section_id' => $row->id,
                        'sort_order' => ($index + 1) * 10,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $given,
                    array_keys($given)
                ));
            }

            $this->touchDraft((int) $row->id, []);
            $after = $this->refreshHash($section);

            if ($after !== $before) {
                $this->revisions->record($section, RevisionEvent::DraftSaved, $this->canonicalPayload($section));
            }

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('FAQ picks saved: %s', $this->label($row)),
                subject: $section,
                properties: ['old' => ['faqs' => $current, 'content_hash' => $before], 'attributes' => ['faqs' => $given, 'content_hash' => $after]],
                event: 'faqs_picked',
            );
        });

        return $this->find((int) $section->getKey());
    }

    /*
    |--------------------------------------------------------------------------
    | Validation and payloads
    |--------------------------------------------------------------------------
    */

    /**
     * Validate a content payload for a type without writing anything — for a Form Request's
     * `after()` hook or an import preview.
     *
     * @param  array<string, mixed>  $content
     * @return array{content: array<string, mixed>, columns: array<string, int|string|null>}
     */
    public function validateContent(string $key, array $content, bool $forPublish = false): array
    {
        return $this->validator->content($key, $content, [], $forPublish);
    }

    /**
     * May this section be published as it stands? Throws naming the first problem (§6.2 `publish()`
     * refusals, FT-13): an empty required field, an empty required media slot, a background video
     * without a poster, a placed image without alt text, a repeater below its minimum, or content
     * that no longer matches the registry.
     *
     * @throws UnknownSectionTypeException
     * @throws InvalidSectionContentException
     */
    public function assertPublishable(WebsiteSection $section): void
    {
        $row = $this->row((int) $section->getKey());
        $key = (string) $row->section_key;

        if (! SectionRegistry::exists($key)) {
            throw UnknownSectionTypeException::key($key, SectionRegistry::keys());
        }

        $fields = $this->currentFields($row);
        $this->validator->content($key, [], $fields, forPublish: true);

        $content = $this->decode($row->content);
        $source = FaqSource::tryFrom((string) ($content['source'] ?? ''));

        if ($key === 'faq' && $source?->requiresCategory() && trim((string) ($content['faq_category_ref'] ?? '')) === '') {
            throw InvalidSectionContentException::requiredField($key, 'faq_category_ref', 'Category');
        }

        $roles = SectionRegistry::mediaRoles($key);
        $pivot = $this->connection()->table('website_section_media as m')
            ->leftJoin('media_assets as a', static function ($join): void {
                $join->on('a.id', '=', 'm.media_asset_id')->whereNull('a.deleted_at');
            })
            ->where('m.website_section_id', $row->id)
            ->orderBy('m.role')->orderBy('m.sort_order')
            ->get(['m.role', 'm.media_asset_id', 'a.id as asset_id', 'a.alt_text', 'a.mime_type']);

        $filled = [];

        foreach ($pivot as $media) {
            $role = (string) $media->role;

            if ($media->asset_id === null) {
                throw InvalidSectionContentException::withErrors(
                    sprintf('The [%s] slot points at a media item that is in the trash.', $role),
                    ['media.'.$role => ['That file was deleted from the media library. Choose another.']]
                );
            }

            $filled[$role] = true;
            $isImage = str_starts_with((string) $media->mime_type, 'image/');

            if ($isImage && trim((string) $media->alt_text) === '') {
                throw InvalidSectionContentException::missingAltText($role, (int) $media->asset_id);
            }
        }

        foreach ($roles as $role => $slot) {
            if ($slot['required'] && ! isset($filled[$role])) {
                throw InvalidSectionContentException::requiredMedia($key, $role, (string) $slot['label']);
            }

            if ($slot['kind'] === SectionRegistry::KIND_VIDEO && isset($filled[$role]) && isset($roles['video_poster']) && ! isset($filled['video_poster'])) {
                throw InvalidSectionContentException::withErrors(
                    UnsupportedUploadException::videoNeedsPoster()->getMessage(),
                    ['media.video_poster' => ['Choose a poster image for the background video.']]
                );
            }
        }

        foreach (SectionRegistry::repeaters($key) as $group => $repeater) {
            $items = $this->connection()->table('website_section_items as i')
                ->leftJoin('media_assets as a', static function ($join): void {
                    $join->on('a.id', '=', 'i.media_asset_id')->whereNull('a.deleted_at');
                })
                ->where('i.website_section_id', $row->id)->where('i.group', $group)
                ->whereNull('i.deleted_at')->where('i.is_enabled', true)
                ->orderBy('i.sort_order')
                ->get(['i.*', 'a.id as asset_id', 'a.alt_text as asset_alt']);

            if ($items->count() < (int) $repeater['min']) {
                throw InvalidSectionContentException::repeaterMin($group, (int) $repeater['min']);
            }

            foreach ($items as $item) {
                $this->validator->item($key, $group, [], $this->currentItemFields($key, $group, $item));

                if ($item->media_asset_id !== null && $item->asset_id === null) {
                    throw InvalidSectionContentException::withErrors(
                        sprintf('An entry in [%s] points at a media item that is in the trash.', $group),
                        ['items.'.$group => ['An entry uses a file that was deleted from the media library.']]
                    );
                }

                $label = trim((string) ($this->decode($item->content)['label'] ?? ''));

                if ($item->asset_id !== null && trim((string) $item->asset_alt) === '' && $label === '') {
                    throw InvalidSectionContentException::missingAltText('items.'.$group, (int) $item->asset_id);
                }
            }
        }
    }

    /**
     * What preview renders: the snapshot the section *would* publish right now (§6.12).
     *
     * @return array<string, mixed>
     */
    public function draftPayload(WebsiteSection $section): array
    {
        return $this->snapshots->build($section);
    }

    /**
     * What the public site renders: the stored snapshot, or null when never published.
     *
     * @return array<string, mixed>|null
     */
    public function publishedPayload(WebsiteSection $section): ?array
    {
        $published = $this->row((int) $section->getKey())->published_content;

        if ($published === null) {
            return null;
        }

        $decoded = json_decode((string) $published, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The canonical draft payload that is hashed and stored in revisions (see `ContentHasher`).
     *
     * @return array<string, mixed>
     */
    public function canonicalPayload(WebsiteSection $section): array
    {
        $row = $this->row((int) $section->getKey());

        $items = [];

        foreach ($this->connection()->table('website_section_items')
            ->where('website_section_id', $row->id)->whereNull('deleted_at')
            ->orderBy('group')->orderBy('sort_order')->orderBy('id')
            ->get() as $item) {
            $items[(string) $item->group][] = [
                'id' => (int) $item->id,
                'sort_order' => (int) $item->sort_order,
                'content' => $this->decode($item->content),
                'metric' => $item->metric,
                'value_mode' => (string) $item->value_mode,
                'manual_value' => $item->manual_value === null ? null : (string) $item->manual_value,
                'media_asset_id' => $item->media_asset_id === null ? null : (int) $item->media_asset_id,
                'is_enabled' => (bool) $item->is_enabled,
            ];
        }

        $media = [];

        foreach ($this->connection()->table('website_section_media')
            ->where('website_section_id', $row->id)
            ->orderBy('role')->orderBy('sort_order')->orderBy('media_asset_id')
            ->get(['role', 'media_asset_id']) as $pivot) {
            $media[(string) $pivot->role][] = (int) $pivot->media_asset_id;
        }

        $faqs = $this->hasTable('faq_website_section')
            ? $this->connection()->table('faq_website_section')
                ->where('website_section_id', $row->id)
                ->orderBy('sort_order')->orderBy('faq_id')
                ->pluck('faq_id')->map(static fn (mixed $id): int => (int) $id)->all()
            : [];

        return [
            'section_key' => (string) $row->section_key,
            'fields' => $this->decode($row->content),
            'columns' => [
                'cta_block_id' => $row->cta_block_id === null ? null : (int) $row->cta_block_id,
                'menu_id' => $row->menu_id === null ? null : (int) $row->menu_id,
            ],
            'items' => $items,
            'media' => $media,
            'faqs' => $faqs,
        ];
    }

    /**
     * Replace the draft with a canonical payload from a revision (used by `ContentPublisher::revert()`
     * inside its transaction). Fields the registry no longer declares are dropped; items are matched
     * by id where they still exist and recreated where they do not; media and FAQ picks pointing at
     * rows that are gone are skipped. Never touches the published columns.
     *
     * @param  array<string, mixed>  $canonical
     */
    public function applyCanonical(WebsiteSection $section, array $canonical): string
    {
        $row = $this->lockRow((int) $section->getKey());
        $key = (string) $row->section_key;

        if (! SectionRegistry::exists($key)) {
            throw UnknownSectionTypeException::key($key, SectionRegistry::keys());
        }

        $declared = SectionRegistry::contentKeys($key);
        $fields = array_intersect_key((array) ($canonical['fields'] ?? []), array_flip($declared));

        foreach (SectionRegistry::columns($key) as $field => $column) {
            $fields[$field] = $canonical['columns'][$column] ?? null;
        }

        $validated = $this->validator->content($key, $fields, $this->currentFields($row));
        $now = Carbon::now();
        $actor = $this->auditor->actorId();

        $this->touchDraft((int) $row->id, array_merge(['content' => $this->encode($validated['content'])], $validated['columns']));

        // Items: restore / update by id, recreate what is gone, trash what the revision did not have.
        $keep = [];
        $existing = $this->connection()->table('website_section_items')
            ->where('website_section_id', $row->id)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        foreach ((array) ($canonical['items'] ?? []) as $group => $items) {
            if (! SectionRegistry::hasRepeater($key, (string) $group)) {
                continue;
            }

            foreach ((array) $items as $item) {
                $values = [
                    'content' => $this->encode((array) ($item['content'] ?? [])),
                    'metric' => $item['metric'] ?? null,
                    'value_mode' => (string) ($item['value_mode'] ?? 'manual'),
                    'manual_value' => $item['manual_value'] ?? null,
                    'media_asset_id' => $this->liveAssetId($item['media_asset_id'] ?? null),
                    'is_enabled' => (bool) ($item['is_enabled'] ?? true),
                    'sort_order' => (int) ($item['sort_order'] ?? 0),
                    'deleted_at' => null,
                    'updated_at' => $now,
                    'updated_by' => $actor,
                ];

                $itemId = isset($item['id']) ? (int) $item['id'] : 0;

                if ($itemId > 0 && in_array($itemId, $existing, true)) {
                    $this->connection()->table('website_section_items')->where('id', $itemId)->update($values + ['group' => (string) $group]);
                    $keep[] = $itemId;

                    continue;
                }

                $keep[] = (int) $this->connection()->table('website_section_items')->insertGetId($values + [
                    'website_section_id' => $row->id,
                    'group' => (string) $group,
                    'created_at' => $now,
                    'created_by' => $actor,
                ]);
            }
        }

        $this->connection()->table('website_section_items')
            ->where('website_section_id', $row->id)->whereNull('deleted_at')
            ->when($keep !== [], static fn ($query) => $query->whereNotIn('id', $keep))
            ->update(['deleted_at' => $now, 'updated_at' => $now, 'updated_by' => $actor]);

        // Media pivots.
        $roles = [];

        foreach (SectionRegistry::mediaRoles($key) as $role => $slot) {
            $ids = array_values(array_filter(array_map(
                fn (mixed $id): ?int => $this->liveAssetId($id),
                (array) ($canonical['media'][$role] ?? [])
            )));

            $roles[$role] = $slot['multiple'] ? $ids : array_slice($ids, 0, 1);
        }

        $this->syncMedia((int) $row->id, $roles);

        // Hand-picked FAQs.
        if ($this->hasTable('faq_website_section') && $this->hasTable('faqs')) {
            $faqIds = $this->connection()->table('faqs')
                ->whereIn('id', array_map('intval', (array) ($canonical['faqs'] ?? [])))
                ->whereNull('deleted_at')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

            $this->connection()->table('faq_website_section')->where('website_section_id', $row->id)->delete();

            $order = 0;
            $rows = [];

            foreach ((array) ($canonical['faqs'] ?? []) as $faqId) {
                if (in_array((int) $faqId, $faqIds, true)) {
                    $rows[] = ['faq_id' => (int) $faqId, 'website_section_id' => $row->id, 'sort_order' => $order += 10, 'created_at' => $now, 'updated_at' => $now];
                }
            }

            if ($rows !== []) {
                $this->connection()->table('faq_website_section')->insert($rows);
            }
        }

        return $this->refreshHash($section);
    }

    /**
     * Recompute and store `content_hash` from the current draft. Returns the hash.
     */
    public function refreshHash(WebsiteSection $section): string
    {
        $hash = $this->hasher->hash($this->canonicalPayload($section));

        $this->connection()->table('website_sections')
            ->where('id', $section->getKey())
            ->where(static fn ($query) => $query->whereNull('content_hash')->orWhere('content_hash', '!=', $hash))
            ->update(['content_hash' => $hash, 'draft_updated_at' => Carbon::now()]);

        return $hash;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function setEnabled(WebsiteSection $section, bool $enabled, ?string $reason): WebsiteSection
    {
        $this->connection()->transaction(function () use ($section, $enabled, $reason): void {
            $row = $this->lockRow((int) $section->getKey());

            if ((bool) $row->is_enabled === $enabled) {
                return;
            }

            $this->connection()->table('website_sections')->where('id', $row->id)->update([
                'is_enabled' => $enabled,
                'updated_at' => Carbon::now(),
                'updated_by' => $this->auditor->actorId(),
            ]);

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('Section %s: %s', $enabled ? 'enabled' : 'disabled', $this->label($row)),
                subject: $section,
                properties: ['old' => ['is_enabled' => (bool) $row->is_enabled], 'attributes' => ['is_enabled' => $enabled]],
                reason: $reason,
                event: $enabled ? 'enabled' : 'disabled',
            );

            $this->cache->bumpAfterCommit(sprintf('Section #%d %s', $row->id, $enabled ? 'enabled' : 'disabled'));
        });

        return $this->find((int) $section->getKey());
    }

    /**
     * Replace the pivot rows of every role present in `$roles`. Returns the asset ids whose usage
     * may have changed (old and new).
     *
     * @param  array<string, list<int>>  $roles
     * @return list<int>
     */
    private function syncMedia(int $sectionId, array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        $table = $this->connection()->table('website_section_media');
        $previous = $table->clone()->where('website_section_id', $sectionId)->whereIn('role', array_keys($roles))->pluck('media_asset_id')->all();

        $table->clone()->where('website_section_id', $sectionId)->whereIn('role', array_keys($roles))->delete();

        $now = Carbon::now();
        $rows = [];

        foreach ($roles as $role => $ids) {
            foreach (array_values($ids) as $index => $id) {
                $rows[] = [
                    'website_section_id' => $sectionId,
                    'media_asset_id' => $id,
                    'role' => $role,
                    'sort_order' => ($index + 1) * 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            $this->connection()->table('website_section_media')->insert($rows);
        }

        return array_values(array_unique(array_map('intval', array_merge($previous, ...array_values($roles)))));
    }

    /**
     * After commit: refresh the usage caches the delete guards read.
     *
     * @param  array<int, mixed>  $assetIds
     * @param  array<int, mixed>  $ctaIds
     */
    private function afterReferencesChanged(array $assetIds, array $ctaIds): void
    {
        $assetIds = array_values(array_unique(array_filter(array_map('intval', $assetIds))));
        $ctaIds = array_values(array_unique(array_filter(array_map('intval', $ctaIds))));

        if ($assetIds !== []) {
            $this->media->recountUsage($assetIds);
        }

        if ($ctaIds === []) {
            return;
        }

        $counts = $this->connection()->table('website_sections')
            ->whereIn('cta_block_id', $ctaIds)->whereNull('deleted_at')
            ->groupBy('cta_block_id')
            ->selectRaw('cta_block_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'cta_block_id')
            ->all();

        foreach ($ctaIds as $ctaId) {
            $this->connection()->table('cta_blocks')->where('id', $ctaId)->update(['usage_count' => (int) ($counts[$ctaId] ?? 0)]);
        }
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function touchDraft(int $id, array $update): void
    {
        $now = Carbon::now();

        $this->connection()->table('website_sections')->where('id', $id)->update(array_merge($update, [
            'draft_updated_at' => $now,
            'updated_at' => $now,
            'updated_by' => $this->auditor->actorId(),
        ]));
    }

    /**
     * Stored field values keyed by field key: content keys from `content`, reference keys from their
     * real columns.
     *
     * @return array<string, mixed>
     */
    private function currentFields(object $row): array
    {
        $key = (string) $row->section_key;
        $content = $this->decode($row->content);
        $fields = [];

        foreach (SectionRegistry::fields($key) as $name => $field) {
            if ($field['stored'] === SectionRegistry::STORED_CONTENT && array_key_exists($name, $content)) {
                $fields[$name] = $content[$name];
            }

            if ($field['stored'] === SectionRegistry::STORED_COLUMN) {
                $fields[$name] = $row->{$field['column']} ?? null;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function currentItemFields(string $key, string $group, object $item): array
    {
        $content = $this->decode($item->content);
        $fields = ['is_enabled' => (bool) $item->is_enabled];

        foreach (SectionRegistry::itemFields($key, $group) as $name => $field) {
            if ($field['stored'] === SectionRegistry::STORED_CONTENT) {
                if (array_key_exists($name, $content)) {
                    $fields[$name] = $content[$name];
                }

                continue;
            }

            $column = (string) ($field['column'] ?? $name);
            $fields[$name] = $item->{$column} ?? null;
        }

        return $fields;
    }

    private function assertPlacementPage(SectionPlacement $placement, ?Page $page): ?int
    {
        if (! $placement->allowsPage()) {
            if ($page !== null) {
                throw InvalidSectionContentException::withErrors(
                    sprintf('The %s placement does not belong to a page.', $placement->label()),
                    ['page' => ['Only page sections belong to a page.']]
                );
            }

            return null;
        }

        if ($page === null || ! $page->exists) {
            throw InvalidSectionContentException::withErrors('Page sections need a page.', ['page' => ['Choose the page this section belongs to.']]);
        }

        $layout = $this->connection()->table('pages')->where('id', $page->getKey())->whereNull('deleted_at')->value('layout');

        if ($layout !== 'sections') {
            throw InvalidSectionContentException::withErrors('That page is not composed of sections.', [
                'page' => ['Switch the page layout to "Composed of sections" first.'],
            ]);
        }

        return (int) $page->getKey();
    }

    private function assertEditable(object $row): void
    {
        if ($row->deleted_at !== null) {
            throw InvalidSectionContentException::withErrors('That section is in the trash.', ['section' => ['Restore it before editing.']]);
        }

        if ((string) $row->status === ContentStatus::Archived->value) {
            throw ContentActionNotAllowedException::archived($this->label($row));
        }
    }

    /**
     * @param  list<int>  $current
     * @param  list<int>  $given
     */
    private function assertExactSet(array $current, array $given): void
    {
        $sortedCurrent = $current;
        $sortedGiven = $given;
        sort($sortedCurrent);
        sort($sortedGiven);

        if ($sortedCurrent !== $sortedGiven) {
            throw InvalidSectionContentException::staleOrder($current, $given);
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        $clean = [];

        foreach (array_values($ids) as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw InvalidSectionContentException::staleOrder([], []);
            }

            $clean[] = (int) $id;
        }

        if (count($clean) !== count(array_unique($clean))) {
            throw InvalidSectionContentException::staleOrder(array_values(array_unique($clean)), $clean);
        }

        return $clean;
    }

    /**
     * `sort_order` = 10, 20, 30 ... for the given ids, in one statement (INV-5).
     *
     * @param  list<int>  $orderedIds
     */
    private function writeOrder(string $table, array $orderedIds): void
    {
        if ($orderedIds === []) {
            return;
        }

        $cases = [];
        $bindings = [];

        foreach ($orderedIds as $index => $id) {
            $cases[] = 'WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = ($index + 1) * 10;
        }

        $bindings[] = Carbon::now();
        $bindings[] = $this->auditor->actorId();

        $this->connection()->update(
            sprintf(
                'UPDATE `%s` SET `sort_order` = CASE `id` %s END, `updated_at` = ?, `updated_by` = ? WHERE `id` IN (%s)',
                $table === 'website_sections' ? 'website_sections' : 'website_section_items',
                implode(' ', $cases),
                implode(', ', array_fill(0, count($orderedIds), '?'))
            ),
            array_merge($bindings, $orderedIds)
        );
    }

    private function liveInPlacement(SectionPlacement $placement, ?int $pageId): QueryBuilder
    {
        return $this->connection()->table('website_sections')
            ->where('placement', $placement->value)
            ->where('page_id', $pageId)
            ->whereNull('deleted_at');
    }

    private function lockRow(int $id): object
    {
        $row = $this->connection()->table('website_sections')->where('id', $id)->lockForUpdate()->first();

        if ($row === null) {
            throw InvalidSectionContentException::withErrors('That section no longer exists.', ['section' => ['It may have been removed in another tab.']]);
        }

        return $row;
    }

    private function row(int $id): object
    {
        $row = $this->connection()->table('website_sections')->where('id', $id)->first();

        if ($row === null) {
            throw InvalidSectionContentException::withErrors('That section no longer exists.', ['section' => ['It may have been removed in another tab.']]);
        }

        return $row;
    }

    private function itemRow(int $itemId, int $sectionId, string $group): object
    {
        $item = $this->connection()->table('website_section_items')
            ->where('id', $itemId)->whereNull('deleted_at')->lockForUpdate()->first();

        if ($item === null || (int) $item->website_section_id !== $sectionId || (string) $item->group !== $group) {
            throw InvalidSectionContentException::withErrors('That entry does not belong to this list.', ['item' => ['Reload the editor and try again.']]);
        }

        return $item;
    }

    private function find(int $id): WebsiteSection
    {
        /** @var WebsiteSection */
        return WebsiteSection::query()->withoutGlobalScopes()->findOrFail($id);
    }

    private function liveAssetId(mixed $id): ?int
    {
        if (! is_numeric($id)) {
            return null;
        }

        return $this->connection()->table('media_assets')->where('id', (int) $id)->whereNull('deleted_at')->exists() ? (int) $id : null;
    }

    private function label(object $row): string
    {
        $name = trim((string) ($row->name ?? ''));

        if ($name !== '') {
            return $name;
        }

        $key = (string) $row->section_key;

        return SectionRegistry::exists($key) ? SectionRegistry::label($key) : $key;
    }

    private function cleanName(?string $name): ?string
    {
        $name = $name === null ? '' : trim(strip_tags($name));

        return $name === '' ? null : mb_substr($name, 0, 150);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $value
     *
     * @throws JsonException
     */
    private function encode(array $value): string
    {
        return json_encode($value === [] ? new stdClass : $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= $this->connection()->getSchemaBuilder()->hasTable($table);
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
