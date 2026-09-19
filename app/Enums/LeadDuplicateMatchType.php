<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why `LeadDuplicateDetector` thinks two contacts are the same (phase-05 §3, §6.2).
 *
 * Every comparison runs on the normalized columns (`*_normalized`, [D-P5-5] / D29). The first three cases compare
 * a lead field with the same field of another lead; `phone_vs_whatsapp` is the cross-field match
 * (`crm.duplicate_cross_field`); the four `client_*` cases match a client or a client contact
 * (`crm.duplicate_check_clients`). Stored on `lead_conversions.matched_by` and `lead_import_rows.duplicate_match_type`.
 */
enum LeadDuplicateMatchType: string
{
    use HasOptions;

    case Phone = 'phone';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case PhoneVsWhatsApp = 'phone_vs_whatsapp';
    case ClientPhone = 'client_phone';
    case ClientEmail = 'client_email';
    case ClientContactPhone = 'client_contact_phone';
    case ClientContactEmail = 'client_contact_email';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Same phone',
            self::WhatsApp => 'Same WhatsApp',
            self::Email => 'Same email',
            self::PhoneVsWhatsApp => 'Phone matches WhatsApp',
            self::ClientPhone => 'Client phone',
            self::ClientEmail => 'Client email',
            self::ClientContactPhone => 'Client contact phone',
            self::ClientContactEmail => 'Client contact email',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Phone, self::WhatsApp, self::Email => 'rose',
            self::PhoneVsWhatsApp => 'amber',
            self::ClientPhone, self::ClientEmail => 'violet',
            self::ClientContactPhone, self::ClientContactEmail => 'indigo',
        };
    }

    /**
     * A same-field match on a normalized value. Only the cross-field phone-vs-WhatsApp match is not exact;
     * `crm.duplicate_block_on_exact` refuses a save on an exact match only.
     */
    public function isExact(): bool
    {
        return $this !== self::PhoneVsWhatsApp;
    }

    /**
     * The match was found on a client or a client contact rather than on another lead.
     */
    public function againstClient(): bool
    {
        return in_array($this, [
            self::ClientPhone,
            self::ClientEmail,
            self::ClientContactPhone,
            self::ClientContactEmail,
        ], true);
    }

    /**
     * The contact field of the candidate that matched: `phone`, `whatsapp` or `email`.
     */
    public function field(): string
    {
        return match ($this) {
            self::Phone, self::PhoneVsWhatsApp, self::ClientPhone, self::ClientContactPhone => 'phone',
            self::WhatsApp => 'whatsapp',
            self::Email, self::ClientEmail, self::ClientContactEmail => 'email',
        };
    }
}
