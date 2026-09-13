<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\MenuItemLinkType;
use App\Enums\Cms\MenuVisibility;
use App\Models\Cms\Menu;
use App\Models\Cms\MenuItem;
use App\Models\Cms\Page;
use App\Services\Cms\Exceptions\ContentActionNotAllowedException;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use App\Support\RichText;
use BackedEnum;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Header and footer navigation (phase-03 §2.5, §2.6, §6.3 `MenuService`, §8.9, §102).
 *
 * One public entry point per operation:
 *
 *   storeItem()   add a link to a menu
 *   updateItem()  change a link — including the partial `['is_enabled' => bool]` of the toggle route
 *   deleteItem()  soft-delete a link (and its children)
 *   updateMenu()  rename, describe or (de)activate a menu
 *   reorder()     rewrite the whole two-level tree from its nested order
 *   resolveUrl()  the href an item points at today — computed, never stored
 *
 * Invariants:
 *
 *   · **INV-6 — two levels, refused before the CHECK has to.** A parent must be a live, top-level item of
 *     the same menu; an item that has children cannot become a child; an item cannot be its own parent
 *     or the parent of its own parent (FT-19, FT-20). `depth` is derived here, never accepted.
 *   · **Typed links (§2.6).** Exactly one target column is kept for the link type and the others are
 *     cleared. A `url` must be `http(s)://`, `mailto:`, `tel:` or a site path and pass
 *     `RichText::isSafeHref()` (INV-13, FT-29); a `route` must satisfy `Router::has()`; a `page` must be
 *     a live page row; an anchor must be a valid section anchor.
 *   · **INV-5 — the tree is rewritten from the exact current set**, in one transaction with the menu row
 *     locked, with contiguous `sort_order` per level; a stale tab cannot drop or add an item.
 *   · **Resolved, never stored.** `resolveUrl()` computes from the live target, so renaming a page slug
 *     fixes every menu at once; a vanished route or an unpublished page resolves to null, never throws.
 *   · **INV-16.** Every effective change is audited with old and new values and bumps the public cache
 *     after commit (menus are live, published on cache flush — §2.15); a no-op writes nothing.
 */
final class MenuService
{
    /** @var list<string> */
    public const ITEM_WRITABLE = [
        'parent_id', 'label', 'link_type', 'page_id', 'route_name', 'route_params', 'url', 'anchor',
        'icon', 'open_new_tab', 'rel_nofollow', 'visibility', 'is_enabled',
    ];

    /** @var list<string> */
    public const MENU_WRITABLE = ['name', 'description', 'is_active'];

    /** A menu never legitimately holds more links than this (`ReorderMenuRequest::MAX_NODES`). */
    public const MAX_ITEMS = 500;

    /** §6.3: `http`, `https`, `mailto`, `tel`, or a leading `/` — never a protocol-relative `//host`. */
    private const URL_PATTERN = '~^(https?://|mailto:|tel:|/(?![/\\\\]))~i';

    private const ANCHOR_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';

    private const ROUTE_PATTERN = '/^[A-Za-z0-9_.\-]{1,100}$/';

    private const MAX_ROUTE_PARAMS = 10;

    private const MODULE = 'menus';

    /** The target columns, cleared unless they belong to the item's link type. */
    private const TARGET_COLUMNS = ['page_id', 'route_name', 'url', 'anchor'];

