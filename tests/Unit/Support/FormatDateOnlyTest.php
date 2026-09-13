<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Format;
use App\Support\SettingsRepository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon as IlluminateCarbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Decision D61, for calendar dates: a date is not an instant.
 *
 * Stored instants are UTC and are converted into the display timezone. A calendar date — a 'Y-m-d'
 * string, or what a `date` cast yields (a Carbon at 00:00:00) — is the same date for everyone. Before
 * the fix `Format::carbon()` read it as UTC midnight and converted it, so `app_date('2026-09-14')` said
 * 13 Sep for every zone west of UTC; `app_date($invoice->due_date)` would have done the same from
 * Phase 5 on.
 *
 * Also pinned here: the stored-empty thousand separator means "no grouping", as the registry's help
 * text promises, while a missing row still falls back to ','.
 *
 * A pure unit test. The process timezone is UTC (D61); the display timezone comes from a settings
 * repository seeded in memory and bound into a bare container, which is the path `app_date()` really
 * takes — no database, no application boot.
 */
final class FormatDateOnlyTest extends TestCase
{
    private string $processTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        Container::setInstance(null);

        $this->processTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        date_default_timezone_set($this->processTimezone);
        Container::setInstance(null);

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function displayTimezoneProvider(): array
    {
        return [
            'America/New_York (UTC-4, west of UTC)' => ['America/New_York'],
            'Asia/Karachi (UTC+5, east of UTC)' => ['Asia/Karachi'],
        ];
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function calendarDateProvider(): array
    {
        return [
            "a 'Y-m-d' string" => ['2026-09-14'],
            'a date-cast Illuminate Carbon (UTC midnight)' => [IlluminateCarbon::parse('2026-09-14 00:00:00', 'UTC')],
            'an immutable_date-cast CarbonImmutable (UTC midnight)' => [CarbonImmutable::parse('2026-09-14 00:00:00', 'UTC')],
            'a midnight in another zone' => [CarbonImmutable::parse('2026-09-14 00:00:00', 'Pacific/Kiritimati')],
        ];
    }

    #[Test]
    #[DataProvider('displayTimezoneProvider')]
    public function a_date_only_string_is_that_date_in_every_display_timezone(string $timezone): void
    {
        $date = Format::carbon('2026-09-14', $timezone);

        $this->assertNotNull($date);
        $this->assertSame('2026-09-14 00:00:00', $date->toDateTimeString(), 'The calendar date, at the start of that day, never shifted from UTC midnight.');
        $this->assertSame($timezone, $date->timezoneName);
    }

    #[Test]
    #[DataProvider('displayTimezoneProvider')]
    public function app_date_renders_every_calendar_date_shape_as_the_same_date(string $timezone): void
    {
        $this->bindSettings(['localization.timezone' => $timezone, 'localization.date_format' => 'd M Y']);

        $this->assertSame($timezone, Format::displayTimezone(), 'Precondition: the display timezone comes from localization.timezone.');

        foreach (self::calendarDateProvider() as $shape => [$value]) {
            $this->assertSame('14 Sep 2026', app_date($value), $shape.' in '.$timezone);
            $this->assertSame('2026-09-14', Format::date($value, 'Y-m-d'), $shape.' in '.$timezone.' with an explicit format');
            $this->assertSame('2026-09-14', Format::carbon($value)?->toDateString(), $shape.' through Format::carbon()');
        }
    }

    #[Test]
    #[DataProvider('displayTimezoneProvider')]
    public function a_date_cast_value_is_not_mutated_by_being_rendered(string $timezone): void
    {
        $dueDate = IlluminateCarbon::parse('2026-09-14 00:00:00', 'UTC');

        Format::carbon($dueDate, $timezone);

        $this->assertSame('UTC', $dueDate->timezoneName);
        $this->assertSame('2026-09-14 00:00:00', $dueDate->toDateTimeString());
    }

    /**
     * The fix must not over-correct: a stored instant still moves into the display timezone.
     */
    #[Test]
    public function an_instant_is_still_converted_into_the_display_timezone(): void
    {
        // 21:30 UTC on the 13th is 17:30 on the 13th in New York and 02:30 on the 14th in Karachi.
        foreach (['2026-09-13 21:30:00', CarbonImmutable::parse('2026-09-13 21:30:00', 'UTC'), 1789335000] as $instant) {
            $this->assertSame('2026-09-13 17:30:00', Format::carbon($instant, 'America/New_York')?->toDateTimeString());
            $this->assertSame('2026-09-14 02:30:00', Format::carbon($instant, 'Asia/Karachi')?->toDateTimeString());
        }

        // A time of day is only asked of a moment: a datetime that happens to sit on UTC midnight is
        // rendered as the instant it is by the time-bearing helpers.
        $midnightUtc = CarbonImmutable::parse('2026-09-14 00:00:00', 'UTC');

        $this->bindSettings(['localization.timezone' => 'Asia/Karachi', 'localization.date_format' => 'd M Y', 'localization.time_format' => 'h:i A']);
        $this->assertSame('14 Sep 2026 05:00 AM', app_datetime($midnightUtc));
        $this->assertSame('05:00 AM', app_time($midnightUtc));

        $this->bindSettings(['localization.timezone' => 'America/New_York', 'localization.date_format' => 'd M Y', 'localization.time_format' => 'h:i A']);
        $this->assertSame('13 Sep 2026 08:00 PM', app_datetime($midnightUtc));
    }

    #[Test]
    public function a_date_only_string_that_does_not_exist_renders_empty_instead_of_rolling_over(): void
    {
        $this->assertNull(Format::carbon('2026-02-30', 'America/New_York'));
        $this->assertSame('', app_date('2026-02-30'));
        $this->assertSame('', app_date('2026-13-01'));
    }

    #[Test]
    public function a_calendar_date_survives_a_display_timezone_whose_midnight_is_skipped(): void
    {
        // America/Santiago springs forward at 00:00 on 6 Sep 2026: that midnight never happens.
        $date = Format::carbon('2026-09-06', 'America/Santiago');

        $this->assertSame('2026-09-06', $date?->toDateString());
    }

    /*
    |--------------------------------------------------------------------------
    | Thousand separator: stored empty vs missing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_stored_empty_thousand_separator_means_no_grouping(): void
    {
        $this->bindSettings(['localization.thousand_separator' => '']);

        $this->assertSame('', Format::thousandSeparator());
        $this->assertSame('1234567', app_number(1234567));
        $this->assertSame('1234567.89', app_number('1234567.891', 2));
        // money() follows the same rule — it used to fall back to ',' on a stored empty separator.
        $this->assertSame('1234567.89', money('1234567.89', false));
    }

    #[Test]
    public function a_stored_null_thousand_separator_also_means_no_grouping(): void
    {
        $this->bindSettings(['localization.thousand_separator' => null]);

        $this->assertSame('', Format::thousandSeparator());
        $this->assertSame('1234567', app_number(1234567));
    }

    #[Test]
    public function a_missing_thousand_separator_row_falls_back_to_a_comma(): void
    {
        $this->bindSettings(['localization.timezone' => 'UTC']);

        $this->assertSame(',', Format::thousandSeparator());
        $this->assertSame('1,234,567', app_number(1234567));
        $this->assertSame('1,234,567.00', money('1234567', false));
    }

    #[Test]
    public function a_stored_space_thousand_separator_is_kept(): void
    {
        $this->bindSettings(['localization.thousand_separator' => ' ']);

        $this->assertSame(' ', Format::thousandSeparator());
        $this->assertSame('1 234 567', app_number(1234567));
    }

    /**
     * A settings repository holding exactly these rows, bound into a bare container so `settings_repo()`
     * (and so every Format read) resolves it. Its rows are seeded into the in-memory payload the
     * repository would otherwise load from the table, so nothing touches a cache or a database.
     *
     * @param  array<string, string|null>  $values  'group.key' => stored value
     */
    private function bindSettings(array $values): void
    {
        $rows = [];

        foreach ($values as $key => $value) {
            $rows[$key] = [
                'value' => $value,
                'type' => 'string',
                'is_encrypted' => false,
                'is_public' => false,
                'options' => null,
            ];
        }

        $repository = new SettingsRepository;
        (new ReflectionProperty(SettingsRepository::class, 'items'))->setValue($repository, $rows);

        $container = new Container;
        $container->instance(SettingsRepository::class, $repository);

        Container::setInstance($container);
    }
}
