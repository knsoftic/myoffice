<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The kind of contact a scheduled follow-up plans (phase-05 §3, `lead_follow_ups.type`).
 */
enum LeadFollowUpType: string
{
    use HasOptions;

    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Meeting = 'meeting';
    case Visit = 'visit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::Meeting => 'Meeting',
            self::Visit => 'Visit',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Call => 'cyan',
            self::WhatsApp => 'emerald',
            self::Email => 'sky',
            self::Meeting => 'violet',
            self::Visit => 'amber',
            self::Other => 'slate',
        };
    }

    /**
     * An icon name `<x-ui.icon>` can draw (resources/data/icons.php).
     */
    public function icon(): string
    {
        return match ($this) {
            self::Call => 'phone',
            self::WhatsApp => 'whatsapp',
            self::Email => 'envelope',
            self::Meeting => 'video-camera',
            self::Visit => 'briefcase',
            self::Other => 'calendar',
        };
    }
}
