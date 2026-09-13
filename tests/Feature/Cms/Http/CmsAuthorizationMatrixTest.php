<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Http;

use App\Models\Cms\CtaBlock;
use App\Models\Cms\WebsiteSection;
use App\Models\User;
use App\Support\PermissionRegistry;
use App\Support\SettingsRepository;
use Database\Seeders\WebsiteCmsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Cms\Http\Concerns\CmsHttpFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-03 §11.9 FT-48 `test_authorization_matrix`, with §9's role table, §7.1-§7.5's routes and
 * CLAUDE.md rule 7 (authorization is decided on the backend).
 *
 * For **every** admin CMS route:
 *   · without its permission — while holding every other CMS permission — the answer is 403 and nothing
 *     is written (every CMS row and the audit trail are fingerprinted before and after);
 *   · a permission-less admin user, a guest and every panel role outside §9's grant are refused too;
 *   · holding exactly that one permission, the route answers 200 with a valid payload.
 *
 * Also the named cases of the FT-48 row (edit without change_status; seo.view without seo.edit), the
 * record rules the policies add on top of the permission (a required section, a system page, a CTA block
 * in use — FT-16, FT-17), and a revision addressed through the wrong target.
 */
final class CmsAuthorizationMatrixTest extends TestCase
{
    use CmsHttpFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    /** The four portal roles: they never pass `panel:admin`. */
    private const PORTAL_ROLES = ['Teacher', 'Student', 'Client', 'Collaborator'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
        Storage::fake('public');
    }

    /*
    |--------------------------------------------------------------------------
    | FT-48 — every route, both directions
    |--------------------------------------------------------------------------
    */

