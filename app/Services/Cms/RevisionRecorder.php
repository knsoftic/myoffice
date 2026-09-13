<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\RevisionEvent;
use App\Models\Cms\CmsRevision;
use App\Services\Cms\Exceptions\ContentActionNotAllowedException;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The only writer of `cms_revisions` (phase-03 §2.14, D19, [D-W3-6]).
 *
 * Invariants:
 *
 *   · **Append-only.** This class inserts rows and reads rows. It has no update and no delete path:
 *     a revision is never edited, and reverting *to* a revision writes a new `reverted` row rather
 *     than touching the old one. There is no `updated_at` on the table and none is written.
 *   · `is_published_snapshot` is derived from the event (`RevisionEvent::isPublishSnapshot()`), never
 *     passed in, so a published snapshot cannot be mislabelled as prunable.
 *   · An event that requires a reason (`unpublished`, `reverted`) is refused without one.
 *   · The snapshot stored is the canonical JSON of `ContentHasher::json()`, and `content_hash` is the
 *     hash of that same payload — so "identical to the live version" is a string comparison.
 */
final class RevisionRecorder
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ContentHasher $hasher,
        private readonly CmsAuditor $auditor,
    ) {}

    /**
     * Append one revision for a section or a page.
     *
     * @param  array<string, mixed>  $canonical
     */
    public function record(
        Model $target,
        RevisionEvent $event,
        array $canonical,
        ?string $reason = null,
        ?string $label = null,
    ): CmsRevision {
        $reason = $reason === null ? null : trim($reason);
        $label = $label === null ? null : trim($label);

        if ($event->requiresReason() && ($reason === null || $reason === '')) {
            throw InvalidSectionContentException::reasonRequired(match ($event) {
                RevisionEvent::Unpublished => 'unpublish',
                RevisionEvent::Reverted => 'revert to an earlier revision',
                default => $event->label(),
            });
        }

        $attributes = [
            'revisionable_type' => $target->getMorphClass(),
            'revisionable_id' => $target->getKey(),
            'event' => $event->value,
            'snapshot' => $this->hasher->json($canonical),
            'content_hash' => $this->hasher->hash($canonical),
            'is_published_snapshot' => $event->isPublishSnapshot(),
            'label' => $label === '' || $label === null ? null : mb_substr($label, 0, 150),
            'reason' => $reason === '' || $reason === null ? null : mb_substr($reason, 0, 255),
            'created_by' => $this->auditor->actorId(),
            'created_at' => Carbon::now(),
        ];

        $id = $this->db->connection()->table('cms_revisions')->insertGetId($attributes);

        /** @var CmsRevision */
        return CmsRevision::query()->hydrate([
            ['id' => $id] + $attributes,
        ])->first();
    }

    /**
     * Does this revision belong to that target? Reverting across targets is refused (§6.2).
     */
    public function assertBelongsTo(CmsRevision $revision, Model $target): void
    {
        $type = (string) $revision->getAttribute('revisionable_type');
        $id = (string) $revision->getAttribute('revisionable_id');

        if ($type !== $target->getMorphClass() || $id !== (string) $target->getKey()) {
            throw ContentActionNotAllowedException::revisionBelongsElsewhere();
        }
    }

    /**
     * The canonical payload a revision holds. Tolerates a model that casts `snapshot` to an array.
     *
     * @return array<string, mixed>
     */
    public function canonical(CmsRevision $revision): array
    {
        $snapshot = $revision->getAttribute('snapshot');

        if (is_array($snapshot)) {
            return $snapshot;
        }

        $decoded = json_decode((string) $snapshot, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The newest published snapshot of a target, or null.
     */
    public function latestPublished(Model $target): ?CmsRevision
    {
        $row = $this->db->connection()->table('cms_revisions')
            ->where('revisionable_type', $target->getMorphClass())
            ->where('revisionable_id', $target->getKey())
            ->where('is_published_snapshot', true)
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var CmsRevision */
        return CmsRevision::query()->hydrate([(array) $row])->first();
    }
}
