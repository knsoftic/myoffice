<?php

declare(strict_types=1);

namespace App\Services\Collaborator\Exceptions;

/**
 * A referral code that something already points at was asked to change (phase-08-09 INV-C2).
 *
 * A code is snapshotted onto every attribution row the moment it is used. Changing it afterwards would
 * leave those rows naming a code that no longer means what they say it means, and the one place that
 * matters is a commission dispute months later.
 *
 * The remedy is not a change: it is a second code, or an attribution change with its own reason.
 */
final class ReferralCodeLockedException extends CollaboratorRuleException
{
    public static function because(string $code, string $what): static
    {
        return self::refuse('referral_code', sprintf(
            'The code %s has already been used — %s reference it. A used code does not change, because '
            .'every attribution row snapshotted it and would start naming something else.',
            $code,
            $what
        ));
    }

    public static function notEditable(): static
    {
        return static::refuse('referral_code',
            'Referral codes are fixed in this installation. A collaborator keeps the code their '
            .'collaborator ID gave them.');
    }
}
