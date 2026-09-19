<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Support\SlugGenerator;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The admin-editable slug of a phase-04 entity (phase-04 §6.1, §6.11, acceptance test 4).
 *
 * Left blank, the model's `HasSlug` trait derives it through `SlugGenerator::make()`. Typed by hand it
 * must be:
 *
 *   · lowercase letters and digits joined by single hyphens (`regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/`);
 *   · not reserved (`SlugGenerator::isReserved()` — `admin`, `blog`, `services`, ...);
 *   · not purely numeric (`0`, `2026`), which would read as an id (§6.1 invariant 4);
 *   · unique against **every** row of the table, trashed rows included — the unique index is a plain
 *     single-column index, so a trashed record keeps its permalink (§6.1 invariant 2).
 *
 * The uniqueness query goes to the table rather than the model so a soft-delete scope can never hide
 * the holder.
 */
trait ValidatesContentSlug
{
    /**
     * @return list<mixed>
     */
    protected function slugRules(string $table, ?int $ignoreId, int $max = 180): array
    {
        // One pattern for the request and the service-side net (`SlugGenerator::PATTERN`).
        return ['sometimes', 'bail', 'nullable', 'string', 'max:'.$max, 'regex:'.SlugGenerator::PATTERN, $this->slugIsAvailable($table, $ignoreId)];
    }

    /**
     * Lower-case and trim a typed slug before the rules see it; an empty one means "derive it".
     */
    protected function normaliseSlugInput(string $key = 'slug'): void
    {
        $value = $this->input($key);

        if (! is_string($value)) {
            return;
        }

        $slug = strtolower(trim($value, " \t\n\r\0\x0B/"));

        $this->merge([$key => $slug === '' ? null : $slug]);
    }

    private function slugIsAvailable(string $table, ?int $ignoreId): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($table, $ignoreId): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            if (ctype_digit($value)) {
                $fail('The address cannot be only a number. Add a word to it.');

                return;
            }

            if (SlugGenerator::isReserved($value)) {
                $fail(sprintf('"%s" is reserved for another part of the site. Choose another address.', $value));

                return;
            }

            $holder = DB::table($table)
                ->where('slug', $value)
                ->when($ignoreId !== null, static fn ($query) => $query->where('id', '!=', $ignoreId))
                ->first(['id', 'deleted_at']);

            if ($holder === null) {
                return;
            }

            $fail($holder->deleted_at !== null
                ? sprintf('"%s" still belongs to a record in the trash. Choose another address.', $value)
                : sprintf('"%s" is already in use. Choose another address.', $value));
        };
    }
}
