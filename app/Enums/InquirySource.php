<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a contact, an application or a lead came from (phase-04 §3, F-5.3).
 *
 * **Phase 4 owns these eleven cases.** `contact_inquiries.source` and `job_applications.source` cast to
 * it here; phase-05 casts `leads.source` and `clients.source`, phase-14-17 casts
 * `course_inquiries.source`. `LeadSource` and `CourseInquirySource` do not exist — the backing strings
 * are identical, so no data migration was ever needed.
 */
enum InquirySource: string
{
    use HasOptions;

    case Website = 'website';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case Google = 'google';
    case WhatsApp = 'whatsapp';
    case Referral = 'referral';
    case WalkIn = 'walk_in';
    case Call = 'call';
    case Email = 'email';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Website => 'Website',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::TikTok => 'TikTok',
            self::Google => 'Google',
            self::WhatsApp => 'WhatsApp',
            self::Referral => 'Referral',
            self::WalkIn => 'Walk-in',
            self::Call => 'Call',
            self::Email => 'Email',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Website => 'brand',
            self::Facebook => 'indigo',
            self::Instagram => 'pink',
            self::TikTok => 'slate',
            self::Google => 'amber',
            self::WhatsApp => 'emerald',
            self::Referral => 'violet',
            self::WalkIn => 'teal',
            self::Call => 'cyan',
            self::Email => 'sky',
            self::Other => 'slate',
        };
    }

    /**
     * The case a `utm_source` value names, or null when it names none (§6.10.4: a `website` inquiry
     * with a recognisable `utm_source` is mapped onto the matching case; anything else stays `website`).
     *
     * Matching is case-insensitive on the backing value plus the common aliases ad platforms emit
     * (`fb`, `ig`, `wa`, `google-ads` …). The result is never `website` itself.
     */
    public static function fromUtmSource(?string $utmSource): ?self
    {
        $value = strtolower(trim((string) $utmSource));

        if ($value === '') {
            return null;
        }

        return match (true) {
            in_array($value, ['facebook', 'fb', 'facebook.com', 'm.facebook.com', 'meta'], true) => self::Facebook,
            in_array($value, ['instagram', 'ig', 'instagram.com'], true) => self::Instagram,
            in_array($value, ['tiktok', 'tiktok.com'], true) => self::TikTok,
            in_array($value, ['google', 'google-ads', 'google_ads', 'googleads', 'adwords', 'google.com'], true) => self::Google,
            in_array($value, ['whatsapp', 'wa', 'whatsapp.com'], true) => self::WhatsApp,
            in_array($value, ['referral', 'ref'], true) => self::Referral,
            in_array($value, ['email', 'newsletter', 'mailchimp'], true) => self::Email,
            default => null,
        };
    }
}
