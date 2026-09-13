<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\ButtonStyle;
use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\FaqSource;
use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\MenuItemLinkType;
use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\MenuVisibility;
use App\Enums\Cms\StatisticValueMode;
use App\Models\Cms\CtaBlock;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\WebsiteSection;
use App\Support\Cms\SectionRegistry;
use App\Support\RichText;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Builds the array a public section partial renders — the `published_content` snapshot of D22
 * (phase-03 §2.2, §6.2 `publish()`, FT-09) and, identically, the draft payload preview renders
 * (§6.12).
 *
 * Shape (every key always present; a partial never has to guess):
 *
 *     [
 *       'id', 'section_key', 'placement', 'page_id', 'name', 'anchor', 'content_hash',
 *       'fields'   => [...the type's content fields, rich text sanitised, links normalised],
 *       'items'    => [group => [['id', 'content', 'metric', 'value_mode', 'manual_value',
 *                                  'value', 'is_live', 'media'], ...]],   // enabled only, in order
 *       'media'    => [role => media array | list of media arrays],       // MediaService::toSnapshot()
 *       'cta'      => ?array,                                             // resolved CTA block
 *       'menus'    => [field => ?tree],                                   // every menu_ref field
 *       'faqs'     => ?list,                                              // the faq type only
 *       'provider' => mixed,                                              // non-live provider output
 *       'built_at' => ISO-8601,
 *     ]
 *
 * Invariants:
 *
 *   · **Enabled items only, in `sort_order`.** A disabled item never reaches the snapshot, so
 *     changing a disabled item cannot change the live page (FT-09, FT-45).
 *   · **No foreign key survives as a bare id (INV-3).** CTA blocks, menus, media and FAQs are
 *     resolved into their public content at build time; a draft CTA, a trashed page link, an inactive
 *     menu or a trashed image resolves to nothing rather than to a dead reference.
 *   · **Unsafe values cannot pass.** Rich text is re-sanitised (the database is not a trust boundary),
 *     and every href is re-checked with `RichText::isSafeHref()`.
 *   · **Statistic values stay decimal strings.** A `manual` item carries its `value`; an `auto` item
 *     carries `value = null` and `is_live = true`, because a live count must not freeze at publish —
 *     the renderer resolves it through `StatisticsProvider::valueFor()` (§6.11, INV-12), falling back
 *     to `manual_value`.
 *   · **Read-only.** This class never writes.
 */
final class SnapshotBuilder
{
    /** @var array<string, bool> */
    private array $tables = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly MediaService $media,
        private readonly Router $router,
        private readonly UrlGenerator $url,
        private readonly Container $container,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(WebsiteSection $section): array
    {
        $key = (string) $section->getAttribute('section_key');
        $row = $this->row((int) $section->getKey());
        $content = $this->decode($row->content ?? null);

        $fields = SectionRegistry::fields($key);
        $snapshot = [
            'id' => (int) $section->getKey(),
            'section_key' => $key,
            'placement' => (string) $row->placement,
            'page_id' => $row->page_id === null ? null : (int) $row->page_id,
            'name' => $row->name !== null && $row->name !== '' ? (string) $row->name : SectionRegistry::label($key),
            'anchor' => $row->anchor,
            'content_hash' => $row->content_hash,
            'fields' => [],
            'items' => [],
            'media' => [],
            'cta' => null,
            'menus' => [],
            'faqs' => null,
            'provider' => null,
            'built_at' => Carbon::now()->toIso8601String(),
        ];

        foreach ($fields as $name => $field) {
            if ($field['stored'] !== SectionRegistry::STORED_CONTENT) {
                continue;
            }

            $snapshot['fields'][$name] = $this->publicValue($field, $content[$name] ?? $field['default']);
        }

        ksort($snapshot['fields']);

        foreach ($fields as $name => $field) {
            if ($field['type'] === SectionRegistry::TYPE_CTA_REF) {
                $snapshot['cta'] = $this->cta($row->cta_block_id === null ? null : (int) $row->cta_block_id);
            }

            if ($field['type'] === SectionRegistry::TYPE_MENU_REF) {
                $snapshot['menus'][$name] = $field['ref_by'] === SectionRegistry::REF_BY_LOCATION
                    ? $this->menuByLocation(is_string($content[$name] ?? null) ? $content[$name] : (string) $field['default'])
                    : $this->menuById($row->menu_id === null ? null : (int) $row->menu_id);
            }
        }

        $snapshot['media'] = $this->mediaFor($section, $key);
        $snapshot['items'] = $this->itemsFor($section, $key);

        if ($key === 'faq') {
            $snapshot['faqs'] = $this->faqs((int) $section->getKey(), $content);
        }

        $snapshot['provider'] = $this->provider($section, $key);

        return $snapshot;
    }

    /*
    |--------------------------------------------------------------------------
    | Fields
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $field
     */
    private function publicValue(array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            SectionRegistry::TYPE_RICHTEXT => is_string($value) && $value !== '' ? RichText::sanitize($value) : null,
            SectionRegistry::TYPE_LINK => $this->link(is_array($value) ? $value : []),
            SectionRegistry::TYPE_URL => is_string($value) && RichText::isSafeHref($value) ? $value : null,
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{label: string|null, url: string|null, style: string, new_tab: bool, rel: string|null}|null
     */
    private function link(array $value): ?array
    {
        $label = trim((string) ($value['label'] ?? ''));
        $url = trim((string) ($value['url'] ?? ''));

        if ($label === '' && $url === '') {
            return null;
        }

        $newTab = filter_var($value['new_tab'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'label' => $label === '' ? null : $label,
            'url' => $url !== '' && RichText::isSafeHref($url) ? $url : null,
            'style' => (ButtonStyle::tryFrom((string) ($value['style'] ?? '')) ?? ButtonStyle::Primary)->value,
            'new_tab' => $newTab,
            'rel' => $newTab ? 'noopener noreferrer' : null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Items and media
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function itemsFor(WebsiteSection $section, string $key): array
    {
        $repeaters = SectionRegistry::repeaters($key);

        if ($repeaters === []) {
            return [];
        }

        $rows = $this->db->connection()->table('website_section_items')
            ->where('website_section_id', $section->getKey())
            ->whereNull('deleted_at')
            ->where('is_enabled', true)
            ->orderBy('group')->orderBy('sort_order')->orderBy('id')
            ->get();

        $assetIds = $rows->pluck('media_asset_id')->filter()->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $assets = $assetIds === []
            ? collect()
            : MediaAsset::query()->whereIn('id', $assetIds)->get()->keyBy(static fn (MediaAsset $asset): int => (int) $asset->getKey());

        $items = array_fill_keys(array_keys($repeaters), []);

        foreach ($rows as $row) {
            $group = (string) $row->group;

            if (! isset($repeaters[$group])) {
                continue;
            }

            $itemFields = $repeaters[$group]['fields'];
            $stored = $this->decode($row->content);
            $public = [];
            $itemProfile = ImageProfile::Icon;

            foreach ($itemFields as $name => $field) {
                if ($field['stored'] === SectionRegistry::STORED_CONTENT) {
                    $public[$name] = $this->publicValue($field, $stored[$name] ?? $field['default']);
                }

                if ($field['type'] === SectionRegistry::TYPE_IMAGE && $field['profile'] instanceof ImageProfile) {
                    $itemProfile = $field['profile'];
                }
            }

            ksort($public);

            $mode = StatisticValueMode::tryFrom((string) $row->value_mode) ?? StatisticValueMode::Manual;
            $manual = $row->manual_value === null ? null : (string) $row->manual_value;
            $asset = $row->media_asset_id === null ? null : $assets->get((int) $row->media_asset_id);

            $items[$group][] = [
                'id' => (int) $row->id,
                'content' => $public,
                'metric' => $row->metric,
                'value_mode' => $mode->value,
                'manual_value' => $manual,
                'value' => $mode === StatisticValueMode::Manual ? $manual : null,
                'is_live' => $mode === StatisticValueMode::Auto,
                'media' => $asset instanceof MediaAsset ? $this->media->toSnapshot($asset, $itemProfile) : null,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaFor(WebsiteSection $section, string $key): array
    {
        $roles = SectionRegistry::mediaRoles($key);

        if ($roles === []) {
            return [];
        }

        $pivot = $this->db->connection()->table('website_section_media')
            ->where('website_section_id', $section->getKey())
            ->orderBy('role')->orderBy('sort_order')
            ->get(['role', 'media_asset_id']);

        $assets = $pivot->isEmpty()
            ? collect()
            : MediaAsset::query()->whereIn('id', $pivot->pluck('media_asset_id')->all())->get()
                ->keyBy(static fn (MediaAsset $asset): int => (int) $asset->getKey());

        $byRole = [];

        foreach ($pivot as $row) {
            $asset = $assets->get((int) $row->media_asset_id);

            if ($asset instanceof MediaAsset && isset($roles[(string) $row->role])) {
                $byRole[(string) $row->role][] = $asset;
            }
        }

        $posterUrl = isset($byRole['video_poster'][0]) ? $this->media->url($byRole['video_poster'][0], 1280) : null;
        $media = [];

        foreach ($roles as $role => $slot) {
            $profile = $slot['profile'] instanceof ImageProfile ? $slot['profile'] : null;
            $list = array_map(
                fn (MediaAsset $asset): array => $this->media->toSnapshot($asset, $profile, $posterUrl),
                $byRole[$role] ?? []
            );

            $media[$role] = $slot['multiple'] ? $list : ($list[0] ?? null);
        }

        return $media;
    }

    /*
    |--------------------------------------------------------------------------
    | References
    |--------------------------------------------------------------------------
    */

    /**
     * A published CTA block as its public content, or null (draft, trashed, missing).
     *
     * @return array<string, mixed>|null
     */
    private function cta(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }

        /** @var CtaBlock|null $block */
        $block = CtaBlock::query()->whereKey($id)->where('status', ContentStatus::Published->value)->first();

        if ($block === null) {
            return null;
        }

        $background = $block->background_media_id === null
            ? null
            : MediaAsset::query()->whereKey($block->background_media_id)->first();

        $button = static function (?string $label, ?string $url, mixed $style, bool $newTab): ?array {
            $label = trim((string) $label);
            $url = trim((string) $url);

            if ($label === '' || $url === '' || ! RichText::isSafeHref($url)) {
                return null;
            }

            return [
                'label' => $label,
                'url' => $url,
                'style' => ($style instanceof ButtonStyle ? $style : (ButtonStyle::tryFrom((string) $style) ?? ButtonStyle::Primary))->value,
                'new_tab' => $newTab,
                'rel' => $newTab ? 'noopener noreferrer' : null,
            ];
        };

        return [
            'id' => (int) $block->getKey(),
            'key' => $block->key,
            'variant' => $block->variant?->value,
            'heading' => $block->heading,
            'subheading' => $block->subheading,
            'description' => $block->description,
            'primary' => $button($block->primary_label, $block->primary_url, $block->primary_style, (bool) $block->primary_new_tab),
            'secondary' => $button($block->secondary_label, $block->secondary_url, $block->secondary_style, (bool) $block->secondary_new_tab),
            'background' => $background instanceof MediaAsset ? $this->media->toSnapshot($background, ImageProfile::Hero) : null,
            'background_color' => $background instanceof MediaAsset ? null : $block->background_color,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function menuById(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }

        $menu = $this->db->connection()->table('menus')
            ->where('id', $id)->whereNull('deleted_at')->where('is_active', true)
            ->first(['id', 'name', 'slug', 'location']);

        return $menu === null ? null : $this->menuTree($menu);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function menuByLocation(string $location): ?array
    {
        $location = MenuLocation::tryFrom($location);

        if ($location === null) {
            return null;
        }

        $query = fn (MenuLocation $slot): ?object => $this->db->connection()->table('menus')
            ->where('location', $slot->value)->whereNull('deleted_at')->where('is_active', true)
            ->first(['id', 'name', 'slug', 'location']);

        $menu = $query($location);

        if ($menu === null && $location->fallback() !== null) {
            $menu = $query($location->fallback());
        }

        return $menu === null ? null : $this->menuTree($menu);
    }

    /**
     * The resolved two-level tree: enabled items whose target resolves, URLs computed, visibility kept
     * as data so the header component filters per request *after* the cache (§6.3, FT-44).
     *
     * @return array<string, mixed>
     */
    private function menuTree(object $menu): array
    {
        $rows = $this->db->connection()->table('menu_items as i')
            ->leftJoin('pages as p', static function ($join): void {
                $join->on('p.id', '=', 'i.page_id')
                    ->whereNull('p.deleted_at')
                    ->where('p.status', ContentStatus::Published->value);
            })
            ->where('i.menu_id', $menu->id)
            ->whereNull('i.deleted_at')
            ->where('i.is_enabled', true)
            ->orderBy('i.depth')->orderBy('i.sort_order')->orderBy('i.id')
            ->get([
                'i.id', 'i.parent_id', 'i.label', 'i.link_type', 'i.route_name', 'i.route_params', 'i.url',
                'i.anchor', 'i.icon', 'i.open_new_tab', 'i.rel_nofollow', 'i.visibility', 'p.slug as page_slug',
            ]);

        $nodes = [];
        $children = [];

        foreach ($rows as $row) {
            $type = MenuItemLinkType::tryFrom((string) $row->link_type);

            if ($type === null) {
                continue;
            }

            $url = $this->resolveMenuUrl($type, $row);

            if ($type !== MenuItemLinkType::None && $url === null) {
                continue; // an unresolvable target is omitted, never rendered dead (§12.1 R-3)
            }

            $newTab = (bool) $row->open_new_tab;
            $rel = array_filter([$newTab ? 'noopener noreferrer' : null, $row->rel_nofollow ? 'nofollow' : null]);

            $node = [
                'id' => (int) $row->id,
                'label' => (string) $row->label,
                'url' => $url,
                'link_type' => $type->value,
                'icon' => $row->icon,
                'new_tab' => $newTab,
                'rel' => $rel === [] ? null : implode(' ', $rel),
                'visibility' => (MenuVisibility::tryFrom((string) $row->visibility) ?? MenuVisibility::All)->value,
                'children' => [],
            ];

            if ($row->parent_id === null) {
                $nodes[(int) $row->id] = $node;
            } else {
                $children[(int) $row->parent_id][] = $node;
            }
        }

        foreach ($children as $parentId => $list) {
            if (isset($nodes[$parentId])) {
                $nodes[$parentId]['children'] = $list;
            }
        }

        // A label-only parent whose every child was omitted has nothing to open.
        $nodes = array_filter(
            $nodes,
            static fn (array $node): bool => $node['link_type'] !== MenuItemLinkType::None->value || $node['children'] !== []
        );

        return [
            'id' => (int) $menu->id,
            'name' => (string) $menu->name,
            'slug' => (string) $menu->slug,
            'location' => (string) $menu->location,
            'items' => array_values($nodes),
        ];
    }

    private function resolveMenuUrl(MenuItemLinkType $type, object $row): ?string
    {
        try {
            return match ($type) {
                MenuItemLinkType::Page => $row->page_slug === null ? null : $this->pageUrl((string) $row->page_slug),
                MenuItemLinkType::Route => $this->routeUrl($row->route_name, $row->route_params),
                MenuItemLinkType::SectionAnchor => trim((string) $row->anchor) === ''
                    ? null
                    : $this->rootPath().'/#'.ltrim((string) $row->anchor, '#'),
                MenuItemLinkType::Url => is_string($row->url) && RichText::isSafeHref($row->url) ? trim($row->url) : null,
                MenuItemLinkType::None => null,
            };
        } catch (Throwable) {
            return null; // a route that disappeared hides the item instead of throwing (FT-29)
        }
    }

    /*
     * Menu links are frozen into the header / footer snapshot, so they are stored **root-relative**
     * (integration K-8): a snapshot published from the console, or under another host or base path,
     * must not carry that origin into every page it renders on.
     */
    private function pageUrl(string $slug): string
    {
        return $this->router->has('site.page')
            ? $this->url->route('site.page', ['slug' => $slug], false)
            : $this->rootPath().'/'.$slug;
    }

    private function routeUrl(mixed $name, mixed $params): ?string
    {
        $name = is_string($name) ? trim($name) : '';

        if ($name === '' || ! $this->router->has($name)) {
            return null;
        }

        $params = is_string($params) ? json_decode($params, true) : $params;

        return $this->url->route($name, is_array($params) ? $params : [], false);
    }

    /**
     * The application's base path without a trailing slash (`''` at a domain root, `/my%20office/public`
     * under a sub-directory) — the root-relative prefix of an anchor or page link.
     */
    private function rootPath(): string
    {
        $path = parse_url($this->url->to('/'), PHP_URL_PATH);

        return is_string($path) ? rtrim($path, '/') : '';
    }

    /**
     * The FAQ section's three sources (§6.13 `FaqService::forSection()`), read-only. The category is
     * referenced by slug (a scalar, INV-3).
     *
     * @param  array<string, mixed>  $content
     * @return list<array<string, mixed>>
     */
    private function faqs(int $sectionId, array $content): array
    {
        if (! $this->hasTable('faqs')) {
            return [];
        }

        $connection = $this->db->connection();
        $source = FaqSource::tryFrom((string) ($content['source'] ?? '')) ?? FaqSource::Category;
        $query = $connection->table('faqs as f')
            ->whereNull('f.deleted_at')
            ->where('f.status', ContentStatus::Published->value);

        switch ($source) {
            case FaqSource::Category:
                $slug = trim((string) ($content['faq_category_ref'] ?? ''));

                if ($slug === '' || ! $this->hasTable('faq_categories')) {
                    return [];
                }

                $query->join('faq_categories as c', 'c.id', '=', 'f.faq_category_id')
                    ->where('c.slug', $slug)->whereNull('c.deleted_at')->where('c.is_enabled', true)
                    ->orderBy('f.sort_order')->orderBy('f.id');
                break;

            case FaqSource::Selected:
                if (! $this->hasTable('faq_website_section')) {
                    return [];
                }

                $query->join('faq_website_section as fw', 'fw.faq_id', '=', 'f.id')
                    ->where('fw.website_section_id', $sectionId)
                    ->orderBy('fw.sort_order')->orderBy('f.id');
                break;

            case FaqSource::Featured:
                $query->where('f.is_featured', true)->orderBy('f.sort_order')->orderBy('f.id');
                break;
        }

        return $query->limit(100)->get(['f.id', 'f.question', 'f.answer'])
            ->map(static fn (object $faq): array => [
                'id' => (int) $faq->id,
                'question' => (string) $faq->question,
                'answer' => RichText::sanitize((string) $faq->answer),
            ])
            ->values()
            ->all();
    }

    /**
     * Output of a non-live `SectionDataProvider`, frozen into the snapshot (§6.1, [D-W3-11]). A live
     * provider is resolved at render time instead, so it contributes nothing here.
     */
    private function provider(WebsiteSection $section, string $key): mixed
    {
        $class = SectionRegistry::provider($key);

        if ($class === null || SectionRegistry::isLive($key) || ! class_exists($class)) {
            return null;
        }

        $provider = $this->container->make($class);

        return method_exists($provider, 'resolve') ? $provider->resolve($section) : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function row(int $id): object
    {
        $row = $this->db->connection()->table('website_sections')->where('id', $id)->first();

        if ($row === null) {
            throw new \RuntimeException(sprintf('Website section #%d does not exist.', $id));
        }

        return $row;
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

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= $this->db->connection()->getSchemaBuilder()->hasTable($table);
    }
}
