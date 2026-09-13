<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;
use App\Support\Cms\ImageDerivative;
use App\Support\Cms\ImageProfile as ImageProfileSpec;

/**
 * Which derivative set was generated for an upload (`media_assets.profile`, phase-03 §2.13/§3) and
 * which one a media slot asks for (`SectionRegistry::mediaRoles()`).
 *
 * The numbers behind every method live in `App\Support\Cms\ImageProfile` — decision **D24**'s single
 * definition table (§6.8). This enum is the typed handle on them: the column cast, the `<select>`,
 * the type hint on `MediaService::store()`.
 *
 * When a file needs both, alias the table:
 *
 *     use App\Enums\Cms\ImageProfile;
 *     use App\Support\Cms\ImageProfile as ImageProfileSpec;
 */
enum ImageProfile: string
{
    use HasOptions;

    case Hero = 'hero';
    case Banner = 'banner';
    case Card = 'card';
    case Thumbnail = 'thumbnail';
    case Logo = 'logo';
    case Icon = 'icon';
    case Og = 'og';
    case VideoPoster = 'video_poster';

    public function label(): string
    {
        return (string) ImageProfileSpec::get($this->value)['label'];
    }

    public function color(): string
    {
        return match ($this) {
            self::Hero => 'indigo',
            self::Banner => 'violet',
            self::Card => 'cyan',
            self::Thumbnail => 'slate',
            self::Logo => 'emerald',
            self::Icon => 'sky',
            self::Og => 'amber',
            self::VideoPoster => 'rose',
        };
    }

    /**
     * One line for the field help text of an image picker.
     */
    public function description(): string
    {
        return (string) ImageProfileSpec::get($this->value)['description'];
    }

    /**
     * The derivative widths, ascending — the `srcset` of §6.8.
     *
     * @return list<int>
     */
    public function widths(): array
    {
        return ImageProfileSpec::widths($this->value);
    }

    /**
     * The widest derivative. An original narrower than this is never upscaled (§6.8).
     */
    public function maxWidth(): int
    {
        return ImageProfileSpec::maxWidth($this->value);
    }

    /**
     * The declared encoder quality. `website.image_quality` overrides it at generation time.
     */
    public function quality(): int
    {
        return ImageProfileSpec::quality($this->value);
    }

    /**
     * Is every derivative cropped to `aspect()`?
     */
    public function crop(): bool
    {
        return ImageProfileSpec::crop($this->value);
    }

    /**
     * `"16:9"` shaped, or null when nothing is cropped.
     */
    public function aspect(): ?string
    {
        return ImageProfileSpec::aspect($this->value);
    }

    /**
     * The `sizes` attribute `<x-site.image>` emits. Empty for `og`, which is never rendered in a
     * responsive `<img>`.
     */
    public function sizesAttribute(): string
    {
        return ImageProfileSpec::sizesAttribute($this->value);
    }

    /**
     * May an upload in this profile keep an alpha channel? True for `logo` and `icon`, which are
     * never flattened onto white (§6.8).
     */
    public function acceptsTransparency(): bool
    {
        return ImageProfileSpec::acceptsTransparency($this->value);
    }

    /**
     * The height of one derivative width, or null when the profile is not cropped.
     */
    public function heightFor(int $width): ?int
    {
        return ImageProfileSpec::heightFor($this->value, $width);
    }

    /**
     * Everything the pipeline has to produce for this profile.
     *
     * @param  int|null  $quality  `website.image_quality`, or null for the declared default
     * @param  bool  $webp  `website.image_webp_enabled`
     * @return list<ImageDerivative>
     */
    public function derivatives(?int $quality = null, bool $webp = true): array
    {
        return ImageProfileSpec::derivatives($this->value, $quality, $webp);
    }
}
