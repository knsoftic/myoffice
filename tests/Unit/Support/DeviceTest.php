<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Device;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * App\Support\Device — the user-agent parse behind `login_histories.device/platform/browser` and
 * the session-management screen (phase-01 §1.6, §3).
 *
 * Plain regex, no package, and every value clamped to 64 characters because that is the column
 * width. A pure unit test: the class touches nothing but the string it is handed.
 */
final class DeviceTest extends TestCase
{
    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    private const IPAD = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/604.1';

    private const ANDROID_PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36';

    private const ANDROID_TABLET = 'Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    private const EDGE = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.2903.70';

    private const FIREFOX_UBUNTU = 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0';

    private const SAFARI_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15';

    private const INTERNET_EXPLORER = 'Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko';

    /*
    |--------------------------------------------------------------------------
    | Nothing to parse
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_missing_user_agent_parses_to_unknown(): void
    {
        foreach ([null, '', '   '] as $agent) {
            $this->assertSame(
                ['device' => 'unknown', 'platform' => 'unknown', 'browser' => 'unknown'],
                Device::parse($agent),
            );
        }

        $this->assertSame(Device::UNKNOWN, Device::device(''));
        $this->assertFalse(Device::isBot(null));
    }

    /*
    |--------------------------------------------------------------------------
    | Full parses
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function userAgentProvider(): array
    {
        return [
            'Chrome on Windows' => [self::CHROME_WINDOWS, Device::DESKTOP, 'Windows 10/11', 'Chrome 131'],
            'Safari on iPhone' => [self::IPHONE, Device::MOBILE, 'iOS 17.0', 'Safari 17'],
            'Safari on iPad' => [self::IPAD, Device::TABLET, 'iOS 17.0', 'Safari 17'],
            'Chrome on an Android phone' => [self::ANDROID_PHONE, Device::MOBILE, 'Android 14', 'Chrome 131'],
            'Chrome on an Android tablet' => [self::ANDROID_TABLET, Device::TABLET, 'Android 13', 'Chrome 131'],
            'Edge on Windows' => [self::EDGE, Device::DESKTOP, 'Windows 10/11', 'Edge 131'],
            'Firefox on Ubuntu' => [self::FIREFOX_UBUNTU, Device::DESKTOP, 'Ubuntu', 'Firefox 133'],
            'Safari on macOS' => [self::SAFARI_MAC, Device::DESKTOP, 'macOS 10.15.7', 'Safari 18'],
            'Internet Explorer 11' => [self::INTERNET_EXPLORER, Device::DESKTOP, 'Windows 7', 'Internet Explorer 11'],
        ];
    }

    #[Test]
    #[DataProvider('userAgentProvider')]
    public function a_real_user_agent_is_parsed_into_all_three_columns(
        string $agent,
        string $device,
        string $platform,
        string $browser,
    ): void {
        $this->assertSame(
            ['device' => $device, 'platform' => $platform, 'browser' => $browser],
            Device::parse($agent),
        );
    }

    /**
     * Tablets must be tested before phones, because many tablets also say "Android".
     */
    #[Test]
    public function an_android_tablet_is_not_mistaken_for_a_phone(): void
    {
        $this->assertSame(Device::TABLET, Device::device(self::ANDROID_TABLET));
        $this->assertSame(Device::MOBILE, Device::device(self::ANDROID_PHONE));
    }