    public function test_authorization_matrix(): void
    {
        $table = $this->cmsRouteTable();

        $registered = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): ?string => $route->getName())
            ->filter(static fn (?string $name): bool => is_string($name) && str_starts_with($name, 'admin.website.'))
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            $registered,
            collect(array_keys($table))->sort()->values()->all(),
            'The matrix must cover exactly the admin CMS routes that are registered (phase-03 §7.1-§7.5).',
        );
        $this->assertCount(78, $table, 'phase-03 §7.1-§7.5 declares 78 admin CMS routes.');

        $all = $this->cmsPermissions();
        $nobody = $this->createUserWithPermissions([]);

        // Refusals first. Every request is prepared from seeded rows and every actor exists before the
        // fingerprint (creating a user writes the audit trail), so the whole sweep is fingerprinted once.
        $prepared = [];
        $withoutOne = [];

        foreach ($table as $name => $entry) {
            $permission = $entry['permission'];

            $this->assertContains($permission, $all, sprintf('%s names %s, which PermissionRegistry does not declare.', $name, $permission));

            $prepared[$name] = $this->prepareCmsRequest($name, $entry, false);
            $withoutOne[$permission] ??= $this->createUserWithPermissions(array_values(array_diff($all, [$permission])));
        }

        $before = $this->cmsFingerprint();

        foreach ($table as $name => $entry) {
            $permission = $entry['permission'];

            foreach (['holding every CMS permission except '.$permission => $withoutOne[$permission], 'with no permission at all' => $nobody] as $context => $user) {
                $response = $this->actingAs($user)->sendPreparedCms($prepared[$name], true);

                $this->assertSame(
                    403,
                    $response->getStatusCode(),
                    sprintf('%s %s must be refused %s; it answered %d.', $prepared[$name]['method'], $name, $context, $response->getStatusCode()),
                );
            }
        }

        $this->assertSame($before, $this->cmsFingerprint(), 'A refused CMS request wrote to a CMS table or the audit trail.');

        // With exactly the one permission its can: names, every route answers.
        $onlyOne = [];

        foreach ($table as $name => $entry) {
            $permission = $entry['permission'];
            $onlyOne[$permission] ??= $this->createUserWithPermissions([$permission]);

            $this->assertCmsRouteAnswers($onlyOne[$permission], $name, $entry, 'to a user holding only '.$permission);
        }
    }

    public function test_authorization_matrix_sends_a_guest_to_sign_in_from_every_cms_route(): void
    {
        $prepared = [];

        foreach ($this->cmsRouteTable() as $name => $entry) {
            $prepared[$name] = $this->prepareCmsRequest($name, $entry, false);
        }

        $before = $this->cmsFingerprint();

        foreach ($prepared as $name => $request) {
            $this->sendPreparedCms($request, false)->assertRedirect(route('login'));
            $this->sendPreparedCms($request, true)->assertUnauthorized();
        }

        $this->assertSame($before, $this->cmsFingerprint(), 'A guest request wrote to a CMS table or the audit trail.');
    }

    /**
     * §9: "a test per role". Every seeded role holds exactly the CMS grant §9 names — nothing for HR,
     * Accountant, Project Manager, Developer, Designer, Sales Executive, Receptionist, Support Agent,
     * Institute Manager, Course Coordinator and the four portals — and every route outside that grant is
     * a 403 that writes nothing. The SEO Expert and the Digital Marketer are also driven through every
     * route inside their grant, writes included.
     */
    #[DataProvider('seededRoles')]
    public function test_authorization_matrix_follows_each_seeded_role(string $role): void
    {
        $user = $this->createUserWithRole($role);
        $grant = $this->contractCmsGrant($role);

        $held = array_values(array_intersect($this->cmsPermissions(), $user->getAllPermissions()->pluck('name')->all()));
        $expected = $grant;
        sort($held);
        sort($expected);

        $this->assertSame($expected, $held, sprintf('The %s role must hold exactly the phase-03 §9 CMS grant.', $role));

        $table = $this->cmsRouteTable();
        $prepared = [];

        foreach ($table as $name => $entry) {
            if (! in_array($entry['permission'], $grant, true)) {
                $prepared[$name] = $this->prepareCmsRequest($name, $entry, false);
            }
        }

        $before = $this->cmsFingerprint();

        foreach ($prepared as $name => $request) {
            $response = $this->actingAs($user)->sendPreparedCms($request, true);

            $this->assertSame(
                403,
                $response->getStatusCode(),
                sprintf('The %s role must be refused %s %s (needs %s); it answered %d.', $role, $request['method'], $name, $table[$name]['permission'], $response->getStatusCode()),
            );
        }

        $this->assertSame($before, $this->cmsFingerprint(), sprintf('A request refused to the %s role wrote something.', $role));

        $drivesWrites = in_array($role, ['SEO Expert', 'Digital Marketer'], true);

        foreach ($table as $name => $entry) {
            if (! in_array($entry['permission'], $grant, true)) {
                continue;
            }

            if ($entry['kind'] === 'write' && ! $drivesWrites) {
                // Writes under a full grant are the single-permission case of test_authorization_matrix.
                $this->assertTrue($user->can($entry['permission']), sprintf('The %s role must be allowed %s.', $role, $entry['permission']));

                continue;
            }

            $this->assertCmsRouteAnswers($user, $name, $entry, 'to the '.$role.' role');
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function seededRoles(): array
    {
        $roles = [
            User::SUPER_ADMIN_ROLE, 'Admin', 'HR', 'Accountant', 'Project Manager', 'Developer', 'Designer',
            'SEO Expert', 'Digital Marketer', 'Sales Executive', 'Receptionist', 'Support Agent',
            'Institute Manager', 'Course Coordinator', 'Teacher', 'Student', 'Client', 'Collaborator',
        ];

        $cases = [];

        foreach ($roles as $role) {
            $cases[$role] = [$role];
        }

        return $cases;
    }

    /*
    |--------------------------------------------------------------------------
    | FT-48 — the named cases
    |--------------------------------------------------------------------------
    */

    public function test_authorization_matrix_edit_without_change_status_saves_drafts_but_cannot_publish(): void
    {
        $editor = $this->createUserWithPermissions(['website_sections.view_any', 'website_sections.view', 'website_sections.edit']);
        $hero = $this->cmsSection('hero');
        $live = $this->liveColumns('website_sections', (int) $hero->getKey());
        $draft = 'Drafted by an editor '.$this->uniqueToken();

        $this->actingAs($editor)
            ->get(route('admin.website.sections.edit', $hero))
            ->assertOk()
            ->assertSee('You can save drafts; publishing needs the publish permission.', false);

        $this->actingAs($editor)
            ->sendCms('PUT', route('admin.website.sections.update', $hero), ['content' => ['heading' => $draft]])
            ->assertOk();

        $row = DB::table('website_sections')->where('id', $hero->getKey())->first();
        $this->assertStringContainsString($draft, (string) $row->content, 'The editor saves the draft.');
        $this->assertSame(1, (int) $row->has_unpublished_changes);

        $this->actingAs($editor)
            ->sendCms('POST', route('admin.website.sections.publish', $hero))
            ->assertForbidden();

        $this->actingAs($editor)
            ->sendCms('PUT', route('admin.website.sections.update', $hero), ['content' => ['heading' => 'Sneaked live'], 'publish' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('publish');

        $this->assertSame($live, $this->liveColumns('website_sections', (int) $hero->getKey()), 'Nothing the editor did reached the published columns.');
        $this->assertStringNotContainsString('Sneaked live', (string) DB::table('website_sections')->where('id', $hero->getKey())->value('content'));

        // The same split on pages (§9: the SEO Expert edits page copy and cannot put it live).
        $pageEditor = $this->createUserWithPermissions(['pages.view_any', 'pages.view', 'pages.edit']);
        $page = $this->makeCmsPage(true);
        $livePage = $this->liveColumns('pages', (int) $page->getKey());

        $this->actingAs($pageEditor)
            ->sendCms('PUT', route('admin.website.pages.update', $page), ['content' => '<p>A draft edit by a page editor.</p>'])
            ->assertOk();

        $this->actingAs($pageEditor)->sendCms('POST', route('admin.website.pages.publish', $page))->assertForbidden();
        $this->actingAs($pageEditor)->sendCms('POST', route('admin.website.pages.unpublish', $page), ['reason' => 'Not allowed to do this'])->assertForbidden();
        $this->actingAs($pageEditor)->sendCms('POST', route('admin.website.pages.schedule', $page), ['publish_at' => now()->addDay()->format('Y-m-d H:i')])->assertForbidden();

        $this->assertSame($livePage, $this->liveColumns('pages', (int) $page->getKey()));
    }

    public function test_authorization_matrix_seo_view_without_edit_is_read_only(): void
    {
        $viewer = $this->createUserWithPermissions(['seo.view_any', 'seo.view']);

        $this->actingAs($viewer)->get(route('admin.website.seo.index'))->assertOk();

        $this->actingAs($viewer)
            ->get(route('admin.website.seo.edit', ['target' => 'route:site.home']))
            ->assertOk()
            ->assertSee('Read-only: changing SEO needs the SEO edit permission.', false)
            ->assertDontSee('Save SEO', false);

        $before = $this->cmsFingerprint();

        $this->actingAs($viewer)
            ->sendCms('PUT', route('admin.website.seo.update'), ['target' => 'route:site.home', 'seo' => ['title' => 'Hijacked title']])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->sendCms('POST', route('admin.website.seo.bulk-robots'), ['targets' => ['route:site.home'], 'robots' => 'noindex_nofollow'])
            ->assertForbidden();

        $this->actingAs($viewer)->sendCms('POST', route('admin.website.seo.sitemap.regenerate'))->assertForbidden();
        $this->actingAs($viewer)->get(route('admin.website.seo.export'))->assertForbidden();

        $this->assertSame($before, $this->cmsFingerprint(), 'seo.view alone must not be able to write SEO.');
    }

    /*
    |--------------------------------------------------------------------------
    | Record rules on top of the permission (FT-16, FT-17, §8.11)
    |--------------------------------------------------------------------------
    */

    /**
     * FT-16 through the admin routes: DELETE on the hero is a 403 from the policy even for a holder of
     * `website_sections.delete`, and a 403 from the service for a Super Admin (who bypasses policies,
     * M-7); disabling it works and takes it off the public page while `status` stays `published`.
     */
    public function test_required_section_cannot_be_deleted_only_disabled_through_the_admin_routes(): void
    {
        $marker = 'Required hero '.$this->uniqueToken();
        $hero = $this->cmsSections()->saveDraft($this->cmsSection('hero'), ['heading' => $marker]);
        $hero = $this->cmsPublisher()->publish($hero);

        $this->get('/')->assertOk()->assertSee($marker, false);

        $manager = $this->createUserWithPermissions(['website_sections.view_any', 'website_sections.view', 'website_sections.delete', 'website_sections.change_status']);

        foreach (['header' => 'global_header', 'hero' => 'home', 'footer' => 'global_footer'] as $key => $placement) {
            $section = WebsiteSection::query()->where('section_key', $key)->where('placement', $placement)->whereNull('page_id')->firstOrFail();

            $this->actingAs($manager)
                ->sendCms('DELETE', route('admin.website.sections.destroy', $section), ['reason' => 'Trying to remove a required section'])
                ->assertForbidden();

            $this->actingAs($manager)
                ->sendCms('DELETE', route('admin.website.sections.destroy', $section), ['reason' => 'Trying to remove a required section'], false)
                ->assertForbidden();

            $this->assertNotSoftDeleted('website_sections', ['id' => $section->getKey()]);
        }

        $this->actingAs($this->createSuperAdmin())
            ->sendCms('DELETE', route('admin.website.sections.destroy', $hero), ['reason' => 'Even a Super Admin cannot'])
            ->assertForbidden();

        $this->assertNotSoftDeleted('website_sections', ['id' => $hero->getKey()]);

        $this->actingAs($manager)
            ->sendCms('POST', route('admin.website.sections.toggle', $hero), ['enabled' => '0'])
            ->assertOk()
            ->assertJsonPath('is_enabled', false);

        $row = DB::table('website_sections')->where('id', $hero->getKey())->first();
        $this->assertSame(0, (int) $row->is_enabled);
        $this->assertSame('published', (string) $row->status, 'Disabling never changes the publish status.');

        $this->signOut();
        $this->get('/')->assertOk()->assertDontSee($marker, false);
    }

    /**
     * FT-17 through the admin routes: DELETE on `privacy-policy` is a 403 (policy for an Admin, service
     * for a Super Admin), the row survives, its content stays editable, and re-running the seeder does not
     * overwrite the edit.
     */
    public function test_system_pages_cannot_be_deleted_through_the_admin_routes(): void
    {
        $page = $this->seededSystemPage();
        $admin = $this->createUserWithRole('Admin');

        $this->actingAs($admin)->sendCms('DELETE', route('admin.website.pages.destroy', $page))->assertForbidden();
        $this->actingAs($admin)->sendCms('DELETE', route('admin.website.pages.destroy', $page), [], false)->assertForbidden();
        $this->actingAs($this->createSuperAdmin())->sendCms('DELETE', route('admin.website.pages.destroy', $page))->assertForbidden();

        $this->assertNotSoftDeleted('pages', ['id' => $page->getKey()]);

        $edited = 'Our own privacy wording '.$this->uniqueToken();

        $this->actingAs($admin)
            ->sendCms('PUT', route('admin.website.pages.update', $page), ['content' => '<p>'.$edited.'</p>'])
            ->assertOk();

        $this->assertStringContainsString($edited, (string) DB::table('pages')->where('id', $page->getKey())->value('content'));

        $pages = DB::table('pages')->count();

        $this->seed(WebsiteCmsSeeder::class);

        $this->assertSame($pages, DB::table('pages')->count(), 'Re-running the seeder creates no page.');
        $this->assertStringContainsString($edited, (string) DB::table('pages')->where('id', $page->getKey())->value('content'), 'Re-running the seeder must not overwrite an edited system page.');
    }

    /**
     * §6.13 / §8.11: a CTA block still referenced by a section cannot be deleted — a 403 carrying the
     * usage list — for a holder of `website_cta_blocks.delete` and for a Super Admin alike.
     */
    public function test_authorization_matrix_refuses_deleting_a_cta_block_that_is_in_use(): void
    {
        $block = $this->seededCtaBlock();
        $section = $this->cmsSection('cta');
        $this->assertSame((int) $block->getKey(), (int) $section->cta_block_id, 'The seeded cta section uses the primary block.');

        $manager = $this->createUserWithPermissions(['website_cta_blocks.view_any', 'website_cta_blocks.view', 'website_cta_blocks.delete']);

        foreach ([$manager, $this->createSuperAdmin()] as $user) {
            $this->actingAs($user)
                ->sendCms('DELETE', route('admin.website.cta-blocks.destroy', $block))
                ->assertForbidden()
                ->assertJsonPath('usage.0.id', (int) $section->getKey());
        }

        $this->assertNotSoftDeleted('cta_blocks', ['id' => $block->getKey()]);
        $this->assertInstanceOf(CtaBlock::class, CtaBlock::query()->find($block->getKey()));
    }

    /**
     * M-19: a revision is reverted only through its own target. Revision #n of section A addressed as a
     * revision of section B (and the same for pages) is a 404, and nothing is written.
     */
    public function test_authorization_matrix_never_reverts_a_revision_through_another_target(): void
    {
        $super = $this->createSuperAdmin();

        $sectionA = $this->makeRichContentSection();
        $sectionB = $this->makeRichContentSection();
        $pageA = $this->makeCmsPage();
        $pageB = $this->makeCmsPage();

        $before = $this->cmsFingerprint();

        $this->actingAs($super)
            ->sendCms('POST', route('admin.website.sections.revisions.revert', ['section' => $sectionB->getKey(), 'revision' => $this->firstRevisionOf($sectionA)->getKey()]), ['reason' => 'Crossing targets on purpose'])
            ->assertNotFound();

        $this->actingAs($super)
            ->sendCms('POST', route('admin.website.pages.revisions.revert', ['page' => $pageB->getKey(), 'revision' => $this->firstRevisionOf($pageA)->getKey()]), ['reason' => 'Crossing targets on purpose'])
            ->assertNotFound();

        $this->actingAs($super)
            ->sendCms('POST', route('admin.website.pages.revisions.revert', ['page' => $pageB->getKey(), 'revision' => $this->firstRevisionOf($sectionA)->getKey()]), ['reason' => 'A section revision through a page'])
            ->assertNotFound();

        $this->assertSame($before, $this->cmsFingerprint());
    }

    /**
     * A signed-in portal user is an ordinary visitor (§9): the student, teacher, client and collaborator
     * panels fail `panel:admin` on every CMS screen, as HTML and as JSON.
     */
    public function test_authorization_matrix_keeps_every_portal_out_of_the_cms_screens(): void
    {
        $screens = array_filter($this->cmsRouteTable(), static fn (array $entry): bool => $entry['kind'] === 'screen');

        foreach (self::PORTAL_ROLES as $role) {
            $user = $this->createUserWithRole($role);

            foreach ($screens as $name => $entry) {
                $request = $this->prepareCmsRequest($name, $entry, false);

                $this->actingAs($user)->sendPreparedCms($request, false)->assertForbidden();
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The CMS permissions phase-03 §9 grants a seeded role (the contract, not the seeder, is the source).
     *
     * @return list<string>
     */
    private function contractCmsGrant(string $role): array
    {
        return array_values(array_unique(match ($role) {
            User::SUPER_ADMIN_ROLE, 'Admin' => $this->cmsPermissions(),
            'SEO Expert' => array_merge(
                PermissionRegistry::permissionNamesFor('seo'),
                ['website_sections.view_any', 'website_sections.view', 'website_sections.edit'],
                ['pages.view_any', 'pages.view', 'pages.edit'],
                PermissionRegistry::permissionNamesFor('website_media'),
            ),
            'Digital Marketer' => array_merge(
                ['website_sections.view_any', 'website_sections.view', 'website_sections.edit'],
                PermissionRegistry::permissionNamesFor(['website_cta_blocks', 'faqs']),
                ['website_media.view_any', 'website_media.view', 'website_media.upload'],
            ),
            default => [],
        }));
    }

    /**
     * The columns only a publish may write (§2.2, §2.7).
     *
     * @return array<string, mixed>
     */
    private function liveColumns(string $table, int $id): array
    {
        return (array) DB::table($table)
            ->where('id', $id)
            ->first(['published_content', 'published_hash', 'status', 'published_at', 'published_by']);
    }
}
