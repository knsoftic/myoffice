<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClientPortal;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPortal\ClientPortalListRequest;
use Illuminate\Contracts\View\View;

/**
 * Milestones of one project — `client.milestones.index` (phase-05 §7, §8.10, §9.2, test 76), owned by Phase 5 and
 * filled by Phase 6's `milestones` section.
 *
 * The project must be one of the client's own (else 404). The milestone `amount` is selected by the section only when
 * the user holds `client_portal.invoices`; `showAmounts` tells the view the same thing.
 */
final class MilestoneController extends Controller
{
    use ServesClientPortal;

    public function index(ClientPortalListRequest $request, string $project): View
    {
        $this->authorize('client_portal.milestones');

        $record = $this->sectionRecord($request, 'projects', $project);

        return $this->sectionList($request, 'milestones', ['project' => (int) $record->getKey()], [
            'projectRecord' => $record,
            'showAmounts' => $this->portalUser($request)->can('client_portal.invoices'),
        ]);
    }
}
