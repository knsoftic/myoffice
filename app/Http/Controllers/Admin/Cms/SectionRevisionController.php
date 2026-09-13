<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Admin\Cms\Concerns\ListsRevisions;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForCms;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\RevertRevisionRequest;
use App\Models\Cms\CmsRevision;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\ContentPublisher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A section's revision history and revert (phase-03 §7.1): read under `website_sections.view_logs`,
 * revert under `website_sections.change_status` with a reason. A revert restores the **draft**, never
 * the live version (FT-11).
 */
final class SectionRevisionController extends Controller
{
    use ListsRevisions;
    use RespondsForCms;

    public function __construct(
        private readonly ContentPublisher $publisher,
    ) {}

    public function index(Request $request, WebsiteSection $section): View
    {
        $this->authorize('website_sections.view_logs');
        $this->authorize('viewRevisions', $section);

        $revisions = $this->revisionsOf($section);

        return view('admin.cms.sections.revisions', [
            'section' => $section,
            'revisions' => $revisions,
            'authors' => $this->revisionAuthors($revisions->items()),
            'currentHash' => $section->content_hash,
            'publishedHash' => $section->published_hash,
            'canRevert' => $request->user()?->can('website_sections.change_status') === true,
        ]);
    }

    public function revert(RevertRevisionRequest $request, WebsiteSection $section, CmsRevision $revision): Response
    {
        $this->authorize('website_sections.change_status');
        $this->assertRevisionOf($revision, $section);
        $this->authorize('revert', $section);

        return $this->attempt($request, function () use ($request, $section, $revision): Response {
            $this->publisher->revert($section, $revision, (string) $request->reason());

            return $this->done(
                $request,
                sprintf('The draft was reverted to revision #%d. Publish it to make it live.', $revision->getKey()),
                redirect()->route('admin.website.sections.edit', $section),
            );
        }, field: 'revision');
    }
}
