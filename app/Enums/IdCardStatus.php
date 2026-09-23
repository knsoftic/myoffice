<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The state of a student's ID card (phase-19-23 §3.3, requirement §85).
 *
 * **Six cases, and five of them are reasons the card is not in use.** A card is a physical object a
 * student carries: it expires, it gets lost, it goes through the wash, it gets replaced, and very
 * occasionally it is withdrawn. Collapsing those into `inactive` would lose exactly the fact the
 * office needs — whether to charge for a replacement, whether to expect the old card back, and
 * whether the old number should still open a door.
 *
 * **`replaced` is what a superseded card becomes**, the same shape as [D-21-3]: the old card is
 * `replaced` and a new row is issued, rather than one row claiming to be both. What makes a card
 * replaceable is `isReplaceable()`, and a revoked card is deliberately not — a card withdrawn for
 * cause is not reissued by asking nicely.
 */
enum IdCardStatus: string
{
    use HasOptions;

    /** In the student's hand and valid. */
    case Active = 'active';

    /** Past its `valid_until`. Replaceable. */
    case Expired = 'expired';

    /** The student says it is gone. Replaceable, usually for a fee. */
    case Lost = 'lost';

    /** Unreadable or broken. Replaceable, and the old one comes back. */
    case Damaged = 'damaged';

    /** Superseded by a newer card. Not replaceable — the replacement already exists. */
    case Replaced = 'replaced';

    /** Withdrawn for cause. Not replaceable by this route. */
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Lost => 'Reported lost',
            self::Damaged => 'Damaged',
            self::Replaced => 'Replaced',
            self::Revoked => 'Revoked',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'emerald',
            self::Expired => 'amber',
            self::Lost => 'orange',
            self::Damaged => 'orange',
            self::Replaced => 'slate',
            self::Revoked => 'rose',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Active => 'Valid and in use.',
            self::Expired => 'Past its expiry date. A replacement can be issued.',
            self::Lost => 'Reported lost by the student. A replacement can be issued.',
            self::Damaged => 'Unreadable or broken. A replacement can be issued.',
            self::Replaced => 'Superseded by a newer card. The newer one is the live card.',
            self::Revoked => 'Withdrawn. A new card is not issued by replacing this one.',
        };
    }

    /** The only status that opens a door or proves anything. */
    public function isUsable(): bool
    {
        return $this === self::Active;
    }

    /**
     * May a replacement be issued against this card?
     *
     * Not `replaced` — the replacement is already there, and allowing a second would produce two
     * successors for one card. Not `revoked` — that is a decision, not an accident.
     */
    public function isReplaceable(): bool
    {
        return match ($this) {
            self::Expired, self::Lost, self::Damaged => true,
            self::Active, self::Replaced, self::Revoked => false,
        };
    }

    /** Nothing further happens to a card in one of these states. */
    public function isTerminal(): bool
    {
        return $this === self::Replaced || $this === self::Revoked;
    }
}
