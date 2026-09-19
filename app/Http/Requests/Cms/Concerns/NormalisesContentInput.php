<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

/**
 * Input shaping shared by the phase-04 write requests.
 *
 * Only the **shape** is touched before validation: strings are trimmed, an empty string becomes null,
 * a list of strings is trimmed and emptied of blanks. Anything that is not the expected type is left
 * exactly as it came, so the rules reject it with a 422 instead of a TypeError answering 500.
 */
trait NormalisesContentInput
{
    /**
     * @param  list<string>  $keys
     */
    protected function trimInputs(array $keys): void
    {
        $clean = [];

        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $value = $this->input($key);

            if (is_string($value)) {
                $value = trim($value);
                $clean[$key] = $value === '' ? null : $value;
            }
        }

        if ($clean !== []) {
            $this->merge($clean);
        }
    }

    /**
     * Trim every entry of a posted list and drop the blank ones; a list that ends up empty becomes an
     * empty array (the service stores it as null, §6.4 invariant 2). Non-string entries are kept so the
     * `.*` rule can refuse them.
     *
     * @param  list<string>  $keys
     */
    protected function cleanStringLists(array $keys): void
    {
        $clean = [];

        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $value = $this->input($key);

            if (! is_array($value)) {
                continue;
            }

            $list = [];

            foreach ($value as $entry) {
                if (is_string($entry)) {
                    $entry = trim($entry);

                    if ($entry === '') {
                        continue;
                    }
                }

                $list[] = $entry;
            }

            $clean[$key] = $list;
        }

        if ($clean !== []) {
            $this->merge($clean);
        }
    }

    /**
     * A posted money amount as a canonical decimal string: separators and spaces removed, never cast to
     * a float (CLAUDE.md rule 4). Anything else is left for the `decimal` rule to refuse.
     *
     * @param  list<string>  $keys
     */
    protected function normaliseAmounts(array $keys): void
    {
        $clean = [];

        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $value = $this->input($key);

            if (is_int($value)) {
                $value = (string) $value;
            }

            if (! is_string($value)) {
                continue;
            }

            $value = str_replace([',', ' '], '', trim($value));
            $clean[$key] = $value === '' ? null : $value;
        }

        if ($clean !== []) {
            $this->merge($clean);
        }
    }

    /**
     * The validated ids of a posted id list, as integers, in the order given, without duplicates.
     *
     * @return list<int>
     */
    protected function validatedIds(string $key): array
    {
        $ids = $this->validated($key);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $ids)));
    }

    /**
     * The validated list of strings under a key (empty when absent).
     *
     * @return list<string>
     */
    protected function validatedStrings(string $key): array
    {
        $values = $this->validated($key);

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $value): string => (string) $value, $values));
    }
}
