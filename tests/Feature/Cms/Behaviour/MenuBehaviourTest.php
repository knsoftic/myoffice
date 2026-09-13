<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Enums\Cms\MenuItemLinkType;
use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\MenuVisibility;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\MenuItem;
use App\Services\Cms\Exceptions\ContentActionNotAllowedException;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-03 §11.4 — menus (FT-19, FT-20, FT-28, FT-29, FT-44).
 *
 * A menu is two levels deep and the database says so (INV-6, `chk_mi_depth`); a cycle can never be
 * written; a link to a page that is not public is omitted rather than rendered dead (§12.1 R-3); a URL is
 * computed from its target, never stored, so renaming a page fixes every link; an unsafe scheme is
 * refused; and visibility is applied per request **after** the page cache, so a guest-only link is never
 * served to a signed-in user or the other way round.
 */
final class MenuBehaviourTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    /** FT-19 */
    public function test_menu_cannot_exceed_two_levels(): void
    {
        $menu = $this->menuAt(MenuLocation::FooterSecondary);
        $admin = $this->createSuperAdmin();

        $top = $this->menus()->storeItem($menu, ['label' => 'FT19 Top', 'link_type' => MenuItemLinkType::None->value]);
        $child = $this->menus()->storeItem($menu, [
            'label' => 'FT19 Child',
            'link_type' => MenuItemLinkType::Url->value,
            'url' => '/ft19-child',
            'parent_id' => (int) $top->getKey(),
        ]);

        $this->assertSame(0, (int) $top->depth);
        $this->assertSame(1, (int) $child->depth, 'A child is depth 1, derived by the service.');

        $count = MenuItem::query()->where('menu_id', $menu->getKey())->count();

        $this->assertThrows(
            fn () => $this->menus()->storeItem($menu, [
                'label' => 'FT19 Grandchild',
                'link_type' => MenuItemLinkType::Url->value,
                'url' => '/ft19-grandchild',
                'parent_id' => (int) $child->getKey(),
            ]),
            ContentActionNotAllowedException::class,
        );

        $this->actingAs($admin)
            ->postJson(route('admin.website.menus.items.store', $menu), [
                'label' => 'FT19 Grandchild',
                'link_type' => MenuItemLinkType::Url->value,
                'url' => '/ft19-grandchild',
                'parent_id' => (int) $child->getKey(),
            ])
            ->assertStatus(422);

        $this->assertSame($count, MenuItem::query()->where('menu_id', $menu->getKey())->count(), 'No grandchild is written.');

        // The CHECK constraint refuses a raw depth-2 row even when every service is bypassed.
        $this->assertThrows(
            fn () => DB::table('menu_items')->insert([
                'menu_id' => $menu->getKey(),
                'parent_id' => $child->getKey(),
                'label' => 'FT19 Raw Grandchild',
                'link_type' => MenuItemLinkType::Url->value,
                'url' => '/ft19-raw',
                'visibility' => MenuVisibility::All->value,
                'open_new_tab' => false,
                'rel_nofollow' => false,
                'is_enabled' => true,
                'sort_order' => 10,
                'depth' => 2,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]),
            QueryException::class,
        );

        // Re-parenting an item that has children would create a third level.
        $other = $this->menus()->storeItem($menu, ['label' => 'FT19 Other', 'link_type' => MenuItemLinkType::Url->value, 'url' => '/ft19-other']);

        $this->assertThrows(
            fn () => $this->menus()->updateItem($top, ['parent_id' => (int) $other->getKey()]),
            ContentActionNotAllowedException::class,
        );

        $this->actingAs($admin)
            ->putJson(route('admin.website.menu-items.update', $top), ['parent_id' => (int) $other->getKey()])
            ->assertStatus(422);

        $row = DB::table('menu_items')->where('id', $top->getKey())->first();
        $this->assertNull($row->parent_id, 'The parent stays top level.');
        $this->assertSame(0, (int) $row->depth);
    }

    /** FT-20 */
    public function test_menu_cycle_is_rejected(): void
    {
        $menu = $this->menuAt(MenuLocation::FooterSecondary);
        $admin = $this->createSuperAdmin();

        $parent = $this->menus()->storeItem($menu, ['label' => 'FT20 Parent', 'link_type' => MenuItemLinkType::Url->value, 'url' => '/ft20-parent']);
        $child = $this->menus()->storeItem($menu, [
            'label' => 'FT20 Child',
            'link_type' => MenuItemLinkType::Url->value,
            'url' => '/ft20-child',
            'parent_id' => (int) $parent->getKey(),
        ]);

        $before = $this->treeRows((int) $menu->getKey());

        // Its own parent.
        $this->assertThrows(
            fn () => $this->menus()->updateItem($parent, ['parent_id' => (int) $parent->getKey()]),
            ContentActionNotAllowedException::class,
        );

        // The parent of its own parent.
        $this->assertThrows(
            fn () => $this->menus()->updateItem($parent, ['parent_id' => (int) $child->getKey()]),
            ContentActionNotAllowedException::class,
        );

        $this->actingAs($admin)
            ->putJson(route('admin.website.menu-items.update', $parent), ['parent_id' => (int) $parent->getKey()])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->putJson(route('admin.website.menu-items.update', $parent), ['parent_id' => (int) $child->getKey()])
            ->assertStatus(422);

        $this->assertSame($before, $this->treeRows((int) $menu->getKey()), 'A refused cycle changes nothing.');
    }

    /** FT-28 */
    public function test_menu_item_pointing_at_unpublished_target_is_hidden(): void
    {
        $admin = $this->createSuperAdmin();
        $menu = $this->menuAt(MenuLocation::Header);
        $header = $this->seededSection('header', SectionPlacement::GlobalHeader);

        $targets = [
            'Draft' => $this->makePage('ft28-draft', 'FT28 Draft Target', '<p>FT28 draft target body</p>'),
            'Scheduled' => $this->makePage('ft28-scheduled', 'FT28 Scheduled Target', '<p>FT28 scheduled target body</p>'),
            'Trashed' => $this->makePage('ft28-trashed', 'FT28 Trashed Target', '<p>FT28 trashed target body</p>'),
            'Deleted' => $this->makePage('ft28-deleted', 'FT28 Deleted Target', '<p>FT28 deleted target body</p>'),
        ];

        $items = [];

        foreach ($targets as $name => $page) {
            $items[$name] = $this->menus()->storeItem($menu, [
                'label' => 'FT28 '.$name.' Link',
                'link_type' => MenuItemLinkType::Page->value,
                'page_id' => (int) $page->getKey(),
            ]);
        }

        $this->publisher()->publish($header);

        $html = (string) $this->get('/')->assertOk()->getContent();

        foreach (array_keys($targets) as $name) {
            $this->assertStringContainsString('FT28 '.$name.' Link', $html, 'Every link renders while its page is published.');
        }

        // draft
        $this->publisher()->unpublish($targets['Draft'], 'FT-28 back to draft');

        // scheduled
        $this->publisher()->unpublish($targets['Scheduled'], 'FT-28 rescheduling');
        $this->publisher()->schedule($targets['Scheduled']->fresh(), Carbon::now()->addDay());

        // trashed — the response names the menu items it disabled
        $response = $this->actingAs($admin)
            ->deleteJson(route('admin.website.pages.destroy', $targets['Trashed']))
            ->assertOk();

        $this->assertContains('FT28 Trashed Link', (array) $response->json('disabled_menu_items'), 'Deleting a page names the menu items it disabled.');

        $trashedItem = DB::table('menu_items')->where('id', $items['Trashed']->getKey())->first();
        $this->assertSame(0, (int) $trashedItem->is_enabled, 'A menu item pointing at a trashed page is disabled…');
        $this->assertNull($trashedItem->deleted_at, '…not deleted.');

        // deleted outright (the FK nulls the menu item's page_id)
        DB::table('pages')->where('id', $targets['Deleted']->getKey())->delete();
        $this->bumpPublicCache('FT-28 raw delete');

        $this->becomeGuest();

        $html = (string) $this->get('/')->assertOk()->getContent();

        foreach ($targets as $name => $page) {
            $this->assertStringNotContainsString('FT28 '.$name.' Link', $html, sprintf('The %s target\'s link must be omitted, not rendered dead.', strtolower($name)));
            $this->get('/'.$page->slug)->assertNotFound();
        }

        $reasons = collect($this->actingAs($admin)
            ->getJson(route('admin.website.menus.link-check', $menu))
            ->assertOk()
            ->json('items'))
            ->pluck('reason', 'label')
            ->all();

        $this->assertSame('the page is not published', $reasons['FT28 Draft Link'] ?? null);
        $this->assertSame('the page is not published', $reasons['FT28 Scheduled Link'] ?? null);
        $this->assertSame('the page is in the trash', $reasons['FT28 Trashed Link'] ?? null);
        $this->assertSame('the page no longer exists', $reasons['FT28 Deleted Link'] ?? null);
    }

    /** FT-29 */
    public function test_menu_urls_are_resolved_not_stored(): void
    {
        $admin = $this->createSuperAdmin();
        $menu = $this->menuAt(MenuLocation::Header);
        $header = $this->seededSection('header', SectionPlacement::GlobalHeader);

        $page = $this->makePage('ft29-original', 'FT29 Page', '<p>FT29 page body</p>');
        $item = $this->menus()->storeItem($menu, [
            'label' => 'FT29 Page Link',
            'link_type' => MenuItemLinkType::Page->value,
            'page_id' => (int) $page->getKey(),
        ]);

        $this->assertStringEndsWith('/ft29-original', (string) $this->menus()->resolveUrl($item->fresh()));

        $menuRows = DB::table('menu_items')->where('menu_id', $menu->getKey())->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();

        $this->pages()->saveDraft($page, ['slug' => 'ft29-renamed']);

        $this->assertStringEndsWith('/ft29-renamed', (string) $this->menus()->resolveUrl($item->fresh()), 'Renaming the slug changes the link.');
        $this->assertSame(
            $menuRows,
            DB::table('menu_items')->where('menu_id', $menu->getKey())->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'Renaming a page writes nothing to any menu.',
        );

        $this->publisher()->publish($header);
        $this->get('/')->assertOk()->assertSee('/ft29-renamed', false)->assertDontSee('/ft29-original', false);

        // A route that disappears hides the item instead of throwing.
        $routeItem = $this->menus()->storeItem($menu, [
            'label' => 'FT29 Route Link',
            'link_type' => MenuItemLinkType::Route->value,
            'route_name' => 'site.home',
        ]);

        DB::table('menu_items')->where('id', $routeItem->getKey())->update(['route_name' => 'site.ft29-vanished-route']);

        $this->assertNull($this->menus()->resolveUrl($routeItem->fresh()), 'A vanished route resolves to null, never RouteNotFoundException.');

        $header = $this->publisher()->publish($header);
        $labels = array_column($this->snapshotOf($header)['menus']['menu_ref']['items'] ?? [], 'label');

        $this->assertContains('FT29 Page Link', $labels);
        $this->assertNotContains('FT29 Route Link', $labels, 'A link whose route vanished is omitted from the tree.');

        $this->becomeGuest();
        $this->get('/')->assertOk()->assertDontSee('FT29 Route Link');

        // An unsafe scheme is refused by validation.
        $this->assertThrows(
            fn () => $this->menus()->storeItem($menu, [
                'label' => 'FT29 Unsafe',
                'link_type' => MenuItemLinkType::Url->value,
                'url' => 'javascript:alert(1)',
            ]),
            InvalidSectionContentException::class,
        );

        $this->actingAs($admin)
            ->postJson(route('admin.website.menus.items.store', $menu), [
                'label' => 'FT29 Unsafe',
                'link_type' => MenuItemLinkType::Url->value,
                'url' => 'javascript:alert(1)',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');

        $this->assertFalse(DB::table('menu_items')->where('label', 'FT29 Unsafe')->exists());
    }

    /** FT-44 */
    public function test_menu_visibility_is_applied_per_request_after_the_cache(): void
    {
        $menu = $this->menuAt(MenuLocation::Header);

        $this->menus()->storeItem($menu, [
            'label' => 'FT44 Guests Only',
            'link_type' => MenuItemLinkType::Url->value,
            'url' => '/ft44-guests',
            'visibility' => MenuVisibility::Guest->value,
        ]);

        $this->menus()->storeItem($menu, [
            'label' => 'FT44 Members Only',
            'link_type' => MenuItemLinkType::Url->value,
            'url' => '/ft44-members',
            'visibility' => MenuVisibility::Auth->value,
        ]);

        $this->publisher()->publish($this->seededSection('header', SectionPlacement::GlobalHeader));

        $visitor = $this->get('/')
            ->assertOk()
            ->assertSee('FT44 Guests Only')
            ->assertDontSee('FT44 Members Only');

        $this->assertCount(1, $this->pageCacheKeys(), 'The visitor\'s render is stored.');

        $this->actingAs($this->createUserWithRole('Student'))
            ->get('/')
            ->assertOk()
            ->assertSee('FT44 Members Only')
            ->assertDontSee('FT44 Guests Only');

        $this->assertCount(1, $this->pageCacheKeys(), 'A signed-in render is neither served from nor written to the page cache.');

        $this->becomeGuest();

        $again = $this->get('/')
            ->assertOk()
            ->assertSee('FT44 Guests Only')
            ->assertDontSee('FT44 Members Only');

        $this->assertSame($visitor->headers->get('ETag'), $again->headers->get('ETag'), 'The second visitor is served the same cached body.');
    }

    /**
     * @return list<array{id: int, parent_id: int|null, depth: int, sort_order: int}>
     */
    private function treeRows(int $menuId): array
    {
        return DB::table('menu_items')
            ->where('menu_id', $menuId)
            ->orderBy('id')
            ->get(['id', 'parent_id', 'depth', 'sort_order'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'depth' => (int) $row->depth,
                'sort_order' => (int) $row->sort_order,
            ])
            ->all();
    }
}
