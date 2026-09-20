<?php

declare(strict_types=1);

namespace App\Services\Collaborator\Exceptions;

/**
 * The referral code somebody asked for is already in use (phase-08-09 §6.1).
 *
 * **The message names nothing about the holder.** A referral code is a public lookup key, so an error
 * that said "taken by Ali Traders" would turn this form into a directory of every partner the business
 * works with — one guess at a time.
 */
final class ReferralCodeTakenException extends CollaboratorRuleException
{
    public static function forCode(string $code): static
    {
        return self::refuse('referral_code', sprintf(
            'The code %s is not available. Choose another one.',
            $code
        ));
    }
}
