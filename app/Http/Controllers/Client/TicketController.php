<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClientPortal;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPortal\ClientPortalListRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Support tickets — `client.tickets.index`, `client.tickets.show` (phase-05 §7, §8.10, §9.2, test 71, Q3), filled by
 * Phase 22's `tickets` section: `support_tickets.client_id = ClientContext::clientId()`, so every portal contact of the
 * company sees the company's tickets. Read-only here; raising a ticket arrives with Phase 22 at the
 * `// Phase 22: client writes` marker of `routes/client.php`.
 */
final class TicketController extends Controller
{
    use ServesClientPortal;

    public function index(ClientPortalListRequest $request): View
    {
        $this->authorize('client_portal.tickets');

        return $this->sectionList($request, 'tickets');
    }

    public function show(Request $request, string $ticket): View
    {
        $this->authorize('client_portal.tickets');

        return $this->sectionDetail($request, 'tickets', $ticket);
    }
}
