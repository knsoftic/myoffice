<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Format;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTime;
use DateTimeZone;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `App\Support\Format` and the `app_date()` / `app_time()` / `app_datetime()` / `app_number()` /
 * `money()` helpers, on their documented fallbacks (phase-02 §3).
 *
 * A pure unit test: with no container the settings are unreachable, which is exactly the install-time
 * path Format promises to survive — `d M Y`, `h:i A`, UTC, `,` and `.`, Rupees first. That the
 * *settings* then change this output is proved end-to-end by
 * `Tests\Feature\Settings\LocalizationFormattingTest`; here the rendering rules themselves are pinned:
 * any date-ish input, any number-ish input, no float ever reaching the output, and a UTC moment shown
 * in whichever display timezone is asked for (D61).
 */
final class FormatTest extends TestCase
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

    #[Test]
    public function the_fallbacks_are_the_documented_ones(): void
    {
        $this->assertSame(Format::FALLBACK_DATE_FORMAT, Format::dateFormat());
        $this->assertSame(Format::FALLBACK_TIME_FORMAT, Format::timeFormat());
        $this->assertSame('d M Y h:i A', Format::dateTimeFormat());
        $this->assertSame('UTC', Format::timezone());
        $this->assertSame('UTC', Format::displayTimezone());
        $this->assertSame('monday', Format::weekStart());
        $this->assertSame(',', Format::thousandSeparator());
        $this->assertSame('.', Format::decimalSeparator());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function sameMomentProvider(): array
    {
        return [
            'CarbonImmutable' => [CarbonImmutable::parse('2026-09-13 14:05:00', 'UTC')],
            'Carbon' => [Carbon::parse('2026-09-13 14:05:00', 'UTC')],
            'DateTime in another zone' => [new DateTime('2026-09-13 19:05:00', new DateTimeZone('Asia/Karachi'))],
            'string' => ['2026-09-13 14:05:00'],
            'ISO-8601 string with offset' => ['2026-09-13T19:05:00+05:00'],
            'unix timestamp' => [1789308300],
        ];
    }

    #[Test]
    #[DataProvider('sameMomentProvider')]
    public function every_date_shape_renders_the_same_moment(mixed $value): void
    {
        $this->assertSame('13 Sep 2026', app_date($value));
        $this->assertSame('02:05 PM', app_time($value));
        $this->assertSame('13 Sep 2026 02:05 PM', app_datetime($value));
        $this->assertSame('2026-09-13 14:05', Format::dateTime($value, 'Y-m-d H:i'), 'An explicit format wins.');
    }

    #[Test]
    public function nothing_to_show_renders_empty_and_never_throws(): void
    {
        // PHP raises an E_WARNING from DateTimeImmutable::modify() before Carbon throws on an
        // unparseable string. Inside the application Laravel's HandleExceptions turns that warning into
        // an ErrorException; the same conversion is installed here so this pure unit test exercises the
        // production path rather than PHPUnit's warning collector.
        set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0): bool {
            throw new \ErrorException($message, 0, $level, $file, $line);
        });

        try {
            foreach ([null, '', 'not a date at all', '2026-13-45 99:99'] as $value) {
                $this->assertSame('', app_date($value), var_export($value, true));
                $this->assertSame('', app_time($value), var_export($value, true));
                $this->assertSame('', app_datetime($value), var_export($value, true));
            }
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function a_utc_moment_is_rendered_in_the_display_timezone_asked_for(): void
    {
        // 21:30 UTC on the 13th — already 02:30 on the 14th in Karachi (D61: stored UTC, shown local).
        $stored = '2026-09-13 21:30:00';

        $this->assertSame('2026-09-14 02:30:00', Format::carbon($stored, 'Asia/Karachi')?->toDateTimeString());
        $this->assertSame('2026-09-13 17:30:00', Format::carbon($stored, 'America/New_York')?->toDateTimeString());
        $this->assertSame('Asia/Karachi', Format::carbon($stored, 'Asia/Karachi')?->timezoneName);

        $this->assertSame('13 Sep 2026 09:30 PM', app_datetime($stored), 'With no display timezone configured, UTC is shown.');
    }

    #[Test]
    public function a_date_is_never_mutated_by_being_formatted(): void
    {
        $moment = Carbon::parse('2026-09-13 21:30:00', 'UTC');

        Format::carbon($moment, 'Asia/Karachi');
        app_datetime($moment);

        $this->assertSame('UTC', $moment->timezoneName);
        $this->assertSame('2026-09-13 21:30:00', $moment->toDateTimeString());
    }

    /**
     * @return array<string, array{0: string|int|float|null, 1: int, 2: string}>
     */
    public static function numberProvider(): array
    {
        return [
            'an integer' => [1248, 0, '1,248'],
            'millions' => [1234567, 0, '1,234,567'],
            'a numeric string' => ['1234567.891', 2, '1,234,567.89'],
            'rounds half away from zero' => ['2.5', 0, '3'],
            'rounds negative half away from zero' => ['-2.5', 0, '-3'],
            'a float without scientific notation' => [1.0E+20, 0, '100,000,000,000,000,000,000'],
            'a tiny negative rounds to plain zero' => [-0.004, 2, '0.00'],
            'separators in the input' => ['1,250.5', 1, '1,250.5'],
            'exponent notation is not a number here' => ['1e3', 0, '0'],
            'garbage is zero' => ['twelve', 0, '0'],
            'null is zero' => [null, 2, '0.00'],
            'NaN is zero' => [NAN, 0, '0'],
            'infinity is zero' => [INF, 0, '0'],
            'negative decimals are treated as none' => ['12.75', -3, '13'],
        ];
    }

    #[Test]
    #[DataProvider('numberProvider')]
    public function numbers_are_grouped_and_rounded_without_floats(string|int|float|null $value, int $decimals, string $expected): void
    {
        $this->assertSame($expected, app_number($value, $decimals));
    }

    #[Test]
    public function a_percentage_is_a_number_with_a_percent_sign(): void
    {
        $this->assertSame('12.50%', Format::percentage('12.5'));
        $this->assertSame('100.00%', Format::percentage('100.0000'));
        $this->assertSame('7.1250%', Format::percentage('7.125', 4));
    }

    #[Test]
    public function money_goes_through_the_one_money_formatter(): void
    {
        $this->assertSame('Rs 1,234,567.89', money('1234567.891'));
        $this->assertSame('-Rs 1,250.50', money('-1250.5'));
        $this->assertSame('1,250.50', money('1250.5', false));
        $this->assertSame('Rs 0.00', money(null), 'An empty decimal column formats as zero.');
        $this->assertSame(money('99.99'), Format::money('99.99'));
    }
}
