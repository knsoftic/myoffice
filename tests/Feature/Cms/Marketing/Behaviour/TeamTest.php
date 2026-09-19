<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Enums\Cms\ContentStatus;
use App\Models\Cms\TeamMember;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\TeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 test 14 — the team page (§2.9, §6.4 invariant 3, §9.2).
 *
 * `is_public = false` hides a published member from `/team` without unpublishing it, while the admin list keeps
 * it; a `social_links` key that is not a `SocialPlatform` value (`myspace`) is refused 422 and stores nothing.
 */
final class TeamTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    public function test_14_a_hidden_member_leaves_the_public_page_but_stays_in_admin(): void
    {
        $admin = $this->actAsSuperAdmin();

        $visible = $this->team()->store([
            'name' => 'Ayesha Visible Engineer',
            'designation' => 'Lead Engineer',
            'status' => ContentStatus::Published->value,
            'is_public' => true,
            'social_links' => ['linkedin' => 'https://www.linkedin.com/in/ayesha-visible'],
        ], null);

        $hidden = $this->team()->store([
            'name' => 'Bilal Hidden Designer',
            'designation' => 'Product Designer',
            'status' => ContentStatus::Published->value,
            'is_public' => false,
        ], null);

        $this->assertSame(ContentStatus::Published, $hidden->fresh()->status, 'Hiding is not unpublishing.');
        $this->assertSame([(int) $visible->getKey()], TeamMember::query()->public()->whereKey([$visible->getKey(), $hidden->getKey()])->pluck('id')->map('intval')->all());

        $this->becomeGuest();
        $this->bumpPublicCache('test 14');

        $this->get(route('site.team.index'))
            ->assertOk()
            ->assertSee('Ayesha Visible Engineer')
            ->assertDontSee('Bilal Hidden Designer');

        $this->actingAs($admin)
            ->get(route('admin.team.index'))
            ->assertOk()
            ->assertSee('Ayesha Visible Engineer')
            ->assertSee('Bilal Hidden Designer');

        // Showing the member again puts it back on the public page.
        $this->team()->togglePublic($hidden->fresh());
        $this->becomeGuest();

        $this->get(route('site.team.index'))->assertOk()->assertSee('Bilal Hidden Designer');
    }

    public function test_14_an_unknown_social_platform_key_is_refused(): void
    {
        $this->actAsSuperAdmin();

        $before = TeamMember::withTrashed()->count();

        try {
            $this->team()->store([
                'name' => 'Myspace Fan',
                'designation' => 'Nostalgic Developer',
                'social_links' => ['myspace' => 'https://myspace.com/fan'],
            ], null);
            $this->fail('An unknown social platform must be refused.');
        } catch (ContentRuleException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('social_links', $exception->errors());
        }

        $this->assertSame($before, TeamMember::withTrashed()->count(), 'A refused member stores nothing.');

        // The same refusal on an update leaves the stored links untouched.
        $member = $this->team()->store([
            'name' => 'Known Links Member',
            'designation' => 'QA Engineer',
            'social_links' => ['github' => 'https://github.com/known-links'],
        ], null);

        try {
            $this->team()->update($member->fresh(), ['social_links' => ['github' => 'https://github.com/x', 'myspace' => 'https://myspace.com/x']], null);
            $this->fail('An unknown social platform must be refused on update too.');
        } catch (ContentRuleException $exception) {
            $this->assertArrayHasKey('social_links', $exception->errors());
        }

        $this->assertSame(['github' => 'https://github.com/known-links'], $member->fresh()->social_links);
    }

    private function team(): TeamService
    {
        return app(TeamService::class);
    }
}
