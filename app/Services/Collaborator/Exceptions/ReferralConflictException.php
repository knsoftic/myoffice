<?php

declare(strict_types=1);

namespace App\Services\Collaborator\Exceptions;

/**
 * A subject already has an active attribution, and something tried to add a second one.
 *
 * `uq_cr_student_current` and its three siblings make "one active referral per subject" a database
 * fact, so the collision arrives as a 1062 from MariaDB — which says nothing a person can act on. This
 * turns it into the sentence that matters: **who already has the credit**. That is the question the
 * receptionist looking at the screen is actually asking, and answering it is the difference between a
 * form they can correct and a red box they escalate.
 */
final class ReferralConflictException extends CollaboratorRuleException
{
    public static function alreadyAttributed(string $subject, string $existing, string $attempted): self
    {
        return self::refuse('collaborator_id', sprintf(
            'This %s is already credited to %s. Attributing it to %s is a change of attribution, not a '
            .'second one: use the change flow, which supersedes the existing row with a reason and '
            .'leaves every commission it already earned pointing at it.',
            $subject,
            $existing,
            $attempted,
        ));
    }
}