    private const LINK_KEYS = ['link_type', 'page_id', 'route_name', 'route_params', 'url', 'anchor'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Router $router,
        private readonly UrlGenerator $url,
        private readonly SectionValidator $validator,
        private readonly CacheVersion $cache,
        private readonly CmsAuditor $auditor,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Items
    |--------------------------------------------------------------------------
    */

    /**
     * Add a link to a menu (§6.3 `storeItem()`), appended at the end of its level.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidSectionContentException for malformed input or an unresolvable target
     * @throws ContentActionNotAllowedException for depth, a foreign parent or a cycle
     */
    public function storeItem(Menu $menu, array $data): MenuItem
    {
        $this->assertKnownKeys($data, self::ITEM_WRITABLE, 'menu item');

        $id = $this->connection()->transaction(function () use ($menu, $data): int {
            $menuRow = $this->lockMenu((int) $menu->getKey());

            if ($menuRow->deleted_at !== null) {
                throw InvalidSectionContentException::withErrors('That menu is in the trash.', ['menu' => ['Restore it before adding links.']]);
            }

            $count = $this->connection()->table('menu_items')
                ->where('menu_id', $menuRow->id)->whereNull('deleted_at')->count();

            if ($count >= self::MAX_ITEMS) {
                throw InvalidSectionContentException::withErrors('This menu is full.', [
                    'menu' => [sprintf('A menu holds at most %d links.', self::MAX_ITEMS)],
                ]);
            }

            $values = $this->itemValues($data, null);
            $parent = $this->resolveParent($menuRow, $values['parent_id'], null);
            $now = Carbon::now();
            $actor = $this->auditor->actorId();

            $values['depth'] = $parent === null ? 0 : 1;
            $values['sort_order'] = $this->nextSort((int) $menuRow->id, $values['parent_id'], null);

            $id = (int) $this->connection()->table('menu_items')->insertGetId(array_merge($this->forStorage($values), [
                'menu_id' => $menuRow->id,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $actor,
                'updated_by' => $actor,
            ]));

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('Menu link added to %s: %s', $menuRow->name, $values['label']),
                subject: $this->findItem($id),
                properties: ['attributes' => array_merge($values, ['menu_id' => (int) $menuRow->id])],
                event: 'item_created',
            );

            $this->cache->bumpAfterCommit(sprintf('Menu #%d link added', $menuRow->id));

            return $id;
        });

        return $this->findItem($id);
    }

    /**
     * Change a link (§6.3 `updateItem()`). Only the keys present change; `['is_enabled' => bool]` alone
     * is the toggle route.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidSectionContentException
     * @throws ContentActionNotAllowedException for depth, a foreign parent, a cycle, or re-parenting an
     *                                          item that has children
     */
    public function updateItem(MenuItem $item, array $data): MenuItem
    {
        $this->assertKnownKeys($data, self::ITEM_WRITABLE, 'menu item');

        $this->connection()->transaction(function () use ($item, $data): void {
            $menuId = $this->connection()->table('menu_items')->where('id', $item->getKey())->value('menu_id');

            if ($menuId === null) {
                throw $this->itemGone();
            }

            $menuRow = $this->lockMenu((int) $menuId);
            $row = $this->connection()->table('menu_items')->where('id', $item->getKey())->lockForUpdate()->first();

            if ($row === null || $row->deleted_at !== null) {
                throw $this->itemGone();
            }

            $values = $this->itemValues($data, $row);
            $current = $this->currentValues($row);
            $changes = [];

            foreach ($values as $column => $value) {
                if ($this->comparable($current[$column] ?? null) !== $this->comparable($value)) {
                    $changes[$column] = $value;
                }
            }

            if ($changes === []) {
                return;
            }

            if (array_key_exists('parent_id', $changes)) {
                $parent = $this->resolveParent($menuRow, $changes['parent_id'], $row);
                $changes['depth'] = $parent === null ? 0 : 1;
                $changes['sort_order'] = $this->nextSort((int) $menuRow->id, $changes['parent_id'], (int) $row->id);
            }

            $this->connection()->table('menu_items')->where('id', $row->id)->update(array_merge($this->forStorage($changes), [
                'updated_at' => Carbon::now(),
                'updated_by' => $this->auditor->actorId(),
            ]));

            $toggle = array_keys($changes) === ['is_enabled'];

            $this->auditor->record(
                module: self::MODULE,
                description: $toggle
                    ? sprintf('Menu link %s: %s', $changes['is_enabled'] ? 'enabled' : 'disabled', $row->label)
                    : sprintf('Menu link updated in %s: %s', $menuRow->name, $changes['label'] ?? $row->label),
                subject: $this->findItem((int) $row->id),
                properties: $this->auditor->diff(array_intersect_key(array_merge($current, [
                    'depth' => (int) $row->depth,
                    'sort_order' => (int) $row->sort_order,
                ]), $changes), $changes),
                event: $toggle ? 'item_toggled' : 'item_updated',
            );

            $this->cache->bumpAfterCommit(sprintf('Menu #%d link changed', $menuRow->id));
        });

        return $this->findItem((int) $item->getKey());
    }

