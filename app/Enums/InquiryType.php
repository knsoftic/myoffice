<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What a public contact inquiry is about (phase-04 §3, `contact_inquiries.inquiry_type`).
 *
 * `routingTarget()` is **the** routing contract of §6.10: the only mapping from form input to an
 * `InquiryTarget` key. `service` → `crm_lead` (Phase 5), `course` → `course_inquiry` (Phase 14-17),
 * `general` → none (handled in the admin queue only).
 */
enum InquiryType: string
{
    use HasOptions;

    public const TARGET_CRM_LEAD = 'crm_lead';

    public const TARGET_COURSE_INQUIRY = 'course_inquiry';

    case Service = 'service';
    case Course = 'course';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Service',
            self::Course => 'Course',
            self::General => 'General',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Service => 'indigo',
            self::Course => 'sky',
            self::General => 'slate',
        };
    }

    /**
     * The `InquiryTarget::key()` this type routes to, or null when it routes nowhere.
     */
    public function routingTarget(): ?string
    {
        return match ($this) {
            self::Service => self::TARGET_CRM_LEAD,
            self::Course => self::TARGET_COURSE_INQUIRY,
            self::General => null,
        };
    }
}
