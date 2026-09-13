<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;

/**
 * What an upload was uploaded *for* (`media_assets.collection`, phase-03 §2.13/§3).
 *
 * The collection is a **filter and a default, not a fence**: the media library of §8.13 filters by
 * it, `defaultProfile()` picks the derivative set when a screen does not name one, and any asset may
 * still be placed anywhere (one library, decision **D24**).
 *
 * `diskPath()` is the root directory every CMS upload lives under on the `public` disk. It is the
 * same for every collection **on purpose**: §6.8 fixes the stored path at
 * `cms/{Y}/{m}/{ulid}/{ulid}.{ext}`, so re-classifying an asset from `sections` to `pages` changes a
 * column and moves no file — and therefore never breaks a URL that is already in a published
 * snapshot or a cached page. The method exists so no caller spells the string out.
 */
enum MediaCollection: string
{
    use HasOptions;

    case Sections = 'sections';
    case Pages = 'pages';
    case Cta = 'cta';
    case Seo = 'seo';
    case Faq = 'faq';
    case General = 'general';

    /**
     * The root directory of the CMS media tree on the `public` disk (§6.8). `robots.txt` disallows
     * `/storage/cms/originals` from this root (§6.5).
     */
    public const ROOT = 'cms';

    public function label(): string
    {
        return match ($this) {
            self::Sections => 'Website sections',
            self::Pages => 'Pages',
            self::Cta => 'CTA blocks',
            self::Seo => 'Social previews',
            self::Faq => 'FAQs',
            self::General => 'General',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Sections => 'indigo',
            self::Pages => 'violet',
            self::Cta => 'amber',
            self::Seo => 'cyan',
            self::Faq => 'sky',
            self::General => 'slate',
        };
    }

    /**
     * The directory prefix on the `public` disk. One root for every collection — see the class note.
     */
    public function diskPath(): string
    {
        return self::ROOT;
    }

    /**
     * The derivative set an upload into this collection gets when no screen names one.
     */
    public function defaultProfile(): ImageProfile
    {
        return match ($this) {
            self::Sections => ImageProfile::Hero,
            self::Pages => ImageProfile::Banner,
            self::Cta => ImageProfile::Hero,
            self::Seo => ImageProfile::Og,
            self::Faq, self::General => ImageProfile::Card,
        };
    }
}
