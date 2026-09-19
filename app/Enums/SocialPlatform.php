<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The allowlist for the keys of `team_members.social_links` (phase-04 §3, §6.4 invariant 3).
 *
 * No column casts to this enum: the JSON map is keyed by these values, and an unknown key is a 422.
 * Every URL must be `http(s)` with a host; `urlPattern()` narrows it to the platform's own hosts where
 * that is meaningful (`website` accepts any host).
 */
enum SocialPlatform: string
{
    use HasOptions;

    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case LinkedIn = 'linkedin';
    case X = 'x_twitter';
    case GitHub = 'github';
    case YouTube = 'youtube';
    case TikTok = 'tiktok';
    case Behance = 'behance';
    case Dribbble = 'dribbble';
    case Website = 'website';

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::LinkedIn => 'LinkedIn',
            self::X => 'X (Twitter)',
            self::GitHub => 'GitHub',
            self::YouTube => 'YouTube',
            self::TikTok => 'TikTok',
            self::Behance => 'Behance',
            self::Dribbble => 'Dribbble',
            self::Website => 'Website',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Facebook, self::LinkedIn => 'indigo',
            self::Instagram, self::Dribbble => 'pink',
            self::X, self::GitHub, self::TikTok => 'slate',
            self::YouTube => 'rose',
            self::Behance => 'sky',
            self::Website => 'emerald',
        };
    }

    /**
     * The glyph key the public brand-icon set draws (`x-site.social-links` uses the same keys);
     * `website` falls back to the `globe-alt` interface icon.
     */
    public function icon(): string
    {
        return $this === self::Website ? 'globe-alt' : $this->value;
    }

    /**
     * A regex the profile URL must match, or null when any `http(s)` URL with a host is acceptable.
     */
    public function urlPattern(): ?string
    {
        return match ($this) {
            self::Facebook => '~^https?://([a-z0-9-]+\.)?(facebook\.com|fb\.com)/~i',
            self::Instagram => '~^https?://([a-z0-9-]+\.)?instagram\.com/~i',
            self::LinkedIn => '~^https?://([a-z0-9-]+\.)?linkedin\.com/~i',
            self::X => '~^https?://([a-z0-9-]+\.)?(x\.com|twitter\.com)/~i',
            self::GitHub => '~^https?://([a-z0-9-]+\.)?github\.com/~i',
            self::YouTube => '~^https?://([a-z0-9-]+\.)?(youtube\.com|youtu\.be)/~i',
            self::TikTok => '~^https?://([a-z0-9-]+\.)?tiktok\.com/~i',
            self::Behance => '~^https?://([a-z0-9-]+\.)?behance\.net/~i',
            self::Dribbble => '~^https?://([a-z0-9-]+\.)?dribbble\.com/~i',
            self::Website => null,
        };
    }
}
