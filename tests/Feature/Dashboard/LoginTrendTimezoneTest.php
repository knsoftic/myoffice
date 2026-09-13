<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\LoginStatus;
use App\Models\User;
use App\Support\DashboardRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Decision D61, on the dashboard's login trend: sign-ins are stored in UTC and bucketed by the calendar
 * day of the viewer's timezone — the same days the x-axis shows.
 *
 * Before the fix the widget grouped by `date(created_at)`, a UTC date. In Asia/Karachi (UTC+5) a 02:30
 * sign-in counted on the previous day, and the first day's 00:00–05:00 slice was keyed to a date the
 * axis does not have, so it vanished from the series and the totals. West of UTC (America/New_York) the
 * last evening of the window fell off the other end.
 *
 * Every planted row sits on or next to a boundary that matters: either side of UTC midnight, and either
 * side of the viewer's own midnight at both ends of the window. The clock is frozen after the window
 * closes in the viewer's zone, so the widget never clamps it to "today".
 */
final class LoginTrendTimezoneTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        DashboardRegistry::reset();
    }

    protected function tearDown(): void
    {
        DashboardRegistry::reset();

        parent::tearDown();
    }

    #[Test]
    public function the_database_session_is_pinned_to_utc_so_a_stored_string_is_the_utc_instant(): void
    {
        $this->assertSame('+00:00', (string) DB::scalar('select @@SESSION.time_zone'), 'D61: config/database.php must pin the session time_zone.');

        $moment = '2026-09-13 21:30:00';
        $user = User::factory()->create();

        $id = DB::table('login_histories')->insertGetId([
            'user_id' => $user->getKey(),
            'email' => $user->email,
            'status' => LoginStatus::Success->value,
            'logged_in_at' => $moment,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);

        $row = DB::selectOne('select created_at, unix_timestamp(created_at) as unix_created from login_histories where id = ?', [$id]);

        $this->assertSame($moment, (string) $row->created_at);
        $this->assertSame(
            CarbonImmutable::parse($moment, 'UTC')->getTimestamp(),
            (int) $row->unix_created,
            'The TIMESTAMP column must hold the UTC instant Laravel wrote, not that wall-clock read in the server zone.',
        );
    }

    #[Test]
    public function karachi_sign_ins_either_side_of_utc_midnight_land_on_the_karachi_day(): void
    {
        $viewer = $this->viewer('Asia/Karachi');

        // 14 Sep 00:30 in Karachi: the 31 Aug – 13 Sep window has closed.
        $this->travelTo(CarbonImmutable::parse('2026-09-13 19:30:00', 'UTC'));

        // Karachi window 31 Aug 00:00 – 13 Sep 23:59:59 = UTC 30 Aug 19:00:00 – 13 Sep 18:59:59.
        $this->plant($viewer, [
            ['2026-08-30 18:59:59', LoginStatus::Success], // 30 Aug 23:59:59 PKT — before the window
            ['2026-08-30 19:30:00', LoginStatus::Success], // 31 Aug 00:30 PKT — first day, UTC date 30 Aug (was dropped)
            ['2026-09-09 18:59:59', LoginStatus::Success], // 09 Sep 23:59:59 PKT
            ['2026-09-09 21:30:00', LoginStatus::Success], // 10 Sep 02:30 PKT — UTC date 9 Sep (was counted on the 9th)
            ['2026-09-10 00:30:00', LoginStatus::Success], // 10 Sep 05:30 PKT — other side of UTC midnight, same Karachi day
            ['2026-09-11 23:59:59', LoginStatus::Failed],  // 12 Sep 04:59:59 PKT
            ['2026-09-12 00:00:00', LoginStatus::Blocked], // 12 Sep 05:00:00 PKT
            ['2026-09-13 18:59:59', LoginStatus::Success], // 13 Sep 23:59:59 PKT — last second of the window
            ['2026-09-13 19:00:00', LoginStatus::Success], // 14 Sep 00:00 PKT — after the window
        ]);

        $data = $this->trend($viewer, '2026-08-31', '2026-09-13');

        $this->assertSame('2026-08-31', $data['rows'][0]['day']);
        $this->assertSame('2026-09-13', $data['rows'][13]['day']);

        $this->assertSame(
            [
                '2026-08-31' => [1, 0],
                '2026-09-09' => [1, 0],
                '2026-09-10' => [2, 0],
                '2026-09-12' => [0, 2],
                '2026-09-13' => [1, 0],
            ],
            $this->nonZeroDays($data),
            'Each sign-in is counted on its Asia/Karachi calendar day, and nothing inside the window is dropped.',
        );

        $this->assertSame(['success' => 5, 'failed' => 2], $data['totals']);
    }

    #[Test]
    public function new_york_sign_ins_either_side_of_utc_midnight_land_on_the_new_york_day(): void
    {
        $viewer = $this->viewer('America/New_York');

        // 14 Sep 08:00 in New York (EDT, UTC-4): the 31 Aug – 13 Sep window has closed.
        $this->travelTo(CarbonImmutable::parse('2026-09-14 12:00:00', 'UTC'));

        // New York window 31 Aug 00:00 – 13 Sep 23:59:59 EDT = UTC 31 Aug 04:00:00 – 14 Sep 03:59:59.
        $this->plant($viewer, [
            ['2026-08-31 03:59:59', LoginStatus::Success], // 30 Aug 23:59:59 EDT — before the window
            ['2026-08-31 04:00:00', LoginStatus::Success], // 31 Aug 00:00 EDT — first second of the window
            ['2026-09-04 23:30:00', LoginStatus::Success], // 04 Sep 19:30 EDT
            ['2026-09-05 01:00:00', LoginStatus::Success], // 04 Sep 21:00 EDT — UTC date 5 Sep (was counted on the 5th)
            ['2026-09-14 02:00:00', LoginStatus::Failed],  // 13 Sep 22:00 EDT — UTC date 14 Sep (was dropped)
            ['2026-09-14 04:00:00', LoginStatus::Success], // 14 Sep 00:00 EDT — after the window
        ]);

        $data = $this->trend($viewer, '2026-08-31', '2026-09-13');

        $this->assertSame(
            [
                '2026-08-31' => [1, 0],
                '2026-09-04' => [2, 0],
                '2026-09-13' => [0, 1],
            ],
            $this->nonZeroDays($data),
            'Each sign-in is counted on its America/New_York calendar day, and nothing inside the window is dropped.',
        );

        $this->assertSame(['success' => 3, 'failed' => 1], $data['totals']);

        // The axis labels are calendar dates too: the first day is 31 Aug for a viewer west of UTC,
        // not the 30th that UTC midnight converted to New York used to render.
        $this->assertSame('31 Aug', $data['labels'][0]);
        $this->assertSame('13 Sep', $data['labels'][13]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function viewer(string $timezone): User
    {
        return $this->createUserWithPermissions(
            ['dashboard.view', 'login_history.view_logs'],
            attributes: ['timezone' => $timezone],
        );
    }

    /**
     * @param  list<array{0: string, 1: LoginStatus}>  $rows  UTC 'Y-m-d H:i:s' and the outcome
     */
    private function plant(User $user, array $rows): void
    {
        foreach ($rows as $i => [$moment, $status]) {
            DB::table('login_histories')->insert([
                'user_id' => $user->getKey(),
                'email' => $user->email,
                'status' => $status->value,
                'ip_address' => '203.0.113.'.($i + 1),
                'logged_in_at' => $moment,
                'created_at' => $moment,
                'updated_at' => $moment,
            ]);
        }
    }

    /**
     * The widget's JSON for a custom range, read in the viewer's own timezone.
     *
     * @return array<string, mixed>
     */
    private function trend(User $viewer, string $from, string $to): array
    {
        $response = $this->actingAs($viewer)
            ->getJson('/admin/dashboard/widget/login_trend_chart?range=custom&from='.$from.'&to='.$to)
            ->assertOk()
            ->assertJsonPath('range.timezone', $viewer->timezone)
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.days', 14)
            ->assertJsonPath('data.note', null);

        /** @var array<string, mixed> $data */
        $data = $response->json('data');

        $this->assertCount(14, $data['rows']);
        $this->assertCount(14, $data['labels']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array{0: int, 1: int}> day => [successful, failed], days with any sign-in only
     */
    private function nonZeroDays(array $data): array
    {
        $days = [];

        foreach ($data['rows'] as $row) {
            if ($row['successful'] > 0 || $row['failed'] > 0) {
                $days[$row['day']] = [$row['successful'], $row['failed']];
            }
        }

        return $days;
    }
}
