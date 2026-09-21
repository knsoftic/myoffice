<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How somebody was contacted about an enquiry (`course_inquiry_follow_ups.channel`, §68).
 *
 * A select rather than free text because "we called twice and WhatsApped once" is a question the
 * §88 report is asked, and free text cannot be counted.
 */
enum FollowUpChannel: string
{
    use HasOptions;

    case Call = 'call';
    case Whatsapp = 'whatsapp';
    case Sms = 'sms';
    case Email = 'email';
    case InPerson = 'in_person';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Phone call',
            self::Whatsapp => 'WhatsApp',
            self::Sms => 'SMS',
            self::Email => 'Email',
            self::InPerson => 'In person',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Call => 'sky',
            self::Whatsapp => 'emerald',
            self::Sms => 'indigo',
            self::Email => 'violet',
            self::InPerson => 'amber',
            self::Other => 'slate',
        };
    }

    /** The icon the timeline puts in front of the row. */
    public function icon(): string
    {
        return match ($this) {
            self::Call => 'phone',
            self::Whatsapp => 'chat-bubble-left-right',
            self::Sms => 'device-phone-mobile',
            self::Email => 'envelope',
            self::InPerson => 'user',
            self::Other => 'ellipsis-horizontal',
        };
    }
}