    /**
     * Soft-delete a link together with its children, so no child is left pointing at a trashed parent.
     */
    public function deleteItem(MenuItem $item): void
    {
        $this->connection()->transaction(function () use ($item): void {
            $menuId = $this->connection()->table('menu_items')->where('id', $item->getKey())->value('menu_id');

            if ($menuId === null) {
                return;
            }

            $menuRow = $this->lockMenu((int) $menuId);
            $row = $this->connection()->table('menu_items')->where('id', $item->getKey())->lockForUpdate()->first();

            if ($row === null || $row->deleted_at !== null) {
                return;
            }

            $children = $this->connection()->table('menu_items')
                ->where('parent_id', $row->id)->whereNull('deleted_at')
                ->lockForUpdate()
                ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

            $now = Carbon::now();

            $this->connection()->table('menu_items')
                ->whereIn('id', array_merge([(int) $row->id], $children))
                ->update(['deleted_at' => $now, 'updated_at' => $now, 'updated_by' => $this->auditor->actorId()]);

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('Menu link removed from %s: %s', $menuRow->name, $row->label),
                subject: $item,
                properties: [
                    'old' => ['deleted_at' => null, 'label' => (string) $row->label, 'link_type' => (string) $row->link_type],
                    'attributes' => ['deleted_at' => $now->toDateTimeString()],
                    'removed_children' => $children,
                ],
                event: 'item_deleted',
            );

            $this->cache->bumpAfterCommit(sprintf('Menu #%d link removed', $menuRow->id));
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Menus
    |--------------------------------------------------------------------------
    */

    /**
     * Rename, describe or (de)activate a menu. `slug` and `location` are not writable (§2.5, [D-W3-4]).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateMenu(Menu $menu, array $data): Menu
    {
        $this->assertKnownKeys($data, self::MENU_WRITABLE, 'menu');

        $this->connection()->transaction(function () use ($menu, $data): void {
            $row = $this->lockMenu((int) $menu->getKey());

            if ($row->deleted_at !== null) {
                throw InvalidSectionContentException::withErrors('That menu is in the trash.', ['menu' => ['Restore it before editing.']]);
            }

            $errors = [];
            $values = [];

            if (array_key_exists('name', $data)) {
                $name = $this->text($data['name']);

                if ($name === null || mb_strlen($name) > 100) {
                    $errors['name'][] = 'Enter a name of at most 100 characters.';
                } else {
                    $values['name'] = $name;
                }
            }

            if (array_key_exists('description', $data)) {
                $description = is_string($data['description']) || $data['description'] === null ? $this->text($data['description']) : false;

                if ($description === false || ($description !== null && mb_strlen($description) > 255)) {
                    $errors['description'][] = 'At most 255 characters of text.';
                } else {
                    $values['description'] = $description;
                }
            }

            if (array_key_exists('is_active', $data)) {
                $active = $this->bool($data['is_active']);

                if ($active === null) {
                    $errors['is_active'][] = 'This must be true or false.';
                } else {
                    $values['is_active'] = $active;
                }
            }

            if ($errors !== []) {
                throw InvalidSectionContentException::withErrors('The menu could not be saved.', $errors);
            }

            $changes = [];

            foreach ($values as $column => $value) {
                if ($this->comparable($row->{$column}) !== $this->comparable($value)) {
                    $changes[$column] = $value;
                }
            }

            if ($changes === []) {
                return;
            }

            $this->connection()->table('menus')->where('id', $row->id)->update(array_merge($changes, [
                'updated_at' => Carbon::now(),
                'updated_by' => $this->auditor->actorId(),
            ]));

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('Menu updated: %s', $changes['name'] ?? $row->name),
                subject: $menu,
                properties: $this->auditor->diff(array_intersect_key((array) $row, $changes), $changes),
                event: 'menu_updated',
            );

            $this->cache->bumpAfterCommit(sprintf('Menu #%d updated', $row->id));
        });

        /** @var Menu */
        return Menu::query()->withoutGlobalScopes()->findOrFail($menu->getKey());
    }

    /**
     * Rewrite the two-level tree (§6.3 `reorder()`): `[['id' => 4, 'children' => [['id' => 9]]], ...]`.
     *
     * @param  array<int, mixed>  $tree
     *
     * @throws InvalidSectionContentException when the flattened ids are not exactly the menu's live set
     * @throws ContentActionNotAllowedException for a third level
     */
    public function reorder(Menu $menu, array $tree): void
    {
        [$nodes, $flat] = $this->normaliseTree($tree);

        $this->connection()->transaction(function () use ($menu, $nodes, $flat): void {
            $menuRow = $this->lockMenu((int) $menu->getKey());

            $rows = $this->connection()->table('menu_items')
                ->where('menu_id', $menuRow->id)->whereNull('deleted_at')
                ->orderBy('depth')->orderBy('sort_order')->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'parent_id', 'depth', 'sort_order']);

            $current = $rows->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

            $this->assertExactSet($current, $flat);

            $desired = [];

            foreach ($nodes as $index => $node) {
                $desired[$node['id']] = ['parent_id' => null, 'depth' => 0, 'sort_order' => ($index + 1) * 10];

                foreach ($node['children'] as $childIndex => $childId) {
                    $desired[$childId] = ['parent_id' => $node['id'], 'depth' => 1, 'sort_order' => ($childIndex + 1) * 10];
                }
            }

            $changed = false;

            foreach ($rows as $row) {
                $want = $desired[(int) $row->id];

                if ($want['parent_id'] !== ($row->parent_id === null ? null : (int) $row->parent_id)
                    || $want['depth'] !== (int) $row->depth
                    || $want['sort_order'] !== (int) $row->sort_order) {
                    $changed = true;

                    break;
                }
            }

            if (! $changed) {
                return;
            }

            $this->writeTree($desired);

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('Menu reordered: %s', $menuRow->name),
                subject: $menu,
                properties: ['old' => ['tree' => $this->treeOf($rows->all())], 'attributes' => ['tree' => $nodes]],
                event: 'reordered',
            );

            $this->cache->bumpAfterCommit(sprintf('Menu #%d reordered', $menuRow->id));
        });
    }

    /**
     * The href an item points at today, or null when it has none or its target does not resolve
     * (§6.3 `resolveUrl()`, FT-29). Never stored, never throws.
     */
    public function resolveUrl(MenuItem $item): ?string
    {
        try {
            $type = $item->getAttribute('link_type');
            $type = $type instanceof MenuItemLinkType ? $type : MenuItemLinkType::tryFrom($this->scalar($type));

            return match ($type) {
                MenuItemLinkType::Page => $this->pageUrl($item),
                MenuItemLinkType::Route => $this->routeUrl($item->getAttribute('route_name'), $item->getAttribute('route_params')),
                MenuItemLinkType::SectionAnchor => $this->anchorUrl($item->getAttribute('anchor')),
                MenuItemLinkType::Url => $this->externalUrl($item->getAttribute('url')),
                MenuItemLinkType::None, null => null,
            };
        } catch (Throwable) {
            return null; // a route that disappeared, or a parameter it now needs, hides the link (FT-29)
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The full, validated column set of an item: the stored row overlaid with the keys present (or the
     * defaults for a new item). Link targets are validated whenever a link key is present or the item is
     * new; the target columns of other link types are cleared.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function itemValues(array $data, ?object $row): array
    {
        $values = $row === null ? [
            'parent_id' => null,
            'label' => null,
            'link_type' => MenuItemLinkType::Url->value,
            'page_id' => null,
            'route_name' => null,
            'route_params' => null,
            'url' => null,
            'anchor' => null,
            'icon' => null,
            'open_new_tab' => false,
            'rel_nofollow' => false,
            'visibility' => MenuVisibility::All->value,
            'is_enabled' => true,
        ] : $this->currentValues($row);

        $errors = [];

        if ($row === null && ! array_key_exists('label', $data)) {
            $errors['label'][] = 'Enter the text of the link.';
        }

        if ($row === null && ! array_key_exists('link_type', $data)) {
            $errors['link_type'][] = 'Choose what the link points at.';
        }

        foreach ($data as $column => $value) {
            switch ($column) {
                case 'label':
                    $label = $this->text($value);

                    if ($label === null || mb_strlen($label) > 100) {
                        $errors[$column][] = 'Enter the text of the link (at most 100 characters).';
                    } else {
                        $values[$column] = $label;
                    }

                    break;

                case 'link_type':
                    $type = $value instanceof MenuItemLinkType ? $value : MenuItemLinkType::tryFrom($this->scalar($value));

                    if ($type === null) {
                        $errors[$column][] = 'Choose one of the listed link types.';
                    } else {
                        $values[$column] = $type->value;
                    }

                    break;

                case 'parent_id':
                case 'page_id':
                    if ($value === null || $value === '') {
                        $values[$column] = null;
                    } elseif (($int = $this->positiveInt($value)) === null) {
                        $errors[$column][] = 'Choose an entry from the list.';
                    } else {
                        $values[$column] = $int;
                    }

                    break;

                case 'route_name':
                case 'url':
                case 'anchor':
                case 'icon':
                    if ($value !== null && ! is_string($value)) {
                        $errors[$column][] = 'This must be text.';
                    } else {
                        $text = $this->text($value);
                        $values[$column] = $column === 'anchor' && $text !== null ? ltrim($text, '#') : $text;
                    }

                    break;

                case 'route_params':
                    $params = $this->routeParams($value);

                    if ($params === false) {
                        $errors[$column][] = sprintf('At most %d text parameters of up to 100 characters each.', self::MAX_ROUTE_PARAMS);
                    } else {
                        $values[$column] = $params;
                    }

                    break;

                case 'open_new_tab':
                case 'rel_nofollow':
                case 'is_enabled':
                    $flag = $this->bool($value);

                    if ($flag === null) {
                        $errors[$column][] = 'This must be true or false.';
                    } else {
                        $values[$column] = $flag;
                    }

                    break;

                case 'visibility':
                    $visibility = $value instanceof MenuVisibility ? $value : MenuVisibility::tryFrom($this->scalar($value));

                    if ($visibility === null) {
                        $errors[$column][] = 'Choose who sees this link.';
                    } else {
                        $values[$column] = $visibility->value;
                    }

                    break;
            }
        }

        if (array_key_exists('icon', $data) && $values['icon'] !== null && ! $this->iconAllowed((string) $values['icon'])) {
            $errors['icon'][] = 'Choose an icon from the list.';
        }

        if ($errors === [] && ($row === null || array_intersect(array_keys($data), self::LINK_KEYS) !== [])) {
            $errors = $this->targetErrors($values);
        }

        if ($errors !== []) {
            throw InvalidSectionContentException::withErrors('The menu link could not be saved.', $errors);
        }

        $type = MenuItemLinkType::from((string) $values['link_type']);

        foreach (self::TARGET_COLUMNS as $column) {
            if ($column !== $type->targetColumn()) {
                $values[$column] = null;
            }
        }

        if ($type !== MenuItemLinkType::Route) {
            $values['route_params'] = null;
        }

        return $values;
    }

    /**
     * The link type's own target must be present and resolvable (§6.3).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, list<string>>
     */
    private function targetErrors(array $values): array
    {
        $type = MenuItemLinkType::tryFrom((string) $values['link_type']);

        switch ($type) {
            case MenuItemLinkType::Page:
                if ($values['page_id'] === null) {
                    return ['page_id' => ['Choose the page this item links to.']];
                }

                $exists = $this->connection()->table('pages')->where('id', $values['page_id'])->whereNull('deleted_at')->exists();

                return $exists ? [] : ['page_id' => ['That page no longer exists or is in the trash.']];

            case MenuItemLinkType::Route:
                $name = (string) ($values['route_name'] ?? '');

                if ($name === '') {
                    return ['route_name' => ['Choose the route this item links to.']];
                }

                if (preg_match(self::ROUTE_PATTERN, $name) !== 1 || ! $this->router->has($name)) {
                    return ['route_name' => [ContentActionNotAllowedException::unknownRoute($name)->getMessage()]];
                }

                return [];

            case MenuItemLinkType::SectionAnchor:
                $anchor = (string) ($values['anchor'] ?? '');

                if ($anchor === '') {
                    return ['anchor' => ['Choose the section this item scrolls to.']];
                }

                return preg_match(self::ANCHOR_PATTERN, $anchor) === 1
                    ? []
                    : ['anchor' => ['Use lowercase letters, numbers and hyphens, up to 64 characters.']];

            case MenuItemLinkType::Url:
                $url = (string) ($values['url'] ?? '');

                if ($url === '') {
                    return ['url' => ['Enter the address this item links to.']];
                }

                return mb_strlen($url) <= 500 && preg_match(self::URL_PATTERN, $url) === 1 && RichText::isSafeHref($url)
                    ? []
                    : ['url' => [ContentActionNotAllowedException::unsafeUrl(mb_substr($url, 0, 80))->getMessage()]];

            case MenuItemLinkType::None:
                return [];

            default:
                return ['link_type' => ['Choose one of the listed link types.']];
        }
    }

    /**
     * INV-6: a parent is a live top-level item of the same menu; no cycle; an item with children stays
     * top level.
     */
    private function resolveParent(object $menuRow, ?int $parentId, ?object $item): ?object
    {
        if ($parentId === null) {
            return null;
        }

        if ($item !== null && $parentId === (int) $item->id) {
            throw ContentActionNotAllowedException::menuCycle();
        }

        $parent = $this->connection()->table('menu_items')->where('id', $parentId)->lockForUpdate()->first();

        if ($parent === null || $parent->deleted_at !== null) {
            throw InvalidSectionContentException::withErrors('The parent item no longer exists.', [
                'parent_id' => ['Choose a top-level item of this menu.'],
            ]);
        }

        if ((int) $parent->menu_id !== (int) $menuRow->id) {
            throw ContentActionNotAllowedException::foreignMenuParent();
        }

        if ($parent->parent_id !== null) {
            if ($item !== null && (int) $parent->parent_id === (int) $item->id) {
                throw ContentActionNotAllowedException::menuCycle();
            }

            throw ContentActionNotAllowedException::menuDepth();
        }

        if ($item !== null && $this->connection()->table('menu_items')
            ->where('parent_id', $item->id)->whereNull('deleted_at')->exists()) {
            throw ContentActionNotAllowedException::menuParentHasChildren((string) $item->label);
        }

        return $parent;
    }

    /**
     * @param  array<int, mixed>  $tree
     * @return array{0: list<array{id: int, children: list<int>}>, 1: list<int>}
     */
    private function normaliseTree(array $tree): array
    {
        $nodes = [];
        $flat = [];

        foreach (array_values($tree) as $node) {
            $id = is_array($node) ? $this->positiveInt($node['id'] ?? null) : null;
            $children = is_array($node) ? ($node['children'] ?? []) : null;

            if ($id === null || ($children !== null && ! is_array($children))) {
                throw InvalidSectionContentException::staleOrder([], []);
            }

            $kids = [];

            foreach (array_values((array) $children) as $child) {
                $childId = is_array($child) ? $this->positiveInt($child['id'] ?? null) : null;

                if ($childId === null) {
                    throw InvalidSectionContentException::staleOrder([], []);
                }

                if (! empty($child['children'])) {
                    throw ContentActionNotAllowedException::menuDepth();
                }

                $kids[] = $childId;
            }

            $nodes[] = ['id' => $id, 'children' => $kids];
            $flat[] = $id;
            array_push($flat, ...$kids);
        }

        if (count($flat) !== count(array_unique($flat))) {
            throw InvalidSectionContentException::staleOrder(array_values(array_unique($flat)), $flat);
        }

        return [$nodes, $flat];
    }

    /**
     * One statement for the whole tree: `parent_id`, `depth` and `sort_order` together, so every row
     * satisfies `chk_mi_parent` and `chk_mi_depth` as it is written.
     *
     * @param  array<int, array{parent_id: int|null, depth: int, sort_order: int}>  $desired
     */
    private function writeTree(array $desired): void
    {
        $parents = [];
        $depths = [];
        $sorts = [];
        $parentBindings = [];
        $depthBindings = [];
        $sortBindings = [];

        foreach ($desired as $id => $want) {
            $parents[] = 'WHEN ? THEN ?';
            $parentBindings[] = $id;
            $parentBindings[] = $want['parent_id'];

            $depths[] = 'WHEN ? THEN ?';
            $depthBindings[] = $id;
            $depthBindings[] = $want['depth'];

            $sorts[] = 'WHEN ? THEN ?';
            $sortBindings[] = $id;
            $sortBindings[] = $want['sort_order'];
        }

        $ids = array_keys($desired);

        $this->connection()->update(
            sprintf(
                'UPDATE `menu_items` SET `parent_id` = CASE `id` %s END, `depth` = CASE `id` %s END, `sort_order` = CASE `id` %s END, `updated_at` = ?, `updated_by` = ? WHERE `id` IN (%s)',
                implode(' ', $parents),
                implode(' ', $depths),
                implode(' ', $sorts),
                implode(', ', array_fill(0, count($ids), '?'))
            ),
            array_merge($parentBindings, $depthBindings, $sortBindings, [Carbon::now(), $this->auditor->actorId()], $ids)
        );
    }

    /**
     * @param  list<object>  $rows
     * @return list<array{id: int, children: list<int>}>
     */
    private function treeOf(array $rows): array
    {
        $roots = [];
        $children = [];

        foreach ($rows as $row) {
            if ($row->parent_id === null) {
                $roots[] = (int) $row->id;
            } else {
                $children[(int) $row->parent_id][] = (int) $row->id;
            }
        }

        return array_map(static fn (int $id): array => ['id' => $id, 'children' => $children[$id] ?? []], $roots);
    }

    private function nextSort(int $menuId, ?int $parentId, ?int $exceptId): int
    {
        return (int) $this->connection()->table('menu_items')
            ->where('menu_id', $menuId)
            ->where('parent_id', $parentId)
            ->whereNull('deleted_at')
            ->when($exceptId !== null, static fn ($query) => $query->where('id', '!=', $exceptId))
            ->max('sort_order') + 10;
    }

    /**
     * The stored row as the same typed shape `itemValues()` returns.
     *
     * @return array<string, mixed>
     */
    private function currentValues(object $row): array
    {
        $params = is_string($row->route_params) ? json_decode($row->route_params, true) : $row->route_params;

        return [
            'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
            'label' => (string) $row->label,
            'link_type' => (string) $row->link_type,
            'page_id' => $row->page_id === null ? null : (int) $row->page_id,
            'route_name' => $row->route_name,
            'route_params' => is_array($params) && $params !== [] ? $params : null,
            'url' => $row->url,
            'anchor' => $row->anchor,
            'icon' => $row->icon,
            'open_new_tab' => (bool) $row->open_new_tab,
            'rel_nofollow' => (bool) $row->rel_nofollow,
            'visibility' => (string) $row->visibility,
            'is_enabled' => (bool) $row->is_enabled,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function forStorage(array $values): array
    {
        if (array_key_exists('route_params', $values)) {
            $values['route_params'] = $values['route_params'] === null
                ? null
                : json_encode($values['route_params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return $values;
    }

    /**
     * @return array<string, string>|null|false false when malformed
     */
    private function routeParams(mixed $value): array|null|false
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (! is_array($value) || count($value) > self::MAX_ROUTE_PARAMS) {
            return false;
        }

        $params = [];

        foreach ($value as $key => $param) {
            if ($param !== null && ! is_scalar($param)) {
                return false;
            }

            $param = $param === null ? '' : (is_bool($param) ? ($param ? '1' : '0') : (string) $param);

            if (mb_strlen($param) > 100) {
                return false;
            }

            $params[(string) $key] = $param;
        }

        return $params;
    }

    private function pageUrl(MenuItem $item): ?string
    {
        if ($item->relationLoaded('page')) {
            $page = $item->getRelation('page');

            if (! $page instanceof Page || $page->trashed() || ! $page->isPublic() || ! is_string($page->slug)) {
                return null;
            }

            $slug = $page->slug;
        } else {
            $pageId = $item->getAttribute('page_id');

            $slug = $pageId === null ? null : $this->connection()->table('pages')
                ->where('id', $pageId)
                ->whereNull('deleted_at')
                ->where('status', ContentStatus::Published->value)
                ->value('slug');
        }

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return $this->router->has('site.page')
            ? $this->url->route('site.page', ['slug' => $slug])
            : rtrim($this->url->to('/'), '/').'/'.$slug;
    }

    private function routeUrl(mixed $name, mixed $params): ?string
    {
        $name = is_string($name) ? trim($name) : '';

        if ($name === '' || ! $this->router->has($name)) {
            return null;
        }

        $params = is_string($params) ? json_decode($params, true) : $params;

        return $this->url->route($name, is_array($params) ? $params : []);
    }

    private function anchorUrl(mixed $anchor): ?string
    {
        $anchor = is_string($anchor) ? ltrim(trim($anchor), '#') : '';

        return $anchor === '' ? null : rtrim($this->url->to('/'), '/').'/#'.$anchor;
    }

    private function externalUrl(mixed $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';

        return $url !== '' && RichText::isSafeHref($url) ? $url : null;
    }

    private function iconAllowed(string $icon): bool
    {
        if (mb_strlen($icon) > 64) {
            return false;
        }

        $icons = $this->validator->icons();

        return $icons === null
            ? preg_match(SectionValidator::ICON_PATTERN, $icon) === 1
            : in_array($icon, $icons, true);
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
     * @param  array<string, mixed>  $data
     * @param  list<string>  $writable
     */
    private function assertKnownKeys(array $data, array $writable, string $noun): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($data)), $writable));

        if ($unknown !== []) {
            throw InvalidSectionContentException::withErrors(
                sprintf('Unknown %s fields: %s.', $noun, implode(', ', $unknown)),
                [str_replace(' ', '_', $noun) => [sprintf('These fields cannot be written here: %s.', implode(', ', $unknown))]]
            );
        }
    }

    private function lockMenu(int $id): object
    {
        $row = $this->connection()->table('menus')->where('id', $id)->lockForUpdate()->first();

        if ($row === null) {
            throw InvalidSectionContentException::withErrors('That menu no longer exists.', ['menu' => ['It may have been deleted in another tab.']]);
        }

        return $row;
    }

    private function itemGone(): InvalidSectionContentException
    {
        return InvalidSectionContentException::withErrors('That menu link no longer exists.', [
            'item' => ['It may have been removed in another tab.'],
        ]);
    }

    private function findItem(int $id): MenuItem
    {
        /** @var MenuItem */
        return MenuItem::query()->withoutGlobalScopes()->findOrFail($id);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function scalar(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function comparable(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
