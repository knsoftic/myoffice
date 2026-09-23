<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How often a person wants to be emailed (phase-19-23 §3.4, §6.19, requirement §97).
 *
 * **This governs the mail channel only.** The `database` row is always written — that is what the
 * bell reads, and a preference that could switch it off would be a preference to lose the record of
 * having been told. `off` means "do not email me about this"; it never means "do not tell me".
 *
 * **A `mandatory` event ignores all three** ([D-22-2]). There are a small number of things somebody
 * does not get to opt out of being emailed about — a password changed, an account suspended — and
 * `NotificationPreferenceService::update()` refuses the attempt rather than accepting it and quietly
 * not honouring it.
 */
enum NotificationDigest: string
{
    use HasOptions;

    /** Email as it happens. */
    case Immediate = 'immediate';

    /** One email a day with everything in it. */
    case Daily = 'daily';

    /** No email. The bell still gets it. */
    case Off = 'off';

    public function label(): string
    {
        return match ($this) {
            self::Immediate => 'Email me straight away',
            self::Daily => 'One email a day',
            self::Off => 'No email',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Immediate => 'sky',
            self::Daily => 'indigo',
            self::Off => 'slate',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Immediate => 'An email each time.',
            self::Daily => 'Everything from the day, in one message.',
            self::Off => 'Nothing by email. It still appears in your notifications here.',
        };
    }

    /** Does this one wait for the digest run rather than sending now? */
    public function isBatched(): bool
    {
        return $this === self::Daily;
    }

    /** Does this one send mail at all? */
    public function sendsMail(): bool
    {
        return $this !== self::Off;
    }
}
