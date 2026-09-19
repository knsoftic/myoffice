<?php

declare(strict_types=1);

namespace App\Services\Crm\Exceptions;

use App\Enums\LeadStatus;

/**
 * The status pair is not in the §2.11 transition table (phase-05 §6.1 `changeStatus()`, test 12) — a 422
 * carrying the allowed targets, which the optimistic board uses to spring the card back.
 */
final class IllegalLeadTransitionException extends CrmRuleException
{
    public ?LeadStatus $from = null;

    public ?LeadStatus $to = null;

    /** @var list<LeadStatus> */
    public array $allowed = [];

    /**
     * @param  list<LeadStatus>  $allowed
     */
    public static function between(LeadStatus $from, LeadStatus $to, array $allowed): self
    {
        $labels = array_map(static fn (LeadStatus $status): string => $status->label(), $allowed);

        $exception = self::refuse('to_status', sprintf(
            'A lead cannot move from %s to %s. Allowed: %s.',
            $from->label(),
            $to->label(),
            $labels === [] ? 'none' : implode(', ', $labels),
        ));

        $exception->from = $from;
        $exception->to = $to;
        $exception->allowed = array_values($allowed);

        return $exception;
    }

    /**
     * @return list<string>
     */
    public function allowedValues(): array
    {
        return array_map(static fn (LeadStatus $status): string => $status->value, $this->allowed);
    }

    /**
     * @return array{current_status: string|null, attempted_status: string|null, allowed: list<string>}
     */
    public function context(): array
    {
        return [
            'current_status' => $this->from?->value,
            'attempted_status' => $this->to?->value,
            'allowed' => $this->allowedValues(),
        ];
    }
}
