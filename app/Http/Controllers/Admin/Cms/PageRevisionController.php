<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Admin\Cms\Concerns\ListsRevisions;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForCms;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\RevertRevisionRequest;
use App\Models\Cms\CmsRevision;
use App\Models\Cms\Page;
use App\Services\Cms\ContentPublisher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page's revision history and revert (phase-03 §7.3): read under `pages.view_logs`, revert under
 * `pages.change_status` with a reason. A revert restores the **draft** body, never the live one (FT-11).
 */
final class PageRevisionController extends Controller
{
    use ListsRevisions;
    use RespondsForCms;

    public function __construct(
        private readonly ContentPublisher $publisher,
    ) {}

    public function index(Request $request, Page $page): View
    {
        $this->authorize('pages.view_logs');
        $this->authorize('viewRevisions', $page);

        $revisions = $this->revisionsOf($page);

        return view('admin.cms.pages.revisions', [
            'page' => $page,
            'revisions' => $revisions,
            'authors' => $this->revisionAuthors($revisions->items()),
            'currentHash' => $page->content_hash,
            'publishedHash' => $page->published_hash,
            'canRevert' => $request->user()?->can('pages.change_status') === true,
        ]);
    }

    public function revert(RevertRevisionRequest $request, Page $page, CmsRevision $revision): Response
    {
        $this->authorize('pages.change_status');
        $this->assertRevisionOf($revision, $page);
        $this->authorize('revert', $page);

        return $this->attempt($request, function () use ($request, $page, $revision): Response {
            $this->publisher->revert($page, $revision, (string) $request->reason());

            return $this->done(
                $request,
                sprintf('The draft was reverted to revision #%d. Publish it to make it live.', $revision->getKey()),
                redirect()->route('admin.website.pages.edit', $page),
            );
        }, field: 'revision');
    }
}
