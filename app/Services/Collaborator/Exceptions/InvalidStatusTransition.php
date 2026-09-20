<?php

declare(strict_types=1);

namespace App\Services\Collaborator\Exceptions;

/**
 * A collaborator was asked to move between two states that do not connect (phase-08-09 §6.2.2).
 *
 * The transition table is short and closed on purpose: four states, and every edge either happens for a
 * named reason or does not happen. A status somebody can set freely is a status that eventually means
 * nothing, and this one decides whether money is earned (INV-C4).
 */
final class InvalidStatusTransition extends CollaboratorRuleException
{
    public static function between(string $from, string $to): static
    {
        return self::refuse('status', sprintf(
            'A collaborator who is %s cannot become %s. The states are fixed (phase-08-09 §6.2.2), so a '
            .'wrong turn is always recoverable.',
            $from,
            $to
        ));
    }
}
