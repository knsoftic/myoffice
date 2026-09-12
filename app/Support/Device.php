<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lightweight user-agent parsing — plain regex, no package.
 *
 * Feeds the `device`, `platform` and `browser` columns on `login_histories` and `activity_log`
 * (all string(64), so every value is clamped to 64 characters).
 *
 *   Device::parse($request->userAgent());
 *   // ['device' => 'desktop', 'platform' => 'Windows 10', 'browser' => 'Chrome 131']
 */
final class Device
{
    public const DESKTOP = 'desktop';

    public const MOBILE = 'mobile';

    public const TABLET = 'tablet';

    public const BOT = 'bot';

    public const UNKNOWN = 'unknown';

    private const MAX_LENGTH = 64;

    /** Crawlers, monitors and CLI clients. */
    private const BOTS = '/(bot\b|bot\/|crawler|crawl|spider|slurp|mediapartners|adsbot|bingpreview|facebookexternalhit|whatsapp|telegrambot|twitterbot|linkedinbot|slackbot|discordbot|embedly|pingdom|uptimerobot|headlesschrome|phantomjs|puppeteer|playwright|selenium|curl\/|wget\/|python-requests|python-urllib|go-http-client|java\/|okhttp|axios\/|guzzlehttp|postmanruntime|insomnia|apache-httpclient|lighthouse|ahrefs|semrush|mj12bot|dotbot|petalbot|yandex|baiduspider|duckduckbot|applebot|sogou|exabot|gtmetrix)/i';

    /** Tablets must be tested before phones: many tablets also say "Android". */
    private const TABLETS = '/(ipad|android(?!.*mobi)|tablet|kindle|silk\/|playbook|rim tablet|sm-t\d|gt-p\d|nexus (?:7|9|10)|pixel tablet|surface|kfapwi|transformer)/i';

    private const MOBILES = '/(mobile|iphone|ipod|android.*mobi|blackberry|bb10|iemobile|opera mini|opera mobi|windows phone|webos|palm|symbian|series60|nokia|fennec|minimo|up\.browser|netfront|wap\b|midp|j2me|huawei|xiaomi|redmi|oppo|vivo|realme|infinix|tecno|itel)/i';

    /**
     * @return array{device: string, platform: string, browser: string}
     */
    public static function parse(?string $userAgent): array
    {
        $agent = trim((string) $userAgent);

        if ($agent === '') {
            return [
                'device' => self::UNKNOWN,
                'platform' => self::UNKNOWN,
                'browser' => self::UNKNOWN,
            ];
        }

        return [
            'device' => self::device($agent),
            'platform' => self::clamp(self::platform($agent)),
            'browser' => self::clamp(self::browser($agent)),
        ];
    }

    /**
     * desktop | mobile | tablet | bot | unknown
     */
    public static function device(string $userAgent): string
    {
        $agent = trim($userAgent);

        if ($agent === '') {
            return self::UNKNOWN;
        }

        if (preg_match(self::BOTS, $agent) === 1) {
            return self::BOT;
        }

        if (preg_match(self::TABLETS, $agent) === 1) {
            return self::TABLET;
        }

        if (preg_match(self::MOBILES, $agent) === 1) {
            return self::MOBILE;
        }

        return self::DESKTOP;
    }

    public static function isBot(?string $userAgent): bool
    {
        return self::device((string) $userAgent) === self::BOT;
    }