    /**
     * Chromium derivatives all claim to be Chrome, and Chrome claims to be Safari — order matters.
     */
    #[Test]
    public function a_chromium_derivative_is_not_reported_as_chrome(): void
    {
        $this->assertSame('Edge 131', Device::browser(self::EDGE));
        $this->assertSame('Chrome 131', Device::browser(self::CHROME_WINDOWS));

        $this->assertSame(
            'Opera 115',
            Device::browser('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 OPR/115.0.0.0'),
        );

        $this->assertSame(
            'Samsung Internet 27',
            Device::browser('Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/27.0 Chrome/125.0.0.0 Mobile Safari/537.36'),
        );

        $this->assertSame(
            'Brave 131',
            Device::browser('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Brave/131.0.0.0'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Bots
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string}>
     */
    public static function botProvider(): array
    {
        return [
            'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'Bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            'curl' => ['curl/8.4.0'],
            'wget' => ['Wget/1.21.3'],
            'python' => ['python-requests/2.31.0'],
            'Guzzle' => ['GuzzleHttp/7'],
            'Postman' => ['PostmanRuntime/7.36.0'],
            'headless Chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/131.0.0.0 Safari/537.36'],
            'uptime monitor' => ['Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)'],
        ];
    }

    #[Test]
    #[DataProvider('botProvider')]
    public function a_crawler_or_cli_client_is_reported_as_a_bot(string $agent): void
    {
        $this->assertSame(Device::BOT, Device::device($agent));
        $this->assertTrue(Device::isBot($agent));
        $this->assertSame(Device::BOT, Device::parse($agent)['device']);
    }

    #[Test]
    public function a_real_browser_is_not_reported_as_a_bot(): void
    {
        foreach ([self::CHROME_WINDOWS, self::IPHONE, self::FIREFOX_UBUNTU, self::SAFARI_MAC] as $agent) {
            $this->assertFalse(Device::isBot($agent), $agent);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Column widths and fallbacks
    |--------------------------------------------------------------------------
    */

    /**
     * `login_histories.platform` and `.browser` are string(64); an absurd user agent must be
     * clamped rather than blow up the insert.
     */
    #[Test]
    public function the_parsed_values_are_clamped_to_the_column_width(): void
    {
        $agent = str_repeat('a', 200).'/1.0';

        $parsed = Device::parse($agent);

        $this->assertLessThanOrEqual(64, mb_strlen($parsed['browser']));
        $this->assertLessThanOrEqual(64, mb_strlen($parsed['platform']));
        $this->assertLessThanOrEqual(64, mb_strlen($parsed['device']));
    }

    #[Test]
    public function an_unrecognised_agent_degrades_to_unknown_rather_than_guessing(): void
    {
        $parsed = Device::parse('Mozilla/5.0');

        $this->assertSame(Device::DESKTOP, $parsed['device']);
        $this->assertSame('unknown', $parsed['platform']);
        $this->assertSame('unknown', $parsed['browser'], '"Mozilla/5.0" carries no information at all.');
    }

    #[Test]
    public function a_browser_without_a_version_is_still_named(): void
    {
        $this->assertSame('Safari', Device::browser('Mozilla/5.0 (Macintosh) Safari'));
        $this->assertSame('iOS', Device::platform('Mozilla/5.0 (iPhone) Safari'));
        $this->assertSame('Android', Device::platform('Mozilla/5.0 (Linux; Android) Mobile'));
    }

    /**
     * Every value the class can return for `device` is one of its own constants, which is what the
     * session screen and the login-history filter render.
     */
    #[Test]
    public function device_only_ever_returns_one_of_its_constants(): void
    {
        $allowed = [Device::DESKTOP, Device::MOBILE, Device::TABLET, Device::BOT, Device::UNKNOWN];

        $agents = array_merge(
            array_column(self::userAgentProvider(), 0),
            array_column(self::botProvider(), 0),
            ['', 'nonsense', 'Mozilla/5.0'],
        );

        foreach ($agents as $agent) {
            $this->assertContains(Device::device((string) $agent), $allowed, (string) $agent);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function platformProvider(): array
    {
        return [
            'Windows 10/11' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'Windows 10/11'],
            'Windows 8.1' => ['Mozilla/5.0 (Windows NT 6.3; WOW64)', 'Windows 8.1'],
            'Windows 7' => ['Mozilla/5.0 (Windows NT 6.1; WOW64)', 'Windows 7'],
            'Windows XP' => ['Mozilla/5.0 (Windows NT 5.1)', 'Windows XP'],
            'Chrome OS' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0)', 'Chrome OS'],
            'Linux' => ['Mozilla/5.0 (X11; Linux x86_64)', 'Linux'],
            'Fedora' => ['Mozilla/5.0 (X11; Fedora; Linux x86_64)', 'Fedora'],
            'macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 13_5)', 'macOS 13.5'],
        ];
    }

    #[Test]
    #[DataProvider('platformProvider')]
    public function the_operating_system_is_read_with_its_version_where_one_is_cheap(string $agent, string $expected): void
    {
        $this->assertSame($expected, Device::platform($agent));
    }
}
