<?php

declare(strict_types=1);

namespace App\Services\Project\Exceptions;

use BackedEnum;

/**
 * The status pair is not in the §2.13 transition table (phase-06 §2.13, P6-12) — a 422 carrying the allowed
 * targets, which the optimistic Kanban board uses to spring the card back to the column it came from.
 *
 * One class serves projects, milestones, tasks and time entries: all four enums expose the same
 * `allowedTransitions()` / `label()` pair, so a separate exception per level would only duplicate the
 * message and give the board four shapes to understand instead of one.
 */
final class InvalidStatusTransition extends ProjectRuleException
{
    public ?BackedEnum $from = null;

    public ?BackedEnum $to = null;

    /** @var list<BackedEnum> */
    public array $allowed = [];

    /**
     * @param  list<BackedEnum>  $allowed
     */
    public static function between(BackedEnum $from, BackedEnum $to, array $allowed, string $field = 'status'): self
    {
        $labels = array_map(self::label(...), $allowed);

        $exception = self::refuse($field, sprintf(
            'This cannot move from %s to %s. Allowed: %s.',
            self::label($from),
            self::label($to),
            $labels === [] ? 'nothing — it is final' : implode(', ', $labels),
        ));

        $exception->from = $from;
        $exception->to = $to;
        $exception->allowed = array_values($allowed);

        return $exception;
    }

    private static function label(BackedEnum $status): string
    {
        return method_exists($status, 'label') ? $status->label() : (string) $status->value;
    }
}
