<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Enums\Cms\MenuItemLinkType;
use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\SectionPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Review round 2 regressions — a permission split is only as strong as its weakest route ([D-W3-10], INV-1,
 * §4.2, §7.1, §7.2).
 *
 *   · Switching a menu link on or off is `menus.change_status` (the toggle route's gate). The item editor's
 *     `PUT` under `menus.edit` carries `is_enabled` too, so it needs the same right for an actual change —
 *     and only for a change, because the editor posts the current state back with every save.
 *   · A published section's `#anchor` is read by the public page straight from the row and is what menu
 *     links point at: changing it is live on save, so it needs `website_sections.change_status`, as a live
 *     page's slug does. On a draft section it is part of the draft.
 */
final class PublishRightSplitTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    private const MENU_EDITOR = ['menus.view_any', 'menus.view', 'menus.edit'];

    private const SECTION_EDITOR = ['website_sections.view_any', 'website_sections.view', 'website_sections.edit', 'website_sections.create'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    public function test_menus_edit_alone_cannot_switch_a_link_on_or_off_through_the_item_editor(): void
    {
        $item = $this->menus()->storeItem($this->menuAt(MenuLocation::Header), [
            'label' => 'FT Split Link',
            'link_type' => MenuItemLinkType::Url->value,
            'url' => '/ft-split-link',
        ]);

        $this->assertTrue((bool) $item->is_enabled);

        $editor = $this->createUserWithPermissions(self::MENU_EDITOR);
        $update = route('admin.website.menu-items.update', $item);

        // The dedicated route refuses, as before.
        $this->actingAs($editor)
            ->postJson(route('admin.website.menu-items.toggle', $item), ['enabled' => false])
            ->assertForbidden();

        // The editor's PUT with a changed state refuses too, and writes nothing — not even the label.
        $this->actingAs($editor)
            ->putJson($update, ['label' => 'FT Split Link Renamed', 'link_type' => 'url', 'url' => '/ft-split-link', 'is_enabled' => false])
            ->assertForbidden();

        $row = DB::table('menu_items')->where('id', $item->getKey())->first();
        $this->assertSame(1, (int) $row->is_enabled, 'menus.edit alone never takes a link off the site.');
        $this->assertSame('FT Split Link', $row->label);

        // Saving the rest with the current state posted back is an ordinary edit.
        $this->actingAs($editor)
            ->putJson($update, ['label' => 'FT Split Link Renamed', 'link_type' => 'url', 'url' => '/ft-split-link', 'is_enabled' => true])
            ->assertSuccessful();

        $this->assertSame('FT Split Link Renamed', DB::table('menu_items')->where('id', $item->getKey())->value('label'));

        // The editor screen does not post the switch at all for someone who may not use it.
        $this->actingAs($editor)
            ->get(route('admin.website.menus.show', (int) $item->menu_id))
            ->assertOk()
            ->assertSee('x-bind:disabled="!! item.id"', false);

        // A publisher may do both.
        $publisher = $this->createUserWithPermissions([...self::MENU_EDITOR, 'menus.change_status']);

        $this->actingAs($publisher)
            ->putJson($update, ['link_type' => 'url', 'url' => '/ft-split-link', 'is_enabled' => false])
            ->assertSuccessful();

        $this->assertSame(0, (int) DB::table('menu_items')->where('id', $item->getKey())->value('is_enabled'));
    }

    public function test_website_sections_edit_alone_cannot_move_a_published_sections_anchor(): void
    {
        $about = $this->seededSection('about');

        $this->assertSame('about', $about->anchor);
        $this->assertNotNull($this->snapshotOf($about), 'The seeded about section is published.');

        $editor = $this->createUserWithPermissions(self::SECTION_EDITOR);
        $update = route('admin.website.sections.update', $about);
        $version = $this->cacheVersion()->refresh();

        $this->actingAs($editor)
            ->putJson($update, ['anchor' => 'ft-moved-about'])
            ->assertForbidden();

        $this->assertSame('about', $this->sectionRow($about)->anchor, 'The live anchor did not move.');
        $this->assertSame($version, $this->cacheVersion()->refresh(), 'Nothing went live, so nothing was invalidated.');

        // The same value posted back (the editor form always sends it) with a new label is an ordinary edit.
        $this->actingAs($editor)
            ->putJson($update, ['anchor' => '#about', 'name' => 'FT About Label'])
            ->assertSuccessful();

        $this->assertSame('FT About Label', $this->sectionRow($about)->name);
        $this->assertSame('about', $this->sectionRow($about)->anchor);

        // The editor form shows the field read-only, with the reason.
        $this->actingAs($editor)
            ->get(route('admin.website.sections.edit', $about))
            ->assertOk()
            ->assertSee('changing it needs the publish permission', false);

        // On a draft section the anchor is part of the draft.
        $draft = $this->sections()->place('rich_content', SectionPlacement::Home);

        $this->actingAs($editor)
            ->putJson(route('admin.website.sections.update', $draft), ['anchor' => 'ft-draft-anchor'])
            ->assertSuccessful();

        $this->assertSame('ft-draft-anchor', $this->sectionRow($draft)->anchor);

        // A publisher may move a live anchor.
        $publisher = $this->createUserWithPermissions([...self::SECTION_EDITOR, 'website_sections.change_status']);

        $this->actingAs($publisher)
            ->putJson($update, ['anchor' => 'ft-moved-about'])
            ->assertSuccessful();

        $this->assertSame('ft-moved-about', $this->sectionRow($about)->anchor);
    }
}
