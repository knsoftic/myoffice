<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClientPortal;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPortal\ClientPortalListRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The client's projects — `client.projects.index`, `client.projects.show`, `client.progress.show` (phase-05 §7,
 * §8.10, §9.2, tests 71, 72, 75), owned by Phase 5 and filled by Phase 6's `projects` / `progress` sections (D31).
 *
 * Rows are `projects.client_id = ClientContext::clientId()` and never show the internal `budget` — the section's
 * explicit column list guarantees it. Another client's project id is a 404. Until Phase 6 registers its sections every
 * route here is a 404 and the nav item is absent ([D-P5-1]).
 */
final class ProjectController extends Controller
{
    use ServesClientPortal;

    public function index(ClientPortalListRequest $request): View
    {
        $this->authorize('client_portal.projects');

        return $this->sectionList($request, 'projects');
    }

    public function show(Request $request, string $project): View
    {
        $this->authorize('client_portal.projects');

        return $this->sectionDetail($request, 'projects', $project);
    }

    /**
     * The one number a client cares about. Phase 6 folds the view into the project screen; both names resolve to it.
     */
    public function progress(ClientPortalListRequest $request, string $project): View
    {
        $this->authorize('client_portal.projects');

        $record = $this->sectionRecord($request, 'projects', $project);

        return $this->sectionList($request, 'progress', ['project' => (int) $record->getKey()], ['projectRecord' => $record]);
    }
}
