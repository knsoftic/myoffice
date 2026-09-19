<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one permalink generator of the marketing modules (phase-04 §6.1).
 *
 * Invariants:
 *
 *   1. `normalise()` transliterates to ASCII, lower-cases and collapses separators; `make()` truncates the
 *      result to `$max` **before** a numeric suffix is added, then shortens the stem again for the suffix,
 *      so a suffixed slug still fits the column.
 *   2. Uniqueness is checked **including soft-deleted rows**. The query builder applies no soft-delete
 *      scope, so a trashed post keeps its permalink and the generator walks past it (`-2`, `-3`, …) —
 *      the single-column unique index never fires on a slug the generator produced.
 *   3. A source that normalises to nothing (a title of only emoji) falls back to the model name plus the
 *      next id: `service-17`.
 *   4. A reserved or purely numeric result gets a suffix until it is neither (`blog` → `blog-2`,
 *      `2026` → `2026-2`). A numeric slug would collide with id-based route binding.
 *   5. The generator reads; it does not lock. The owning service runs inside a transaction and retries
 *      `make()` up to three times on a 1062, so two concurrent saves can never persist one slug twice.
 */
final class SlugGenerator
{
    public const RESERVED = ['admin', 'login', 'logout', 'password', 'dashboard', 'api', 'storage', 'livewire',
        'blog', 'services', 'service', 'portfolio', 'team', 'careers', 'career', 'contact', 'courses', 'course',
        'admission', 'student', 'teacher', 'client', 'collaborator', 'preview', 'sitemap', 'sitemap.xml',
        'robots.txt', 'feed', 'search', 'privacy-policy', 'terms'];

    /** The longest numeric suffix the generator will append (`-99999`), reserved from the stem. */
    private const SUFFIX_ROOM = 6;

    /** How many suffixes are tried before giving up (the caller then asks for a manual slug). */
    private const MAX_SUFFIX = 99999;

    /** The validation pattern of an admin-entered slug (§6.1). */
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * A unique, non-reserved slug for `$source` in `$table.$column`.
     *
     * @param  int|null  $ignoreId  the row being edited, whose own slug does not count as taken
     */
    public static function make(string $source, string $table, ?int $ignoreId = null,
        string $column = 'slug', int $max = 180): string
    {
        $max = max(self::SUFFIX_ROOM + 2, $max);
        $base = self::truncate(self::normalise($source), $max);

        if ($base === '') {
            $base = self::truncate(self::fallback($table), $max);
        }

        $taken = self::taken($base, $table, $ignoreId, $column, $max);
        $candidate = $base;
        $counter = 1;

        while (isset($taken[$candidate]) || self::isReserved($candidate)) {
            $counter++;

            if ($counter > self::MAX_SUFFIX) {
                // Practically unreachable; a random tail keeps the caller's transaction alive, and the
                // unique index still has the final word.
                $tail = '-'.Str::lower(Str::random(5));

                return self::truncate($base, $max - strlen($tail)).$tail;
            }

            $suffix = '-'.$counter;
            $candidate = self::truncate($base, $max - strlen($suffix)).$suffix;
        }

        return $candidate;
    }

    /**
     * Is `$slug` a path the public router or a later phase owns, or purely numeric?
     *
     * Both are refused for a hand-entered slug by the Form Requests (§11 test 4 — `admin`, `blog`, `0`)
     * and suffixed by `make()`.
     */
    public static function isReserved(string $slug): bool
    {
        $slug = Str::lower(trim($slug));

        if ($slug === '') {
            return false;
        }

        return in_array($slug, self::RESERVED, true) || ctype_digit($slug);
    }

    /**
     * ASCII transliteration + `Str::slug`: lower-case, one `-` between words, no leading/trailing `-`.
     */
    public static function normalise(string $value): string
    {
        $value = (string) preg_replace('~[\x00-\x1F\x7F]~u', ' ', $value);

        return trim(Str::slug(Str::ascii($value), '-'), '-');
    }

    private static function truncate(string $slug, int $max): string
    {
        if ($max <= 0) {
            return '';
        }

        return rtrim(substr($slug, 0, $max), '-');
    }

    /**
     * `services` → `service-18`, `blog_posts` → `blog-post-31`: the singular model name plus the next id.
     */
    private static function fallback(string $table): string
    {
        $name = Str::slug(Str::singular(str_replace('_', ' ', $table)));
        $next = (int) DB::table($table)->max('id') + 1;

        return ($name === '' ? 'item' : $name).'-'.$next;
    }

    /**
     * Every existing slug that could collide with a candidate built from `$base` — trashed rows included.
     *
     * @return array<string, true>
     */
    private static function taken(string $base, string $table, ?int $ignoreId, string $column, int $max): array
    {
        // Every candidate starts with this stem, however much of `$base` the suffix forces off.
        $stem = self::truncate($base, $max - self::SUFFIX_ROOM);
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $stem === '' ? $base : $stem).'%';

        return DB::table($table)
            ->where($column, 'like', $like)
            ->when($ignoreId !== null, static fn ($query) => $query->where('id', '!=', $ignoreId))
            ->pluck($column)
            // The column collation compares case-insensitively; so does this set.
            ->mapWithKeys(static fn ($slug): array => [Str::lower((string) $slug) => true])
            ->all();
    }
}
