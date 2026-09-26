<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\LoginStatus;
use App\Models\Role;
use App\Models\User;
use App\Support\DashboardRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-02 §6 "Performance": the dashboard issues a bounded number of queries — no N+1 across widgets.
 *
 * Measured with `DB::listen` around the real HTTP request, twice: once on a small data set and once after
 * every table a widget reads has grown by an order of magnitude. A widget that queries inside a loop (per
 * user, per role, per activity row, per sign-in) makes the second count larger than the first; a
 * constant-query dashboard never exceeds the first count (it can come in one lower, when a per-request
 * cache the first measurement filled is already warm). The absolute ceilings are generous on purpose —
 * they catch a runaway, not a single extra lookup — while the growth check admits no growth at all.
 */
final class DashboardQueryBudgetTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /**
     * Ceiling for the whole GET /admin request: session, auth, RBAC, settings, shell and every card.
     *
     * **Raised from 60 to 90 when the registry went from 38 widgets to 58**, and the number is the
     * only honest part of this comment — the reasoning is what matters, because "the test failed so
     * I raised the limit" is exactly how a ceiling stops meaning anything.
     *
     * What was checked before touching it:
     *
     *   · **The growth check passes.** With the ceiling lifted out of the way, `$large <= $small`
     *     held — the count does not move when every table a widget reads grows by an order of
     *     magnitude. That is the assertion that catches an N+1, and it is untouched at zero growth.
     *   · **Every widget already costs one query.** The fifteen added in this round each fold their
     *     figures into a single conditional-aggregate pass; there is no waste left to remove. The
     *     previous breach (66 against 60) was different in kind — five widgets were spending twelve
     *     queries and the fix was to write them properly, not to move the line.
     *   · **The arithmetic no longer fits.** 58 cards that each cost one query cannot render inside
     *     60 total alongside session, auth, RBAC, settings and the shell. Measured: 77.
     *
     * So this ceiling tracks the product rather than a moment in it, and 90 leaves room for roughly
     * a dozen more cards before it asks the question again — which is the point of a ceiling that is
     * allowed to complain. **It catches a runaway, not growth**; the growth check catches the rest.
     *
     * If it is ever raised again, the same three things are what justify it. A raise without them is
     * the scan being quietened (see `DEVELOPMENT_LOG.md` D171).
     */
    private const PAGE_CEILING = 90;

    /** Ceiling for one widget's JSON. */
    private const WIDGET_CEILING = 20;

    /** @var list<string> */
    private array $queries = [];

    private bool $recording = false;

    private bool $listening = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        DashboardRegistry::reset();

        // The database session driver garbage-collects expired sessions on a 2-in-100 lottery, which
        // adds a `delete from sessions` to a random request. That query belongs to no widget, and it
        // made the growth check fail at random (seen under --order-by=random: "login_trend_chart grew
        // from 3 to 4 queries", the 4th being the GC delete). Switching the lottery off makes the
        // count deterministic; every ceiling and the no-growth rule are unchanged.
        config(['session.lottery' => [0, 100]]);
    }

    protected function tearDown(): void
    {
        DashboardRegistry::reset();

        parent::tearDown();
    }

    #[Test]
    public function the_dashboard_query_count_does_not_grow_with_the_data(): void
    {
        $admin = $this->createSuperAdmin();

        // Something in every table first, so the first measurement already exercises every eager load.
        $this->growData($admin, 3);
        $this->warm($admin, '/admin');

        $small = $this->countQueries(fn () => $this->actingAs($admin)->get('/admin')->assertOk());

        $this->growData($admin, 40);
        $this->warm($admin, '/admin');

        $large = $this->countQueries(fn () => $this->actingAs($admin)->get('/admin')->assertOk());

        $this->assertLessThanOrEqual(self::PAGE_CEILING, $small['count'], "GET /admin ran {$small['count']} queries:\n".$small['log']);
        $this->assertLessThanOrEqual(
            $small['count'],
            $large['count'],
            sprintf(
                "GET /admin went from %d to %d queries when the data grew — a widget is querying per row.\nSmall:\n%s\nLarge:\n%s",
                $small['count'],
                $large['count'],
                $small['log'],
                $large['log'],
            ),
        );
    }

    #[Test]
    public function rendering_every_card_inline_is_bounded_too(): void
    {
        $admin = $this->createSuperAdmin();

        $this->growData($admin, 3);
        $this->warm($admin, '/admin?eager=1');

        $small = $this->countQueries(fn () => $this->actingAs($admin)->get('/admin?eager=1')->assertOk());

        $this->growData($admin, 40);
        $this->warm($admin, '/admin?eager=1');

        $large = $this->countQueries(fn () => $this->actingAs($admin)->get('/admin?eager=1')->assertOk());

        $this->assertLessThanOrEqual(self::PAGE_CEILING + 15, $small['count'], "GET /admin?eager=1 ran {$small['count']} queries:\n".$small['log']);
        $this->assertLessThanOrEqual($small['count'], $large['count'], "eager=1 grew from {$small['count']} to {$large['count']} queries:\n".$large['log']);
    }

    #[Test]
    public function each_widget_json_is_bounded_and_constant(): void
    {
        $admin = $this->createSuperAdmin();

        $this->growData($admin, 3);

        foreach (DashboardRegistry::for($admin)->keys() as $key) {
            $this->warm($admin, '/admin/dashboard/widget/'.$key);
        }

        $small = [];

        foreach (DashboardRegistry::for($admin)->keys() as $key) {
            $small[$key] = $this->countQueries(fn () => $this->actingAs($admin)->getJson('/admin/dashboard/widget/'.$key)->assertOk());
        }

        $this->growData($admin, 40);

        foreach ($small as $key => $measurement) {
            $this->warm($admin, '/admin/dashboard/widget/'.$key);

            $large = $this->countQueries(fn () => $this->actingAs($admin)->getJson('/admin/dashboard/widget/'.$key)->assertOk());

            $this->assertLessThanOrEqual(self::WIDGET_CEILING, $measurement['count'], "{$key} ran {$measurement['count']} queries:\n".$measurement['log']);
            $this->assertLessThanOrEqual($measurement['count'], $large['count'], "{$key} grew from {$measurement['count']} to {$large['count']} queries:\n".$large['log']);
        }
    }

    /**
     * Grow every table a Phase 2 widget reads: users (with roles), sign-ins of every status, activity.
     */
    private function growData(User $causer, int $times): void
    {
        $roles = Role::query()->whereNotIn('name', [User::SUPER_ADMIN_ROLE])->limit(4)->get();

        for ($i = 0; $i < $times; $i++) {
            $user = User::factory()->create();
            $user->assignRole($roles[$i % max(1, $roles->count())]);

            foreach ([LoginStatus::Success, LoginStatus::Failed, LoginStatus::Blocked, LoginStatus::Success] as $n => $status) {
                DB::table('login_histories')->insert([
                    'user_id' => $user->getKey(),
                    'email' => $user->email,
                    'status' => $status->value,
                    'ip_address' => '203.0.113.'.(($i + $n) % 250 + 1),
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
                    'logged_in_at' => now()->subHours($n),
                    'created_at' => now()->subHours($n),
                    'updated_at' => now()->subHours($n),
                ]);
            }

            activity('users')->causedBy($user)->performedOn($causer)->log('Budget row '.$i);
        }

        $this->forgetPermissionCache();
    }

    /**
     * One request first, so one-off cache fills (settings payload, permission map, module map,
     * discovered widget classes) are not billed to the measured request.
     */
    private function warm(User $admin, string $uri): void
    {
        str_contains($uri, '/widget/')
            ? $this->actingAs($admin)->getJson($uri)->assertOk()
            : $this->actingAs($admin)->get($uri)->assertOk();
    }

    /**
     * @param  callable(): TestResponse  $request
     * @return array{count: int, log: string}
     */
    private function countQueries(callable $request): array
    {
        if (! $this->listening) {
            DB::listen(function (QueryExecuted $query): void {
                if ($this->recording) {
                    $this->queries[] = $query->sql;
                }
            });

            $this->listening = true;
        }

        $this->queries = [];
        $this->recording = true;

        try {
            $request();
        } finally {
            $this->recording = false;
        }

        $measured = $this->queries;
        $this->queries = [];

        return [
            'count' => count($measured),
            'log' => implode("\n", array_map(static fn (string $sql, int $i): string => ($i + 1).'. '.$sql, $measured, array_keys($measured))),
        ];
    }
}
