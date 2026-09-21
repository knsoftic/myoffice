<?php

declare(strict_types=1);

namespace App\Services\Institute\Exceptions;

use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * A catalogue rule refused the act — a 422 with the message on the named field.
 *
 * These hold whatever the caller's permissions, Super Admin included: a category holding courses is
 * never deleted, a course with no module is never published, and a published slug never moves without
 * somebody saying why. The messages name the thing and the number rather than stating a rule, because
 * the person reading one is usually mid-task and needs to know what to do next.
 */
class CourseRuleException extends ValidationException
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
     */
    public static function reasonRequired(string $field, string $message): static
    {
        return static::withMessages([$field => [$message]]);
    }
}
