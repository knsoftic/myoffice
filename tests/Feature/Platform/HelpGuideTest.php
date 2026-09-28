<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\PanelType;
use App\Models\Role;
use App\Support\RichText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The operating guide, rendered in the panel it describes.
 *
 * Three things here are worth a test rather than a glance.
 *
 * **It must not 403 anybody who can sign in.** A manual some roles cannot open is not a manual, and
 * the reason it is gated on `dashboard.view` rather than a module of its own is that a new
 * permission would have to be seeded onto every role that should read it — starting with the
 * front-desk roles that need it most.
 *
 * **The contents and the anchors come from one pass.** An index built separately from the headings
 * it points at is an index whose links break the first time a heading is renamed, and nothing would
 * report it.
 *
 * **`code` and `pre` survive.** They were added to the `material` sanitiser profile for this screen,
 * because a guide that quotes settings keys and shell commands and loses every one of them to the
 * sanitiser is a guide nobody can follow. `cms` — the public website's profile — deliberately did
 * not get them.
 */
final class HelpGuideTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        Cache::flush();
    }

    #[Test]
    public function the_guide_renders_for_a_super_admin(): void
    {
        $response = $this->actingAs($this->createSuperAdmin())->get(route('admin.help.index'));

        $response->assertOk();
        $response->assertSee('Daftar chalane ka tareeqa');
        $response->assertSee('Fehrist');
    }

    /**
     * The point of gating it on `dashboard.view`.
     *
     * Every seeded admin role holds that ability, so every one of them can read the manual. If this
     * fails, somebody has gated the guide on something narrower and a role has been shut out of the
     * document that explains their own job.
     */
    #[Test]
    public function every_seeded_admin_role_can_open_the_guide(): void
    {
        $roles = Role::query()
            ->where('panel', PanelType::Admin->value)
            ->pluck('name')
            ->all();

        $this->assertNotEmpty($roles, 'No admin roles are seeded, so this test proves nothing.');

        $refused = [];

        foreach ($roles as $name) {
            $user = $this->createUserWithRole($name);

            if ($this->actingAs($user)->get(route('admin.help.index'))->getStatusCode() !== 200) {
                $refused[] = $name;
            }
        }

        $this->assertSame(
            [],
            $refused,
            "These roles cannot open the manual that explains their own job:\n  - ".implode("\n  - ", $refused),
        );
    }

    #[Test]
    public function a_signed_out_visitor_cannot_read_it(): void
    {
        $this->get(route('admin.help.index'))->assertRedirect();
    }

    /** The contents are only useful if the anchors land somewhere. */
    #[Test]
    public function every_contents_link_points_at_a_heading_that_exists(): void
    {
        $body = (string) $this->actingAs($this->createSuperAdmin())
            ->get(route('admin.help.index'))
            ->getContent();

        preg_match_all('/<a href="#([a-z0-9-]+)"/i', $body, $links);

        // Every id on the page, not only the headings': the admin layout carries a skip-to-content
        // link pointing at `<main id="main-content">`, and an accessibility affordance is not a
        // dangling anchor. What this still catches is the bug it was written for -- a contents entry
        // pointing at a heading id that nothing generated.
        preg_match_all('/\sid="([a-z0-9-]+)"/i', $body, $targets);

        $anchors = array_unique($links[1]);
        $ids = array_flip($targets[1]);

        $this->assertNotEmpty($anchors, 'The contents rendered no links at all.');

        $dangling = array_values(array_filter(
            $anchors,
            static fn (string $a): bool => ! isset($ids[$a]),
        ));

        $this->assertSame(
            [],
            $dangling,
            "The contents link to anchors no heading carries:\n  - ".implode("\n  - ", $dangling),
        );
    }

    /**
     * The sanitiser change this screen needed, and the one it deliberately did not make.
     */
    #[Test]
    public function the_material_profile_keeps_code_and_pre_and_the_cms_profile_does_not(): void
    {
        $html = Str::markdown("`smtp.gmail.com`\n\n```\nphp artisan migrate\n```\n");

        $material = RichText::sanitize($html, 'material');
        $cms = RichText::sanitize($html, 'cms');

        $this->assertStringContainsString('<code', $material);
        $this->assertStringContainsString('<pre', $material);

        // The public website has no use for either, and a profile earns a tag by needing it.
        $this->assertStringNotContainsString('<code', $cms);
        $this->assertStringNotContainsString('<pre', $cms);

        // What neither profile may ever keep.
        $nasty = RichText::sanitize('<script>alert(1)</script><p onclick="x()">hi</p>', 'material');
        $this->assertStringNotContainsString('<script', $nasty);
        $this->assertStringNotContainsString('onclick', $nasty);
    }

    /** The guide's own tables have to survive, or half the document is gone. */
    #[Test]
    public function the_guides_tables_survive_the_sanitiser(): void
    {
        $body = (string) $this->actingAs($this->createSuperAdmin())
            ->get(route('admin.help.index'))
            ->getContent();

        $this->assertStringContainsString('<table>', $body);
        $this->assertStringContainsString('<th>', $body);
    }
}
