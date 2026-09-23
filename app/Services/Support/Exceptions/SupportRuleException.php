<?php

declare(strict_types=1);

namespace App\Services\Support\Exceptions;

use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * A support, meeting or messaging rule refused the act — a 422 with the message on the named field.
 *
 * Every domain in this system has its own `*RuleException` (`CourseRuleException`,
 * `CrmRuleException`, `HrRuleException`, `ProjectRuleException` …) and this is Phase 22's. The
 * shape is identical on purpose: a caller that catches one knows what it holds, and a handler that
 * renders one does not have to know which service threw it.
 *
 * **These hold whatever the caller's permissions, Super Admin included.** A ticket is never deleted,
 * a message is never edited, a closed thread never accepts another line, and a pair the §94 matrix
 * refuses is refused for everybody — `Gate::before` waves a Super Admin past every policy, so a rule
 * that mattered was never going to live in one (D124, D140).
 *
 * **The messages are written for the person who tried.** They are mid-task: a refusal that names the
 * route that *is* open — raise a ticket, message your own teacher, reply instead of editing — is the
 * difference between being stopped and being stuck.
 */
class SupportRuleException extends ValidationException
{
    /**
     * @param  array<string, list<string>>  $messages
     */
    public static function withMessages(array $messages): static
    {
        /** @var Validator $validator */
        $validator = validator([], []);

        foreach ($messages as $field => $errors) {
            foreach ((array) $errors as $error) {
                $validator->errors()->add($field, $error);
            }
        }

        return new static($validator);
    }

    public static function refuse(string $field, string $message): static
    {
        return static::withMessages([$field => [$message]]);
    }

    /**
     * "That can be done, once you say why" — read differently at the call site from a flat refusal.
     *
     * Used wherever §2.28 marks a transition *reason* mandatory: closing a thread, removing somebody
     * from one, cancelling a meeting, reopening a closed ticket.
     */
    public static function reasonRequired(string $field, string $message): static
    {
        return static::withMessages([$field => [$message]]);
    }
}
