<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClientPortal;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPortal\ClientPortalListRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Conversations — `client.messages.index`, `client.messages.show` (phase-05 §7, §8.10, §9.2, test 77), filled by
 * Phase 22's `messages` section.
 *
 * A conversation is personal, not corporate: the section lists only conversations where the **signed-in user** is a
 * participant — never every conversation of the company. Sending arrives with Phase 22.
 */
final class MessageController extends Controller
{
    use ServesClientPortal;

    public function index(ClientPortalListRequest $request): View
    {
        $this->authorize('client_portal.messages');

        return $this->sectionList($request, 'messages');
    }

    public function show(Request $request, string $conversation): View
    {
        $this->authorize('client_portal.messages');

        return $this->sectionDetail($request, 'messages', $conversation);
    }
}
