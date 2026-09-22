<?php

declare(strict_types=1);

namespace App\Services\Files\Exceptions;

use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * An upload was refused — a 422 with the message on the file field.
 *
 * These hold whatever the uploader's permissions, Super Admin included. A `.php` is not accepted because
 * an administrator turned a setting on, and a PDF renamed `.docx` is not accepted because the person
 * uploading it is a teacher. The messages say what to do next rather than quoting the rule, because the
 * person reading one is mid-upload and wants to finish.
 */
class FileRuleException extends ValidationException
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
}
