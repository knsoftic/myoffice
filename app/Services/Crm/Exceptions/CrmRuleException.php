<?php

declare(strict_types=1);

namespace App\Services\Crm\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A phase-05 business rule refused the act — a 422 with the message on the named field.
 *
 * These rules hold whatever the caller's permissions, Super Admin included: a lost reason is mandatory, a won
 * lead with a live conversion cannot be reopened, a system timeline row is never edited, the only primary
 * contact of a multi-contact client cannot be deleted, a user bound to another client cannot be bound again.
 *
 * It **is** a `ValidationException`, exactly like phase-04's `ContentRuleException`: Laravel renders it as a
 * 422 with an `errors` bag for a JSON request and as a redirect back with the error for a browser form, and it
 * is thrown inside the service's transaction, so the refused act leaves nothing behind.
 */
class CrmRuleException extends ValidationException
{
    public static function refuse(string $field, string $message): static
    {
        return static::withMessages([$field => [$message]]);
    }

    public static function reasonRequired(string $field = 'reason', string $message = 'A reason is required.'): static
    {
        return static::refuse($field, $message);
    }

    public static function lostReasonRequired(): static
    {
        return static::refuse('lost_reason', 'Say why the lead was lost.');
    }

    public static function liveConversion(): static
    {
        return static::refuse('to_status', 'This lead has been converted. Supersede the conversion before reopening it.');
    }

    public static function followUpRequired(): static
    {
        return static::refuse('follow_up', 'Schedule the next follow-up to move the lead to this stage.');
    }

    public static function systemActivity(): static
    {
        return static::refuse('activity', 'System timeline entries cannot be changed or removed.');
    }

    public static function manualActivityTypeOnly(): static
    {
        return static::refuse('type', 'Only notes, calls, WhatsApp messages, emails and meetings can be logged by hand.');
    }

    public static function editWindowClosed(int $minutes): static
    {
        return static::refuse('activity', sprintf('Notes can be edited for %d minutes after they are written.', $minutes));
    }

    public static function tooManyIds(int $max): static
    {
        return static::refuse('ids', sprintf('Select at most %d leads at a time.', $max));
    }

    public static function notWon(): static
    {
        return static::refuse('promote_to_won', 'Only a won lead can be converted. Mark it as won first.');
    }

    public static function clientNotFound(): static
    {
        return static::refuse('existing_client_id', 'Choose an existing client from the list.');
    }

    public static function portalUserTaken(): static
    {
        return static::refuse('email', 'That account is already the portal login of another client or contact.');
    }
}
