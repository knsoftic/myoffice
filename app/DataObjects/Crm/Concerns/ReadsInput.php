<?php

declare(strict_types=1);

namespace App\DataObjects\Crm\Concerns;

use App\Support\Format;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Turns a validated Form Request payload into typed DTO fields (phase-05 §6).
 *
 * The DTOs are built from `$request->validated()`, so these readers only normalise shape — trimming, blank to
 * null, integers, booleans, enums and money strings — they never validate. Money is normalised through
 * `App\Support\Money` and stays a string; a date-time typed by a person is read in the display timezone and
 * converted to UTC for storage (D61).
 */
trait ReadsInput
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected static function str(array $data, string $key, ?int $max = null): ?string
    {
        $value = $data[$key] ?? null;

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $max === null ? $value : mb_substr($value, 0, $max);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1 ? (int) trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function bool(array $data, string $key, bool $default = false): bool
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return $default;
        }

        return filter_var($data[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function nullableBool(array $data, string $key): ?bool
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }

        return filter_var($data[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * A decimal(15,2) string, or null for a blank value.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function money(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        try {
            return Money::of((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A date-time entered in the display timezone, returned in UTC.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function dateTime(array $data, string $key): ?CarbonImmutable
    {
        $value = $data[$key] ?? null;

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value), Format::displayTimezone())->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A calendar date (no time, no timezone conversion) as `Y-m-d`.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function date(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  array<string, mixed>  $data
     * @param  class-string<TEnum>  $enum
     * @return TEnum|null
     */
    protected static function enum(array $data, string $key, string $enum): ?BackedEnum
    {
        $value = $data[$key] ?? null;

        if ($value instanceof $enum) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $enum::tryFrom($value);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    protected static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if ($item instanceof BackedEnum) {
                $item = $item->value;
            }

            if (is_scalar($item) && trim((string) $item) !== '') {
                $out[] = trim((string) $item);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Which of `$fields` the payload actually carries — the "only the fields supplied" rule of an update.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     * @return list<string>
     */
    protected static function provided(array $data, array $fields): array
    {
        return array_values(array_filter($fields, static fn (string $field): bool => array_key_exists($field, $data)));
    }
}
