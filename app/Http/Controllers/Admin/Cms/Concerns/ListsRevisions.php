<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms\Concerns;

use App\Models\Cms\CmsRevision;
use App\Models\User;
use App\Services\Cms\Exceptions\ContentActionNotAllowedException;
use App\Services\Cms\RevisionRecorder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\Response;

/**
 * The append-only revision history of a snapshot-published target (`cms_revisions`, §2.14), shared by
 * the section and page revision screens.
 *
 * Revisions are read by the target's morph, newest first, never joined through a relation a model may
 * not declare. A revision that belongs to another target is a **404**, never a revert (integration M-19):
 * the ownership check runs before any service call.
 *
 * The using class must also use `RespondsForCms`.
 */
trait ListsRevisions
{
    /**
     * @return LengthAwarePaginator<int, CmsRevision>
     */
    protected function revisionsOf(Model $target): LengthAwarePaginator
    {
        return CmsRevision::query()
            ->where('revisionable_type', $target->getMorphClass())
            ->where('revisionable_id', $target->getKey())
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();
    }

    /**
     * @param  iterable<CmsRevision>  $revisions
     * @return array<int, string>
     */
    protected function revisionAuthors(iterable $revisions): array
    {
        $ids = [];

        foreach ($revisions as $revision) {
            if ($revision->created_by !== null) {
                $ids[] = (int) $revision->created_by;
            }
        }

        $ids = array_values(array_unique($ids));

        return $ids === [] ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * 404 unless the revision belongs to the target.
     */
    protected function assertRevisionOf(CmsRevision $revision, Model $target): void
    {
        try {
            app(RevisionRecorder::class)->assertBelongsTo($revision, $target);
        } catch (ContentActionNotAllowedException) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }
}
