<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\DateRange;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Processors\Processor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `App\Support\DateRange` (phase-02 §3): the presets, `custom()`, `previous()` for the delta
 * comparison, and the conversion that makes a range built in the display timezone select the right
 * rows from columns stored in UTC (decision D61).
 *
 * A pure unit test. The clock is frozen at **Sunday 13 Sep 2026 14:05 UTC**, the PHP process
 * timezone is pinned to UTC (D61: storage is UTC and nothing changes it at runtime), and every
 * range names its timezone explicitly — so nothing here depends on a settings row, on the order
 * the suite runs in, or on the machine's own timezone.
 */
final class DateRangeTest extends TestCase
{
    private const NOW = '2026-09-13 14:05:00';

    private string $processTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        Container::setInstance(null);

        $this->processTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');

        $now = CarbonImmutable::parse(self::NOW, 'UTC');
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow($now);

        // PHP raises an E_WARNING from DateTimeImmutable::modify() before Carbon throws on an
        // unparseable string; inside the application Laravel's HandleExceptions turns it into an
        // ErrorException. The same conversion here keeps this pure unit test on the production path.
        set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0): bool {
            throw new \ErrorException($message, 0, $level, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        date_default_timezone_set($this->processTimezone);
        Container::setInstance(null);

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Presets
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function presetProvider(): array
    {
        return [
            'today' => [DateRange::TODAY, '2026-09-13 00:00:00', '2026-09-13 23:59:59', '1'],
            'yesterday' => [DateRange::YESTERDAY, '2026-09-12 00:00:00', '2026-09-12 23:59:59', '1'],
            'week (starts Monday by default)' => [DateRange::WEEK, '2026-09-07 00:00:00', '2026-09-13 23:59:59', '7'],
            'month' => [DateRange::MONTH, '2026-09-01 00:00:00', '2026-09-30 23:59:59', '30'],
            'quarter' => [DateRange::QUARTER, '2026-07-01 00:00:00', '2026-09-30 23:59:59', '92'],
            'year' => [DateRange::YEAR, '2026-01-01 00:00:00', '2026-12-31 23:59:59', '365'],
        ];
    }

    #[Test]
    #[DataProvider('presetProvider')]
    public function each_preset_covers_whole_days_in_its_timezone(string $preset, string $start, string $end, string $days): void
    {
        $range = DateRange::make($preset, null, null, 'Asia/Karachi');

        $this->assertSame($preset, $range->preset());
        $this->assertSame('Asia/Karachi', $range->timezone());
        $this->assertSame($start, $range->start()->toDateTimeString());
        $this->assertSame($end, $range->end()->toDateTimeString());
        $this->assertSame('Asia/Karachi', $range->start()->timezoneName);
        $this->assertSame((int) $days, $range->days());
        $this->assertCount((int) $days, $range->dateKeys());
    }

    #[Test]
    public function today_is_the_day_it_is_in_the_ranges_own_timezone(): void
    {
        // 14:05 UTC on the 13th: still the 13th in New York, already the 14th in Auckland.
        $this->assertSame('2026-09-13', DateRange::today('America/New_York')->start()->toDateString());
        $this->assertSame('2026-09-14', DateRange::today('Pacific/Auckland')->start()->toDateString());
    }

    #[Test]
    public function an_unknown_preset_falls_back_to_the_default_instead_of_failing(): void
    {
        foreach (['nonsense', '', 'MONTH ', 'decade'] as $preset) {
            $range = DateRange::make($preset, null, null, 'UTC');

            $this->assertSame(DateRange::DEFAULT_PRESET, $range->preset(), 'preset '.var_export($preset, true));
        }

        $this->assertSame(DateRange::DEFAULT_PRESET, DateRange::make('custom', 'garbage', 'junk', 'UTC')->preset());
    }

    /*
    |--------------------------------------------------------------------------
    | custom()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_custom_range_is_inclusive_and_swaps_reversed_ends(): void
    {
        $range = DateRange::custom('2026-09-07', '2026-09-01', 'UTC');

        $this->assertSame('2026-09-01 00:00:00', $range->start()->toDateTimeString());
        $this->assertSame('2026-09-07 23:59:59', $range->end()->toDateTimeString());
        $this->assertSame(7, $range->days());
        $this->assertTrue($range->isCustom());
        $this->assertSame(['range' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-07'], $range->queryParameters());

        $rebuilt = DateRange::make('custom', '2026-09-01', '2026-09-07', 'UTC');
        $this->assertTrue($rebuilt->equals($range), 'queryParameters() rebuild the exact same range.');
    }

    #[Test]
    public function a_custom_range_with_one_readable_end_is_a_single_day(): void
    {
        $range = DateRange::custom(null, '2026-09-05', 'UTC');

        $this->assertSame('2026-09-05', $range->start()->toDateString());
        $this->assertSame('2026-09-05', $range->end()->toDateString());
    }

    #[Test]
    public function a_custom_range_with_no_readable_end_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DateRange::custom('not a date', null, 'UTC');
    }

    /**
     * D61: `localization.timezone` is display-only, and DateRange "accepts input in it". A person
     * who picks 14 September from a date picker means 14 September where they are — whatever the
     * process timezone happens to be.
     *
     * @return array<string, array{0: string}>
     */
    public static function displayTimezoneProvider(): array
    {
        return [
            'Asia/Karachi (UTC+5)' => ['Asia/Karachi'],
            'Pacific/Auckland (UTC+12)' => ['Pacific/Auckland'],
            'America/New_York (UTC-4)' => ['America/New_York'],
            'America/Los_Angeles (UTC-7)' => ['America/Los_Angeles'],
            'Pacific/Honolulu (UTC-10)' => ['Pacific/Honolulu'],
        ];
    }

    #[Test]
    #[DataProvider('displayTimezoneProvider')]
    public function a_date_picked_in_the_display_timezone_is_that_day_in_that_timezone(string $timezone): void
    {
        $range = DateRange::custom('2026-09-14', '2026-09-14', $timezone);

        $this->assertSame(
            '2026-09-14',
            $range->start()->toDateString(),
            sprintf(
                'APP BUG: DateRange::custom("2026-09-14", …, "%s") starts on %s. A date-only input is parsed in the '
                .'process timezone (UTC) and only then moved into the range timezone, so every timezone west of UTC '
                .'selects the previous day. Parse the input in the range timezone (CarbonImmutable::parse($value, $tz)).',
                $timezone,
                $range->start()->toDateString(),
            ),
        );
        $this->assertSame('2026-09-14', $range->end()->toDateString());
        $this->assertSame(1, $range->days());
    }

    /*
    |--------------------------------------------------------------------------
    | previous()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function previous_steps_back_one_calendar_unit_for_calendar_presets(): void
    {
        $month = DateRange::month('UTC')->previous();
        $this->assertSame(['2026-08-01', '2026-08-31', DateRange::MONTH], [$month->start()->toDateString(), $month->end()->toDateString(), $month->preset()]);

        $quarter = DateRange::quarter('UTC')->previous();
        $this->assertSame(['2026-04-01', '2026-06-30'], [$quarter->start()->toDateString(), $quarter->end()->toDateString()]);

        $year = DateRange::year('UTC')->previous();
        $this->assertSame(['2025-01-01', '2025-12-31'], [$year->start()->toDateString(), $year->end()->toDateString()]);

        $week = DateRange::week('UTC')->previous();
        $this->assertSame(['2026-08-31', '2026-09-06'], [$week->start()->toDateString(), $week->end()->toDateString()]);
    }

    #[Test]
    public function the_month_before_a_31_day_month_is_the_whole_shorter_month(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-31 10:00:00', 'UTC'));

        $previous = DateRange::month('UTC')->previous();

        $this->assertSame('2026-02-01', $previous->start()->toDateString());
        $this->assertSame('2026-02-28', $previous->end()->toDateString(), 'No overflow into March.');
    }

    #[Test]
    public function today_steps_back_to_yesterday_and_then_to_a_dated_day(): void
    {
        $yesterday = DateRange::today('UTC')->previous();

        $this->assertSame(DateRange::YESTERDAY, $yesterday->preset());
        $this->assertSame('2026-09-12', $yesterday->start()->toDateString());

        $before = $yesterday->previous();
        $this->assertSame(DateRange::CUSTOM, $before->preset());
        $this->assertSame('2026-09-11', $before->start()->toDateString());
    }

    #[Test]
    public function a_custom_range_compares_against_the_same_number_of_days_immediately_before_it(): void
    {
        $range = DateRange::custom('2026-09-01', '2026-09-07', 'UTC');
        $previous = $range->previous();

        $this->assertSame('2026-08-25', $previous->start()->toDateString());
        $this->assertSame('2026-08-31', $previous->end()->toDateString());
        $this->assertSame($range->days(), $previous->days());
        $this->assertTrue($previous->end()->lessThan($range->start()), 'The two windows never overlap.');
    }

    #[Test]
    public function last_days_includes_today_and_has_no_gaps(): void
    {
        $range = DateRange::lastDays(14, 'UTC');
        $keys = $range->dateKeys();

        $this->assertCount(14, $keys);
        $this->assertSame('2026-08-31', $keys[0]);
        $this->assertSame('2026-09-13', $keys[13]);
        $this->assertSame(array_values(array_unique($keys)), $keys);

        $this->expectException(InvalidArgumentException::class);
        DateRange::lastDays(0, 'UTC');
    }

    /*
    |--------------------------------------------------------------------------
    | Storage conversion (D61)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_query_boundaries_are_the_display_day_expressed_in_utc(): void
    {
        $range = DateRange::custom('2026-09-14', '2026-09-14', 'Asia/Karachi');

        $this->assertSame('UTC', $range->storageStart()->timezoneName);
        $this->assertSame('2026-09-13 19:00:00', $range->storageStart()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-14 18:59:59', $range->storageEnd()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function apply_binds_utc_boundaries_and_apply_dates_binds_plain_dates(): void
    {
        $range = DateRange::custom('2026-09-14', '2026-09-14', 'Asia/Karachi');

        $query = $range->apply($this->queryBuilder(), 'created_at');

        $this->assertSame(['2026-09-13 19:00:00', '2026-09-14 18:59:59'], $query->getBindings());
        $this->assertStringContainsString('between', strtolower($query->toSql()));

        $dates = $range->applyDates($this->queryBuilder(), 'due_on');

        $this->assertSame(['2026-09-14', '2026-09-14'], $dates->getBindings(), 'A DATE column is compared to dates, not converted.');
    }

    #[Test]
    public function contains_judges_a_utc_moment_against_the_display_day(): void
    {
        $range = DateRange::custom('2026-09-14', '2026-09-14', 'Asia/Karachi');

        $this->assertTrue($range->contains(CarbonImmutable::parse('2026-09-13 21:30:00', 'UTC')), '21:30 UTC is 02:30 on the 14th in Karachi.');
        $this->assertFalse($range->contains(CarbonImmutable::parse('2026-09-13 18:59:59', 'UTC')), '18:59:59 UTC is still the 13th in Karachi.');
        $this->assertTrue($range->contains(CarbonImmutable::parse('2026-09-14 18:59:59', 'UTC')));
        $this->assertFalse($range->contains(CarbonImmutable::parse('2026-09-14 19:00:00', 'UTC')));
        $this->assertFalse($range->contains(null));
    }

    #[Test]
    public function to_array_reports_the_range_the_way_a_widget_response_does(): void
    {
        $array = DateRange::month('Asia/Karachi')->toArray();

        $this->assertSame(['preset', 'from', 'to', 'label', 'days', 'timezone'], array_keys($array));
        $this->assertSame('2026-09-01', $array['from']);
        $this->assertSame('2026-09-30', $array['to']);
        $this->assertSame('September 2026', $array['label']);
        $this->assertSame(30, $array['days']);
        $this->assertSame('Asia/Karachi', $array['timezone']);
    }

    private function queryBuilder(): Builder
    {
        $connection = $this->createMock(ConnectionInterface::class);

        return new Builder($connection, new MySqlGrammar($this->createStub(Connection::class)), new Processor);
    }
}
