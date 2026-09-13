<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Activity;
use App\Models\User;
use App\Support\ConfigureFromSettings;
use App\Support\DateRange;
use App\Support\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * Decision D61: the storage timezone is UTC.
 *
 * `config('app.timezone')` stays `UTC` and nothing changes it at runtime; `localization.timezone` is
 * display-only — `Format` renders in it, `DateRange` accepts input in it and converts to UTC for the
 * query. Before D61, `ConfigureFromSettings` switched `app.timezone` (and PHP's default timezone) to the
 * setting at boot, so every row written after Phase 2 carried Karachi wall-clock time next to Phase 1's
 * UTC rows, and changing the setting silently re-based every later write.
 *
 * The clock is frozen at 21:30 UTC on 13 Sep 2026 — already 02:30 on the 14th in Karachi — so a row
 * stored in the wrong zone lands on the wrong *day*, not just the wrong hour.
 */
final class StorageTimezoneTest extends TestCase
{
    use InteractsWithRbac;
    use InteractsWithSettingsForms;
    use RefreshDatabase;

    private const UTC_MOMENT = '2026-09-13 21:30:00';

    private string $processTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();

        $this->processTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        // Whatever the application did to the process timezone must not leak into later tests.
        date_default_timezone_set($this->processTimezone);

        parent::tearDown();
    }

    #[Test]
    public function the_configured_storage_timezone_is_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
    }

    #[Test]
    public function saving_and_applying_a_display_timezone_never_changes_the_storage_timezone(): void
    {
        $admin = $this->createSuperAdmin();

        $this->saveTimezone($admin, 'Asia/Karachi');

        // What every boot does, and what the settings screen does after a save.
        ConfigureFromSettings::apply();

        $this->assertSame('Asia/Karachi', setting('localization.timezone'));
        $this->assertSame('UTC', config('app.timezone'), 'D61: localization.timezone is display-only; app.timezone stays UTC.');
        $this->assertSame('UTC', date_default_timezone_get(), 'D61: nothing calls date_default_timezone_set() at runtime.');
    }

    #[Test]
    public function a_row_written_while_the_display_timezone_is_karachi_is_stored_in_utc_and_shown_in_karachi(): void
    {
        $admin = $this->createSuperAdmin();

        $this->saveTimezone($admin, 'Asia/Karachi');
        ConfigureFromSettings::apply();
        app(SettingsRepository::class)->flush();

        $this->travelTo(CarbonImmutable::parse(self::UTC_MOMENT, 'UTC'));

        $user = User::factory()->create(['email' => 'utc-check@example.test', 'timezone' => null]);

        $raw = (string) DB::table('users')->where('id', $user->getKey())->value('created_at');

        $this->assertSame(
            self::UTC_MOMENT,
            $raw,
            'D61: the created_at column must hold UTC wall-clock time whatever localization.timezone says.',
        );

        // Rendered through the helpers every view uses: the same instant, in the display timezone.
        $this->assertSame('14 Sep 2026 02:30 AM', app_datetime($user->fresh()->created_at));
        $this->assertSame('14 Sep 2026', app_date($user->fresh()->created_at));
    }

    #[Test]
    public function the_audit_trail_is_stored_in_utc_too(): void
    {
        $admin = $this->createSuperAdmin();

        $this->saveTimezone($admin, 'Asia/Karachi');
        ConfigureFromSettings::apply();

        $this->travelTo(CarbonImmutable::parse(self::UTC_MOMENT, 'UTC'));

        $since = $this->lastActivityId();

        $this->actingAs($admin)
            ->put('/admin/settings/company', $this->browserPayload($admin, 'company', ['tagline' => 'Stamped in UTC']))
            ->assertSessionHasNoErrors();

        $row = Activity::query()->where('id', '>', $since)->where('properties->key', 'company.tagline')->firstOrFail();

        $this->assertSame(self::UTC_MOMENT, (string) DB::table('activity_log')->where('id', $row->getKey())->value('created_at'));
        $this->assertSame(self::UTC_MOMENT, (string) DB::table('settings')->where('group', 'company')->where('key', 'tagline')->value('updated_at'));
    }

    #[Test]
    public function a_karachi_day_selects_the_utc_rows_that_fall_inside_it(): void
    {
        $admin = $this->createSuperAdmin();

        $this->saveTimezone($admin, 'Asia/Karachi');
        ConfigureFromSettings::apply();

        // Stored in UTC: 18:59:59 on the 13th is still the 13th in Karachi; 19:00:00 is the 14th.
        $ids = [];

        foreach (['2026-09-13 18:59:59', '2026-09-13 19:00:00', self::UTC_MOMENT, '2026-09-14 18:59:59', '2026-09-14 19:00:00'] as $moment) {
            $this->travelTo(CarbonImmutable::parse($moment, 'UTC'));
            $ids[$moment] = User::factory()->create()->getKey();
        }

        $this->travelBack();

        $range = DateRange::custom('2026-09-14', '2026-09-14', 'Asia/Karachi');

        $selected = $range->apply(User::query()->whereIn('id', array_values($ids)))->pluck('id')->all();

        $this->assertEqualsCanonicalizing(
            [$ids['2026-09-13 19:00:00'], $ids[self::UTC_MOMENT], $ids['2026-09-14 18:59:59']],
            $selected,
            'A range picked as "14 Sep" in Karachi selects exactly the rows stored between 19:00 UTC on the 13th and 18:59:59 UTC on the 14th.',
        );
    }

    private function saveTimezone(User $admin, string $timezone): void
    {
        $this->actingAs($admin)
            ->from('/admin/settings/localization')
            ->put('/admin/settings/localization', $this->browserPayload($admin, 'localization', ['timezone' => $timezone]))
            ->assertRedirect('/admin/settings/localization')
            ->assertSessionHasNoErrors();

        app(SettingsRepository::class)->flush();
    }
}
