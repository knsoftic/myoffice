<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClientPortal;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPortal\ClientPortalListRequest;
use Illuminate\Contracts\View\View;

/**
 * Meetings — `client.meetings.index` (phase-05 §7, §8.10, §9.2), filled by Phase 22's `meetings` section:
 * `meetings.client_id = ClientContext::clientId()` or a participant row for the signed-in user; `?when=upcoming|past`.
 */
final class MeetingController extends Controller
{
    use ServesClientPortal;

    public function index(ClientPortalListRequest $request): View
    {
        $this->authorize('client_portal.meetings');

        return $this->sectionList($request, 'meetings');
    }
}
