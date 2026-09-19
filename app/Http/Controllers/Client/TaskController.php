<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClientPortal;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPortal\ClientPortalListRequest;
use Illuminate\Contracts\View\View;

/**
 * Client-visible tasks of one project — `client.tasks.index` (phase-05 §7, §8.10, §9.2, test 74), filled by Phase 6's
 * `tasks` section.
 *
 * The project must be one of the client's own (resolved through the `projects` section, else 404); the section lists
 * only `tasks.is_client_visible = 1` rows of a project whose `client_can_see_tasks` is on, and never selects estimated
 * or actual hours or internal comments.
 */
final class TaskController extends Controller
{
    use ServesClientPortal;

    public function index(ClientPortalListRequest $request, string $project): View
    {
        $this->authorize('client_portal.tasks');

        $record = $this->sectionRecord($request, 'projects', $project);

        return $this->sectionList($request, 'tasks', ['project' => (int) $record->getKey()], ['projectRecord' => $record]);
    }
}
