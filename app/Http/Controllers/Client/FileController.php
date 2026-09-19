<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClientPortal;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPortal\ClientPortalListRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deliverable files — `client.files.index` (with `?project=`), `client.files.download` (phase-05 §7, §8.10, §9.2,
 * test 71), filled by Phase 6's `files` section over `attachments`.
 *
 * Only `attachments.visibility = client` rows whose attachable resolves to one of the client's own projects, tasks,
 * milestones or tickets; the download re-checks the same predicate inside the section before streaming, and needs
 * `client_portal.download` on top of `client_portal.files`. There is no `files` table (F-2.6, F-2.8).
 */
final class FileController extends Controller
{
    use ServesClientPortal;

    public function index(ClientPortalListRequest $request): View
    {
        $this->authorize('client_portal.files');

        return $this->sectionList($request, 'files');
    }

    public function download(Request $request, string $file): Response
    {
        $this->authorize('client_portal.download');

        return $this->sectionDownload($request, 'files', $file);
    }
}
