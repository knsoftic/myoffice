<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * Shared helpers for the string-backed enums in App\Enums.
 *
 * Any enum using this trait must expose a human readable label(); in return it
 * gets the select-input helpers every enum in the contract has to provide.
 */
trait HasOptions
{
    /**
     * Human readable label for the case.
     */
    abstract public function label(): string;

    /**
     * Tailwind colour token used by badges and status pills.
     */
    abstract public function color(): string;

    /**
     * Value => label map, ready for a <select> or a filter dropdown.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * All backing values, in declaration order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