    /**
     * Operating system, with a version where one is cheap to read.
     */
    public static function platform(string $userAgent): string
    {
        $agent = $userAgent;

        if (preg_match('/windows phone(?: os)? ([\d.]+)/i', $agent, $m) === 1) {
            return 'Windows Phone '.$m[1];
        }

        if (preg_match('/windows nt ([\d.]+)/i', $agent, $m) === 1) {
            return match ($m[1]) {
                '10.0' => 'Windows 10/11',
                '6.3' => 'Windows 8.1',
                '6.2' => 'Windows 8',
                '6.1' => 'Windows 7',
                '6.0' => 'Windows Vista',
                '5.2', '5.1' => 'Windows XP',
                default => 'Windows NT '.$m[1],
            };
        }

        if (stripos($agent, 'windows') !== false) {
            return 'Windows';
        }

        if (preg_match('/(?:iphone|ipad|ipod).*?os ([\d_]+)/i', $agent, $m) === 1) {
            return 'iOS '.str_replace('_', '.', $m[1]);
        }

        if (preg_match('/\b(?:iphone|ipad|ipod)\b/i', $agent) === 1) {
            return 'iOS';
        }

        if (preg_match('/android[ \/]?([\d.]+)?/i', $agent, $m) === 1) {
            return isset($m[1]) && $m[1] !== '' ? 'Android '.$m[1] : 'Android';
        }

        if (stripos($agent, 'cros') !== false) {
            return 'Chrome OS';
        }

        if (preg_match('/mac os x ([\d_\.]+)/i', $agent, $m) === 1) {
            return 'macOS '.str_replace('_', '.', $m[1]);
        }

        if (stripos($agent, 'mac os') !== false || stripos($agent, 'macintosh') !== false) {
            return 'macOS';
        }

        foreach ([
            'ubuntu' => 'Ubuntu',
            'fedora' => 'Fedora',
            'debian' => 'Debian',
            'centos' => 'CentOS',
            'freebsd' => 'FreeBSD',
            'openbsd' => 'OpenBSD',
            'linux' => 'Linux',
            'blackberry' => 'BlackBerry',
            'bb10' => 'BlackBerry',
            'webos' => 'webOS',
            'symbian' => 'Symbian',
        ] as $needle => $label) {
            if (stripos($agent, $needle) !== false) {
                return $label;
            }
        }

        return self::UNKNOWN;
    }

    /**
     * Browser, with a major version where one is cheap to read. Order matters: the Chromium
     * derivatives all claim to be Chrome, and Chrome claims to be Safari.
     */
    public static function browser(string $userAgent): string
    {
        $agent = $userAgent;

        $candidates = [
            'Edge' => '/edge?(?:a|ios)?\/([\d.]+)/i',
            'Opera Mini' => '/opera mini\/([\d.]+)/i',
            'Opera' => '/(?:opr|opios)\/([\d.]+)/i',
            'Vivaldi' => '/vivaldi\/([\d.]+)/i',
            'Brave' => '/brave\/([\d.]+)/i',
            'Yandex Browser' => '/yabrowser\/([\d.]+)/i',
            'Samsung Internet' => '/samsungbrowser\/([\d.]+)/i',
            'UC Browser' => '/(?:ucbrowser|ubrowser)\/([\d.]+)/i',
            'Firefox' => '/(?:firefox|fxios)\/([\d.]+)/i',
            'Chrome' => '/(?:chrome|crios|chromium)\/([\d.]+)/i',
            'Safari' => '/version\/([\d.]+).*safari/i',
            'Internet Explorer' => '/(?:msie |rv:)([\d.]+).*(?:trident|msie)/i',
        ];

        // Legacy Opera: "Opera/9.80 ... Version/12.16".
        if (preg_match('/^opera\//i', $agent) === 1) {
            $version = preg_match('/version\/([\d.]+)/i', $agent, $m) === 1 ? $m[1] : null;

            return self::withVersion('Opera', $version);
        }

        if (preg_match('/trident\/.*rv:([\d.]+)/i', $agent, $m) === 1) {
            return self::withVersion('Internet Explorer', $m[1]);
        }

        foreach ($candidates as $name => $pattern) {
            if (preg_match($pattern, $agent, $m) === 1) {
                return self::withVersion($name, $m[1] ?? null);
            }
        }

        if (stripos($agent, 'safari') !== false) {
            return 'Safari';
        }

        // Last resort: the leading "Product/Version" token, unless it is the meaningless "Mozilla/5.0".
        if (preg_match('/^([a-z0-9\-_. ]+)\/([\d.]+)/i', $agent, $m) === 1
            && strcasecmp(trim($m[1]), 'mozilla') !== 0) {
            return self::withVersion(trim($m[1]), $m[2]);
        }

        return self::UNKNOWN;
    }

    /**
     * "Chrome" + "131.0.6778.86" => "Chrome 131".
     */
    private static function withVersion(string $name, ?string $version): string
    {
        if ($version === null || $version === '') {
            return $name;
        }

        $major = explode('.', $version)[0];

        return $major === '' ? $name : $name.' '.$major;
    }

    private static function clamp(string $value): string
    {
        if ($value === '') {
            return self::UNKNOWN;
        }

        return mb_substr($value, 0, self::MAX_LENGTH);
    }
}
