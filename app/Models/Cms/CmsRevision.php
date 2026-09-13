<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\Cms\RevisionEvent;
use App\Models\Cms\Concerns\ForbidsDeletion;
use App\Models\Cms\Concerns\ForbidsUpdates;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One append-only content snapshot (phase-03 §2.14, decision D22) of a section, a page, or any later
 * draft/publish entity through the `revisionable` morph.
 *
 * **Write-once** and **no `deleted_at`** (decision D19): `RevisionRecorder` is the only writer, a
 * revision is never edited ({@see ForbidsUpdates}, `UPDATED_AT = null`) and an Eloquent delete throws
 * ({@see ForbidsDeletion}). Published snapshots (`is_published_snapshot = true`) are never pruned;
 * `PruneCmsRevisions` removes older non-published rows through the query builder only.
 *
 * `snapshot` is deliberately **not cast**: it holds the canonical JSON `ContentHasher` produced and
 * `content_hash` was computed from, so it is kept byte for byte. Read it with
 * {@see self::snapshotPayload()} or `RevisionRecorder::canonical()`.
 *
 * @property int $id
 * @property string $revisionable_type
 * @property int $revisionable_id
 * @property RevisionEvent $event
 * @property string $snapshot
 * @property string $content_hash
 * @property bool $is_published_snapshot
 * @property string|null $label
 * @property string|null $reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class CmsRevision extends Model
{
    use ForbidsDeletion;
    use ForbidsUpdates;

    /** A revision is never edited (§2.14). */
    public const UPDATED_AT = null;

    protected $table = 'cms_revisions';

    /**
     * `created_by` is stamped from the authenticated user by {@see ForbidsUpdates}.
     *
     * @var list<string>
     */
    protected $fillable = [
        'revisionable_type',
        'revisionable_id',
        'event',
        'snapshot',
        'content_hash',
        'is_published_snapshot',
        'label',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revisionable_id' => 'integer',
            'event' => RevisionEvent::class,
            'is_published_snapshot' => 'boolean',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * A revision belongs to the module of the thing it snapshots: `website_sections` for a section,
     * `pages` for a page, and whatever module a later-phase revisionable declares. Resolved through
     * Phase 1's own resolver so there is no second model => module map. Null when unresolvable.
     */
    public function moduleSlug(): ?string
    {
        $type = (string) $this->getAttribute('revisionable_type');

        if ($type === '') {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        try {
            return Modules::moduleForSubject(new $class);
        } catch (Throwable) {
            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The canonical payload (fields + items + media roles + pivots) as an array.
     *
     * @return array<string, mixed>
     */
    public function snapshotPayload(): array
    {
        $snapshot = $this->getAttribute('snapshot');

        if (is_array($snapshot)) {
            return $snapshot;
        }

        $decoded = is_string($snapshot) && $snapshot !== '' ? json_decode($snapshot, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    public function isPublishedSnapshot(): bool
    {
        return (bool) $this->is_published_snapshot;
    }

    /**
     * "Identical to the live version" (§2.14) — compared by hash, never by body.
     */
    public function matchesHash(?string $hash): bool
    {
        return $hash !== null && hash_equals((string) $this->content_hash, $hash);
    }

    /**
     * Does this revision belong to that target? The route layer uses it so revision #7 of page A can
     * never be reverted through page B (integration M-19). `RevisionRecorder::assertBelongsTo()` is
     * the throwing form.
     */
    public function belongsToTarget(Model $target): bool
    {
        return (string) $this->getAttribute('revisionable_type') === $target->getMorphClass()
            && (string) $this->getAttribute('revisionable_id') === (string) $target->getKey();
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * The section or page this snapshot was taken of. A trashed target resolves to null here — the
     * revision itself survives, because history is never removed with its subject.
     *
     * @return MorphTo<Model, $this>
     */
    public function revisionable(): MorphTo
    {
        return $this->morphTo();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Every revision of one target (`idx_rev_target`).
     *
     * @param  Builder<CmsRevision>  $query
     * @return Builder<CmsRevision>
     */
    public function scopeForTarget(Builder $query, Model $target): Builder
    {
        return $query->where($query->qualifyColumn('revisionable_type'), $target->getMorphClass())
            ->where($query->qualifyColumn('revisionable_id'), $target->getKey());
    }

    /**
     * @param  Builder<CmsRevision>  $query
     * @return Builder<CmsRevision>
     */
    public function scopePublishedSnapshots(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_published_snapshot'), true);
    }

    /**
     * The rows `PruneCmsRevisions` may consider — never a published snapshot.
     *
     * @param  Builder<CmsRevision>  $query
     * @return Builder<CmsRevision>
     */
    public function scopePrunable(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_published_snapshot'), false);
    }

    /**
     * @param  Builder<CmsRevision>  $query
     * @return Builder<CmsRevision>
     */
    public function scopeOfEvent(Builder $query, RevisionEvent|string $event): Builder
    {
        return $query->where(
            $query->qualifyColumn('event'),
            $event instanceof RevisionEvent ? $event->value : $event
        );
    }

    /**
     * Newest first — `idx_rev_target` ends in `id` for exactly this order.
     *
     * @param  Builder<CmsRevision>  $query
     * @return Builder<CmsRevision>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc($query->qualifyColumn('id'));
    }
}
