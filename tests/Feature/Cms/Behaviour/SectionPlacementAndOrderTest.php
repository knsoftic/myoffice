<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\SectionPlacement;
use App\Models\Activity;
use App\Models\Cms\WebsiteSection;
use App\Models\Cms\WebsiteSectionItem;
use App\Services\Cms\Exceptions\ContentActionNotAllowedException;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use App\Services\Cms\Exceptions\UnknownSectionTypeException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-03 §11.1 — registry, placement and ordering (FT-01, FT-02, FT-03, FT-04, FT-05, FT-15, FT-16,
 * FT-18).
 *
 * The section registry is code and the placed sections are data: these tests prove the boundary holds
 * in both directions. An undeclared or misplaced type never reaches the table (INV-2), a unique type is
 * unique because the database says so (`uq_ws_instance`), an orphaned row never breaks the public page,
 * reordering is exact and contiguous (INV-5), a required type is disable-only (INV-7), and a repeater's
 * bounds hold in the service and not only in the form.
 *
 * Every refusal is asserted twice where the contract names a status: once at the service (the rule
 * itself, which a Super Admin cannot bypass) and once over HTTP (what the editor receives).
 */
final class SectionPlacementAndOrderTest extends TestCase
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

    protected function tearDown(): void
    {
        $this->forgetTestSectionTypes();

        parent::tearDown();
    }

    /** FT-01 */
    public function test_unknown_section_type_cannot_be_placed(): void
    {
        $sections = DB::table('website_sections')->count();
        $revisions = DB::table('cms_revisions')->count();

        $this->assertThrows(
            fn () => $this->sections()->place('nope', SectionPlacement::Home),
            UnknownSectionTypeException::class,
        );

        $this->assertSame($sections, DB::table('website_sections')->count(), 'An unknown type wrote a section row.');
        $this->assertSame($revisions, DB::table('cms_revisions')->count(), 'An unknown type wrote a revision.');

        $this->actingAs($this->createSuperAdmin())
            ->postJson(route('admin.website.sections.store', ['placement' => SectionPlacement::Home->value]), ['section_key' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('section_key');

        $this->assertSame($sections, DB::table('website_sections')->count());
    }

    /** FT-02 */
    public function test_unique_section_type_cannot_be_placed_twice(): void
    {
        $hero = $this->seededSection('hero');
        $instanceKey = (string) $this->sectionRow($hero)->instance_key;

        $this->assertSame('home|0|hero', $instanceKey, 'A unique type carries the instance key the unique index constrains.');

        $this->assertThrows(
            fn () => $this->sections()->place('hero', SectionPlacement::Home),
            ContentActionNotAllowedException::class,
        );

        // The INSERT decides, not a SELECT: a raw row with the same instance key is refused by the index.
        $this->assertThrows(
            fn () => DB::table('website_sections')->insert([
                'section_key' => 'hero',
                'placement' => SectionPlacement::Home->value,
                'page_id' => null,
                'instance_key' => $instanceKey,
                'is_enabled' => true,
                'status' => ContentStatus::Draft->value,
                'sort_order' => 990,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]),
            UniqueConstraintViolationException::class,
        );

        $response = $this->actingAs($this->createSuperAdmin())
            ->postJson(route('admin.website.sections.store', ['placement' => SectionPlacement::Home->value]), ['section_key' => 'hero'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('section_key');

        $this->assertStringContainsString('Hero', (string) $response->json('errors.section_key.0'), 'The refusal must name the section that already exists.');

        $this->assertSame(
            1,
            WebsiteSection::withTrashed()->where('section_key', 'hero')->where('placement', SectionPlacement::Home->value)->count(),
            'Exactly one hero may exist on the home page.',
        );
    }

    /** FT-03 */
    public function test_repeatable_section_type_can_be_placed_many_times(): void
    {
        $placed = [];

        foreach (range(1, 3) as $ignored) {
            $placed[] = (int) $this->sections()->place('cta', SectionPlacement::Home)->getKey();
        }

        $rows = DB::table('website_sections')->whereIn('id', $placed)->get(['id', 'section_key', 'instance_key', 'status']);

        $this->assertCount(3, $rows, 'Three CTA sections must coexist on one page.');

        foreach ($rows as $row) {
            $this->assertSame('cta', $row->section_key);
            $this->assertNull($row->instance_key, 'A repeatable type carries no instance key, so the unique index never constrains it.');
            $this->assertSame(ContentStatus::Draft->value, $row->status);
        }
    }

    /** FT-04 */
    public function test_orphaned_section_type_does_not_break_the_public_page(): void
    {
        $now = Carbon::now();
        $snapshot = [
            'id' => 0,
            'section_key' => 'retired_widget',
            'placement' => SectionPlacement::Home->value,
            'page_id' => null,
            'name' => 'Retired widget',
            'anchor' => null,
            'content_hash' => str_repeat('a', 40),
            'fields' => ['heading' => 'FT04 Orphan Marker'],
            'items' => [],
            'media' => [],
            'cta' => null,
            'menus' => [],
            'faqs' => null,
            'provider' => null,
            'built_at' => $now->toIso8601String(),
        ];

        DB::table('website_sections')->insert([
            'section_key' => 'retired_widget',
            'placement' => SectionPlacement::Home->value,
            'page_id' => null,
            'instance_key' => null,
            'name' => 'Retired widget',
            'content' => json_encode(['heading' => 'FT04 Orphan Marker']),
            'published_content' => json_encode($snapshot),
            'content_hash' => str_repeat('a', 40),
            'published_hash' => str_repeat('a', 40),
            'is_enabled' => true,
            'status' => ContentStatus::Published->value,
            'sort_order' => 1,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Log::spy();

        $this->get('/')
            ->assertOk()
            ->assertDontSee('FT04 Orphan Marker');

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (mixed $message, mixed $context = []): bool => is_array($context) && ($context['section_key'] ?? null) === 'retired_widget')
            ->once();

        $this->actingAs($this->createSuperAdmin())
            ->get(route('admin.website.sections.index', ['placement' => SectionPlacement::Home->value]))
            ->assertOk()
            ->assertSee('Orphaned type');
    }

    /** FT-05 */
    public function test_section_type_not_allowed_in_placement_is_rejected(): void
    {
        $before = DB::table('website_sections')->count();

        $this->assertThrows(
            fn () => $this->sections()->place('header', SectionPlacement::Home),
            UnknownSectionTypeException::class,
        );

        $this->actingAs($this->createSuperAdmin())
            ->postJson(route('admin.website.sections.store', ['placement' => SectionPlacement::Home->value]), ['section_key' => 'header'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('section_key');

        $this->assertSame($before, DB::table('website_sections')->count());
    }

    /** FT-15 */
    public function test_reorder_writes_contiguous_order_and_rejects_a_stale_set(): void
    {
        // The seeded hero, about, faq and cta, topped up with placed sections to (at least) six.
        for ($n = count($this->homeOrder()); $n < 6; $n++) {
            $this->placeRichContent('FT15 Section '.$n, publish: false);
        }

        $current = $this->homeOrder();
        $this->assertGreaterThanOrEqual(6, count($current));

        $reversed = array_reverse(array_keys($current));
        $contiguous = range(10, 10 * count($current), 10);
        $reorders = Activity::query()->where('module', 'website_sections')->where('event', 'reordered')->count();

        $this->sections()->reorder(SectionPlacement::Home, null, $reversed);

        $after = $this->homeOrder();

        $this->assertSame($reversed, array_keys($after), 'The new order is exactly the posted id list.');
        $this->assertSame($contiguous, array_values($after), 'sort_order is contiguous 10, 20, 30 ... with no gaps and no duplicates.');

        $this->assertSame($reorders + 1, Activity::query()->where('module', 'website_sections')->where('event', 'reordered')->count(), 'One activity row per reorder.');

        $activity = Activity::query()->where('module', 'website_sections')->where('event', 'reordered')->latest('id')->firstOrFail();
        $properties = $activity->properties->toArray();

        $this->assertSame(array_keys($current), $properties['old']['order'] ?? null, 'The audit row keeps the old order.');
        $this->assertSame($reversed, $properties['attributes']['order'] ?? null, 'The audit row keeps the new order.');

        // A stale tab: one section missing.
        $missing = array_slice($reversed, 1);

        $this->assertThrows(
            fn () => $this->sections()->reorder(SectionPlacement::Home, null, $missing),
            InvalidSectionContentException::class,
        );
        $this->assertSame($after, $this->homeOrder(), 'A list missing a section changes nothing.');

        // A foreign id: the header section belongs to another placement.
        $foreign = $reversed;
        $foreign[0] = (int) $this->seededSection('header', SectionPlacement::GlobalHeader)->getKey();

        $this->assertThrows(
            fn () => $this->sections()->reorder(SectionPlacement::Home, null, $foreign),
            InvalidSectionContentException::class,
        );
        $this->assertSame($after, $this->homeOrder(), 'A list with a foreign id changes nothing.');

        $this->actingAs($this->createSuperAdmin())
            ->postJson(route('admin.website.sections.reorder'), ['placement' => SectionPlacement::Home->value, 'order' => $missing])
            ->assertStatus(422);

        $this->assertSame($after, $this->homeOrder());
        $this->assertSame($reorders + 1, Activity::query()->where('module', 'website_sections')->where('event', 'reordered')->count(), 'A refused reorder is not audited as a reorder.');
    }

    /** FT-16 */
    public function test_required_section_cannot_be_deleted_only_disabled(): void
    {
        $hero = $this->publishHeroHeading('FT16 Hero Marker');

        $this->get('/')->assertOk()->assertSee('FT16 Hero Marker');

        $admin = $this->createSuperAdmin();

        // Super Admin bypasses policies; the service still refuses (INV-7, M-7).
        $this->actingAs($admin)
            ->deleteJson(route('admin.website.sections.destroy', $hero), ['reason' => 'Trying to remove the hero'])
            ->assertForbidden();

        $this->assertThrows(
            fn () => $this->sections()->remove($hero, 'Trying to remove the hero'),
            ContentActionNotAllowedException::class,
        );

        // An editor holding the delete permission is stopped by the policy itself.
        $editor = $this->createUserWithPermissions([
            'website_sections.view_any',
            'website_sections.view',
            'website_sections.delete',
        ]);

        $this->actingAs($editor)
            ->deleteJson(route('admin.website.sections.destroy', $hero), ['reason' => 'Trying to remove the hero'])
            ->assertForbidden();

        $this->assertNull($this->sectionRow($hero)->deleted_at, 'The hero row survives every delete attempt.');

        $this->actingAs($admin)
            ->postJson(route('admin.website.sections.toggle', $hero), ['enabled' => false])
            ->assertOk();

        $row = $this->sectionRow($hero);
        $this->assertSame(0, (int) $row->is_enabled, 'Disabling is the one off switch a required section has.');
        $this->assertSame(ContentStatus::Published->value, $row->status, 'Disabling never changes the publish status.');

        $this->becomeGuest();

        $this->get('/')
            ->assertOk()
            ->assertDontSee('FT16 Hero Marker');
    }

    /** FT-18 */
    public function test_repeater_min_and_max_are_enforced(): void
    {
        $hero = $this->seededSection('hero');
        $count = static fn (): int => WebsiteSectionItem::query()
            ->where('website_section_id', $hero->getKey())
            ->where('group', 'statistic')
            ->count();

        while ($count() < 8) {
            $this->sections()->upsertItem($hero, 'statistic', [
                'label' => 'FT18 Statistic '.$count(),
                'value_mode' => 'manual',
                'manual_value' => '10',
            ]);
        }

        $this->assertSame(8, $count(), 'The hero statistics repeater holds at most eight.');

        $this->assertThrows(
            fn () => $this->sections()->upsertItem($hero, 'statistic', ['label' => 'FT18 Ninth', 'value_mode' => 'manual', 'manual_value' => '9']),
            InvalidSectionContentException::class,
        );

        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->postJson(route('admin.website.sections.items.store', $hero), [
                'group' => 'statistic',
                'item' => ['label' => 'FT18 Ninth', 'value_mode' => 'manual', 'manual_value' => '9'],
            ])
            ->assertStatus(422);

        $this->assertSame(8, $count(), 'A ninth statistic is never written.');

        // A repeater with a minimum of one: deleting its last item is refused.
        $this->registerTestSectionType('ft18_min_one', [
            'label' => 'FT-18 minimum one',
            'group' => 'content',
            'placements' => [SectionPlacement::Home->value => 900],
            'unique' => false,
            'required' => false,
            'fields' => [
                'heading' => ['label' => 'Heading', 'type' => 'text', 'default' => null],
            ],
            'repeaters' => [
                'point' => [
                    'label' => 'Points',
                    'min' => 1,
                    'max' => 3,
                    'item_label_field' => 'title',
                    'fields' => [
                        'title' => ['label' => 'Title', 'type' => 'text', 'required' => true, 'default' => null],
                    ],
                ],
            ],
            'media' => [],
        ]);

        $section = $this->sections()->place('ft18_min_one', SectionPlacement::Home);
        $item = $this->sections()->upsertItem($section, 'point', ['title' => 'The only point']);

        $this->assertThrows(
            fn () => $this->sections()->deleteItem($item),
            InvalidSectionContentException::class,
        );

        $this->actingAs($admin)
            ->deleteJson(route('admin.website.section-items.destroy', $item))
            ->assertStatus(422);

        $this->assertNull(
            DB::table('website_section_items')->where('id', $item->getKey())->value('deleted_at'),
            'The last item of a repeater with min = 1 survives.',
        );
    }

    /**
     * The live home sections, id => sort_order, in order.
     *
     * @return array<int, int>
     */
    private function homeOrder(): array
    {
        return DB::table('website_sections')
            ->where('placement', SectionPlacement::Home->value)
            ->whereNull('page_id')
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('sort_order', 'id')
            ->mapWithKeys(static fn (mixed $sort, int|string $id): array => [(int) $id => (int) $sort])
            ->all();
    }
}
