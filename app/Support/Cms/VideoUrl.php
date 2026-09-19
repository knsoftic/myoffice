<?php

declare(strict_types=1);

namespace App\Support\Cms;

/**
 * The video-link allowlist of the review and story tables (phase-04 §6.9: `student_reviews.video_url`,
 * `success_stories.video_url`).
 *
 * Only YouTube and Vimeo are accepted, and a link is rendered as an embed **built from the parsed id** —
 * never as the stored string and never as user HTML — so a crafted URL can neither load another host
 * nor inject markup.
 */
final class VideoUrl
{
    public const PROVIDER_YOUTUBE = 'youtube';

    public const PROVIDER_VIMEO = 'vimeo';

    /** @var array<string, string> host => provider */
    private const HOSTS = [
        'youtube.com' => self::PROVIDER_YOUTUBE,
        'www.youtube.com' => self::PROVIDER_YOUTUBE,
        'm.youtube.com' => self::PROVIDER_YOUTUBE,
        'youtu.be' => self::PROVIDER_YOUTUBE,
        'vimeo.com' => self::PROVIDER_VIMEO,
        'www.vimeo.com' => self::PROVIDER_VIMEO,
        'player.vimeo.com' => self::PROVIDER_VIMEO,
    ];

    /**
     * An http(s) link on an allowlisted host that yields a video id.
     */
    public static function isAllowed(?string $url): bool
    {
        return self::parse($url) !== null;
    }

    /**
     * @return array{provider: string, id: string}|null
     */
    public static function parse(?string $url): ?array
    {
        $url = trim((string) $url);

        if ($url === '' || strlen($url) > 255) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $provider = self::HOSTS[$host] ?? null;
        $path = (string) ($parts['path'] ?? '');

        if ($provider === self::PROVIDER_YOUTUBE) {
            $id = null;

            if ($host === 'youtu.be') {
                $id = trim($path, '/');
            } elseif (preg_match('~^/(?:embed|shorts|live)/([^/]+)~', $path, $matches) === 1) {
                $id = $matches[1];
            } elseif ($path === '/watch') {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $id = is_string($query['v'] ?? null) ? $query['v'] : null;
            }

            return is_string($id) && preg_match('~^[A-Za-z0-9_-]{11}$~', $id) === 1
                ? ['provider' => self::PROVIDER_YOUTUBE, 'id' => $id]
                : null;
        }

        if ($provider === self::PROVIDER_VIMEO) {
            $pattern = $host === 'player.vimeo.com' ? '~^/video/(\d{1,12})/?$~' : '~^/(\d{1,12})/?$~';

            return preg_match($pattern, $path, $matches) === 1
                ? ['provider' => self::PROVIDER_VIMEO, 'id' => $matches[1]]
                : null;
        }

        return null;
    }

    /**
     * The iframe `src` for an allowed link (privacy-enhanced YouTube host), or null.
     */
    public static function embedUrl(?string $url): ?string
    {
        $video = self::parse($url);

        return match ($video['provider'] ?? null) {
            self::PROVIDER_YOUTUBE => 'https://www.youtube-nocookie.com/embed/'.$video['id'],
            self::PROVIDER_VIMEO => 'https://player.vimeo.com/video/'.$video['id'],
            default => null,
        };
    }
}
