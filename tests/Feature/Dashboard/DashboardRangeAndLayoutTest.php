<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Dashboard\Layout;
use App\Enums\LoginStatus;
use App\Models\User;
use App\Support\DashboardRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * phase-02 §6 "Dashboard": the date range changes the numbers, and the layout preference persists per
 * user and is never shared between users.
 *
 * The clock is frozen at noon UTC on 13 Sep 2026, and every planted sign-in sits at least a few hours
 * away from any day boundary, so the figures are the same whichever timezone the range is built in.
 */
final class DashboardRangeAndLayoutTest extends TestCase
{
    use InteractsWithRbac;
    use InteractsWithSettingsForms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        DashboardRegistry::reset();

        $this->travelTo(CarbonImmutable::parse('2026-09-13 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        DashboardRegistry::reset();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Date range
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_date_range_changes_the_numbers_a_widget_reports(): void
    {
        $viewer = $this->createUserWithPermissions(['dashboard.view', 'login_history.view_logs']);

        $before = [];

        foreach (['today', 'month', 'year'] as $range) {
            $before[$range] = $this->loginsFor($viewer, $range);
        }

        $this->plantSignIns($viewer, [
            '2026-09-13 09:00:00' => 2,   // today
            '2026-09-02 12:00:00' => 3,   // earlier this month
            '2026-03-10 12:00:00' => 4,   // earlier this year
            '2025-11-15 12:00:00' => 6,   // last year: never counted below
        ]);

        $this->assertSame($before['today'] + 2, $this->loginsFor($viewer, 'today'), 'today');
        $this->assertSame($before['month'] + 5, $this->loginsFor($viewer, 'month'), 'this month');
        $this->assertSame($before['year'] + 9, $this->loginsFor($viewer, 'year'), 'this year');

        $custom = $this->actingAs($viewer)
            ->getJson('/admin/dashboard/widget/logins_today?range=custom&from=2025-11-01&to=2025-11-30')
            ->assertOk();

        $this->assertSame(6, (int) $custom->json('data.current'), 'A custom window counts only its own days.');
        $this->assertSame('custom', $custom->json('range.preset'));
        $this->assertSame('2025-11-01', $custom->json('range.from'));
        $this->assertSame('2025-11-30', $custom->json('range.to'));
    }

    #[Test]
    public function the_page_renders_different_figures_for_a_different_range(): void
    {
        $admin = $this->createSuperAdmin();

        $this->plantSignIns($admin, [
            '2026-09-13 09:00:00' => 1,
            '2026-03-10 12:00:00' => 11,
        ]);

        $today = $this->cardBody((string) $this->actingAs($admin)->get('/admin?range=today')->assertOk()->getContent(), 'logins_today');
        $year = $this->cardBody((string) $this->actingAs($admin)->get('/admin?range=year')->assertOk()->getContent(), 'logins_today');

        $this->assertNotSame('', $today);
        $this->assertNotSame($today, $year, 'Changing the global range must change what the logins card renders.');
        $this->assertMatchesRegularExpression('/\b12\b/', $year, 'The year view shows all twelve sign-ins.');
    }

    #[Test]
    public function an_unreadable_range_falls_back_to_the_default_instead_of_failing(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)->get('/admin?range=decade')->assertOk();
        $this->actingAs($admin)->get('/admin?range=custom&from=garbage&to=junk')->assertOk();
        $this->actingAs($admin)
            ->getJson('/admin/dashboard/widget/logins_today?range=custom&from=garbage')
            ->assertOk()
            ->assertJsonPath('range.preset', 'month');
    }

    /*
    |--------------------------------------------------------------------------
    | Per-user layout
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_layout_persists_for_the_user_who_saved_it_and_nobody_else(): void
    {
        $alice = $this->createSuperAdmin(['name' => 'Alice Layout']);
        $bob = $this->createSuperAdmin(['name' => 'Bob Default']);

        $order = ['roles_overview', 'modules_enabled', 'users_by_status'];

        $this->actingAs($alice)
            ->putJson('/admin/dashboard/layout', ['order' => $order, 'hidden' => ['recent_activity', 'system_health']])
            ->assertOk()
            ->assertJsonPath('saved', true);

        $alice->refresh();
        $bob->refresh();

        $this->assertSame($order, array_slice((array) $alice->preference(Layout::PREFERENCE_ORDER), 0, 3));
        $this->assertEqualsCanonicalizing(['recent_activity', 'system_health'], (array) $alice->preference(Layout::PREFERENCE_HIDDEN));

        $this->assertNull($bob->preference(Layout::PREFERENCE_ORDER), 'Another user’s layout is untouched.');
        $this->assertNull($bob->preference(Layout::PREFERENCE_HIDDEN));

        // Alice's dashboard follows her arrangement; Bob's keeps the default.
        $aliceLayout = DashboardRegistry::layoutFor($alice);
        $bobLayout = DashboardRegistry::layoutFor($bob);

        $this->assertNotContains('recent_activity', $aliceLayout['visible']->keys()->all());
        $this->assertContains('recent_activity', $aliceLayout['hidden']->keys()->all());
        $this->assertContains('recent_activity', $bobLayout['visible']->keys()->all());

        $aliceHtml = (string) $this->actingAs($alice)->get('/admin')->assertOk()->getContent();
        $bobHtml = (string) $this->actingAs($bob)->get('/admin')->assertOk()->getContent();

        $aliceOverview = array_values(array_intersect($this->renderedKeys($aliceHtml), $order));
        $this->assertSame($order, $aliceOverview, 'Alice’s cards render in the order she saved.');

        $this->assertSame('loading', $this->cardState($aliceHtml, 'recent_activity'), 'A card Alice hid costs no query: it ships as a skeleton.');
        $this->assertSame('ready', $this->cardState($bobHtml, 'recent_activity'), 'Bob never hid it.');

        // Saving again replaces rather than merges, and still only for Alice.
        $this->actingAs($alice)->putJson('/admin/dashboard/layout', ['order' => [], 'hidden' => []])->assertOk();

        $this->assertSame([], (array) $alice->fresh()->preference(Layout::PREFERENCE_HIDDEN, []));
        $this->assertNull($bob->fresh()->preference(Layout::PREFERENCE_HIDDEN));
    }

    #[Test]
    public function a_layout_request_can_never_name_whose_layout_it_edits(): void
    {
        $alice = $this->createSuperAdmin();
        $bob = $this->createSuperAdmin();

        $this->actingAs($alice)
            ->putJson('/admin/dashboard/layout', ['hidden' => ['roles_overview'], 'user_id' => $bob->getKey()])
            ->assertOk();

        $this->assertSame(['roles_overview'], (array) $alice->fresh()->preference(Layout::PREFERENCE_HIDDEN));
        $this->assertNull($bob->fresh()->preference(Layout::PREFERENCE_HIDDEN));
    }

    #[Test]
    public function a_layout_may_only_name_cards_the_viewer_can_see(): void
    {
        $viewer = $this->createUserWithPermissions(['dashboard.view', 'users.view_any']);

        $this->actingAs($viewer)
            ->putJson('/admin/dashboard/layout', ['order' => ['users_by_status'], 'hidden' => ['recent_activity']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('hidden.0');

        $this->assertNull($viewer->fresh()->preference(Layout::PREFERENCE_HIDDEN), 'A refused layout stores nothing.');

        $this->actingAs($viewer)
            ->putJson('/admin/dashboard/layout', ['order' => ['Not A Key']])
            ->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function loginsFor(User $viewer, string $range): int
    {
        return (int) $this->actingAs($viewer)
            ->getJson('/admin/dashboard/widget/logins_today?range='.$range)
            ->assertOk()
            ->assertJsonPath('range.preset', $range)
            ->json('data.current');
    }

    /**
     * @param  array<string, int>  $moments  UTC 'Y-m-d H:i:s' => how many successful sign-ins then
     */
    private function plantSignIns(User $user, array $moments): void
    {
        foreach ($moments as $moment => $count) {
            for ($i = 0; $i < $count; $i++) {
                DB::table('login_histories')->insert([
                    'user_id' => $user->getKey(),
                    'email' => $user->email,
                    'status' => LoginStatus::Success->value,
                    'ip_address' => '198.51.100.'.($i + 1),
                    'logged_in_at' => $moment,
                    'created_at' => $moment,
                    'updated_at' => $moment,
                ]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function renderedKeys(string $html): array
    {
        preg_match_all('/data-widget-key="([a-z0-9_]+)"/', $html, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function cardState(string $html, string $key): ?string
    {
        $node = $this->xpath($html)->query('//*[@data-widget-key="'.$key.'"]')->item(0);

        return $node instanceof \DOMElement ? $node->getAttribute('data-widget-state') : null;
    }

    private function cardBody(string $html, string $key): string
    {
        $xpath = $this->xpath($html);
        $body = $xpath->query('//*[@data-widget-key="'.$key.'"]//*[@data-widget-body]')->item(0);

        return $body === null ? '' : trim((string) preg_replace('/\s+/', ' ', (string) $body->textContent));
    }
}
