<?php

declare(strict_types=1);

namespace App\Search\Contracts;

use App\DataObjects\Search\SearchHit;
use App\Enums\SearchEntityType;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One searchable entity (phase-19-23 §6.23, [D-23-3]).
 *
 * **Eleven providers rather than one UNION, because there are eleven different isolation rules.**
 * A single query across eleven tables could apply one scope, and the whole point of §108 is that a
 * student searching "Ahmed" must never surface another student, while a teacher searching the same
 * word should find the students in their own batches and nobody else's. Those cannot be the same
 * `WHERE` clause. Each provider therefore reuses **the scope its own module's index already uses** —
 * not a copy of it, the same one — so a rule that changes in Phase 5 changes here too.
 *
 * **`query()` returns models and `present()` turns one into a hit.** They are separate so the
 * service can run exactly one query per provider and then present the rows, rather than a query per
 * row. §6.23 says "one query per provider, never a query per row", and a provider that eager-loads
 * what `present()` needs is keeping that promise; one that lazy-loads a relation inside `present()`
 * is quietly breaking it eleven times per keystroke.
 */
interface SearchProvider
{
    public function type(): SearchEntityType;

    /** The module that must be enabled. */
    public function module(): string;

    /** The permission the viewer must hold to search this entity at all. */
    public function permission(): string;

    /**
     * The columns this provider matches on — shown in the palette's "searching by" hint, so
     * somebody who typed a phone number and got nothing can see whether phone was ever searched.
     *
     * @return list<string>
     */
    public function columns(): array;

    /**
     * Matching rows for this viewer, already scoped and already eager-loaded.
     *
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function query(string $term, User $viewer, int $limit): Collection;

    /**
     * Turn one row into a palette entry.
     *
     * The `url` is nullable on purpose: a record the viewer matched but may not open is presented
     * **without a link**, never dropped. See {@see SearchHit}.
     */
    public function present(\Illuminate\Database\Eloquent\Model $model, User $viewer): SearchHit;

    /**
     * Where this entity sits among the groups. Lower is higher up the palette.
     *
     * Taken from {@see SearchEntityType::weight()} by default so the ordering is declared once,
     * next to the entity, rather than spread across eleven classes.
     */
    public function weight(): int;

    /**
     * Document-number columns this entity can be found by exactly.
     *
     * The fast path: pasting `INV-2026-0042` should open that invoice rather than offering it among
     * nine other things. Empty for an entity with no document number.
     *
     * @return list<string>
     */
    public function exactColumns(): array;
}
