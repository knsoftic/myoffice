<?php

declare(strict_types=1);

namespace App\Services\Cms\Exceptions;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A draft payload does not match the shape `App\Support\Cms\SectionRegistry` declares for its type,
 * or a publish was attempted while the content is incomplete (phase-03 §6.2, FT-13, FT-18).
 *
 * It is the last gate before malformed content could reach a public view: the Form Request validates
 * first, but a service call from a seeder, a console command or a later phase has no Form Request, so
 * the service validates again and refuses here.
 *
 * `toValidationException()` lets a controller surface it as a 422 with the offending key named,
 * which is what every acceptance test in §11.1-§11.2 asserts.
 */
final class InvalidSectionContentException extends RuntimeException
{
    /**
     * Field-keyed messages, ready for a 422 body.
     *
     * @var array<string, list<string>>
     */
    public array $errors = [];

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function withErrors(string $message, array $errors = []): self
    {
        $exception = new self($message);
        $exception->errors = $errors;

        return $exception;
    }

    /**
     * @param  list<string>  $keys
     */
    public static function unknownKeys(string $sectionKey, array $keys): self
    {
        return self::withErrors(
            sprintf(
                'Section type [%s] does not declare the content %s [%s].',
                $sectionKey,
                count($keys) === 1 ? 'key' : 'keys',
                implode(', ', $keys)
            ),
            ['content' => ['Unrecognised content keys: '.implode(', ', $keys).'.']]
        );
    }

    public static function requiredField(string $sectionKey, string $field, string $label): self
    {
        return self::withErrors(
            sprintf('Section [%s] cannot be published while [%s] is empty.', $sectionKey, $field),
            ['content.'.$field => [$label.' is required before this section can be published.']]
        );
    }

    public static function requiredMedia(string $sectionKey, string $role, string $label): self
    {
        return self::withErrors(
            sprintf('Section [%s] cannot be published while the [%s] image is empty.', $sectionKey, $role),
            ['media.'.$role => [$label.' must be chosen before this section can be published.']]
        );
    }

    public static function missingAltText(string $role, int $assetId): self
    {
        return self::withErrors(
            sprintf('The image in the [%s] slot (asset #%d) has no alt text.', $role, $assetId),
            ['media.'.$role => ['Every placed image needs alt text before the section can be published.']]
        );
    }

    public static function repeaterMax(string $group, int $max): self
    {
        return self::withErrors(
            sprintf('The [%s] repeater accepts at most %d items.', $group, $max),
            ['items.'.$group => [sprintf('At most %d items are allowed here.', $max)]]
        );
    }

    public static function repeaterMin(string $group, int $min): self
    {
        return self::withErrors(
            sprintf('The [%s] repeater needs at least %d items.', $group, $min),
            ['items.'.$group => [sprintf('At least %d %s required here.', $min, $min === 1 ? 'item is' : 'items are')]]
        );
    }

    public static function metricRequired(string $group): self
    {
        return self::withErrors(
            'A live statistic must name the metric it counts.',
            ['items.'.$group.'.metric' => ['Choose what this statistic counts, or switch it to a typed-in value.']]
        );
    }

    /**
     * @param  list<int>  $expected
     * @param  list<int>  $given
     */
    public static function staleOrder(array $expected, array $given): self
    {
        return self::withErrors(
            'The order you submitted does not match the current set of rows — reload and try again.',
            ['order' => [sprintf(
                'Expected exactly %d ids (%s), received %d (%s).',
                count($expected),
                implode(', ', $expected) ?: 'none',
                count($given),
                implode(', ', $given) ?: 'none'
            )]]
        );
    }

    public static function reasonRequired(string $action): self
    {
        return self::withErrors(
            sprintf('A reason is required to %s.', $action),
            ['reason' => ['Say why — it is recorded in the audit trail.']]
        );
    }

    /**
     * Re-throwable as Laravel's own 422.
     */
    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages(
            $this->errors !== [] ? $this->errors : ['content' => [$this->getMessage()]]
        );
    }
}
