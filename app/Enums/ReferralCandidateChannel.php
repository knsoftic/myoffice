<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a referral candidate came from, and which one wins (phase-08-09 §3.2, §6.3, requirement §37).
 *
 * The precedence ladder, top to bottom. {@see rank()} is the whole rule: **a staff member's explicit
 * selection outranks everything** (INV-R3), because the person at the desk can see the situation and the
 * cookie cannot. A typed code beats a remembered one for the same reason — somebody wrote it down.
 *
 * Below those two, the browser-supplied channels are ordered by how hard they are to forge: the session
 * the server itself set, then the signed cookie, then a hidden field, then a raw query parameter. None of
 * them is trusted as a **code**: they carry a visit token and the server re-resolves the code from
 * `collaborator_referral_visits` (INV-R2). A forged token can at worst name a visit that does not exist.
 *
 * {@see referralSource()} returns the spine's `collaborator_referrals.referral_source` **string**. The
 * spine's `ReferralSource` enum ships with Phase 10; returning its value now keeps this file from
 * redeclaring a spine enum (§3 preamble), and Phase 10 can add a typed accessor beside this one without
 * changing a stored value.
 */
enum ReferralCandidateChannel: string
{
    use HasOptions;

    case StaffSelection = 'staff_selection';
    case TypedCode = 'typed_code';
    case Session = 'session';
    case Cookie = 'cookie';
    case HiddenField = 'hidden_field';
    case QueryParam = 'query_param';

    public function label(): string
    {
        return match ($this) {
            self::StaffSelection => 'Chosen by staff',
            self::TypedCode => 'Code typed on the form',
            self::Session => 'Remembered in the session',
            self::Cookie => 'Remembered in a cookie',
            self::HiddenField => 'Carried by the form',
            self::QueryParam => 'From the link',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::StaffSelection => 'emerald',
            self::TypedCode => 'sky',
            self::Session, self::Cookie => 'indigo',
            self::HiddenField, self::QueryParam => 'slate',
        };
    }

    /**
     * 1 wins. The ladder of §6.3, in one place.
     */
    public function rank(): int
    {
        return match ($this) {
            self::StaffSelection => 1,
            self::TypedCode => 2,
            self::Session => 3,
            self::Cookie => 4,
            self::HiddenField => 5,
            self::QueryParam => 6,
        };
    }

    /**
     * The value stored in `collaborator_referrals.referral_source` (spine §2.8).
     */
    public function referralSource(): string
    {
        return match ($this) {
            self::StaffSelection => 'manual_selection',
            self::TypedCode => 'admission_form',
            default => 'referral_link',
        };
    }

    /**
     * Did this candidate arrive from the browser? Those are re-resolved server-side and never trusted as
     * a code (INV-R2).
     */
    public function isBrowserSupplied(): bool
    {
        return in_array($this, [self::Cookie, self::HiddenField, self::QueryParam], true);
    }

    /**
     * The channels in precedence order, for a resolver that wants to walk them.
     *
     * @return list<self>
     */
    public static function ladder(): array
    {
        $cases = self::cases();

        usort($cases, static fn (self $a, self $b): int => $a->rank() <=> $b->rank());

        return $cases;
    }
}
