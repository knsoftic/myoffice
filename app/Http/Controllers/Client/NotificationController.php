<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClientPortal;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPortal\ClientPortalListRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in user's notifications — `client.notifications.index`, `.read`, `.read-all` (phase-05 §7, §8.10, §9.2),
 * `can:client_portal.notifications`, served through the `notifications` section.
 *
 * `notifiable_type = User AND notifiable_id = auth()->id()` — a notification is personal, never the company's. Marking
 * one read goes through the user's own `notifications()` relation, so another user's notification id is a **404**
 * (ownership is re-asserted by the query itself). While the section is not registered (no notifications store yet)
 * every route here is a 404.
 */
final class NotificationController extends Controller
{
    use ServesClientPortal;

    public function index(ClientPortalListRequest $request): View
    {
        $this->authorize('client_portal.notifications');

        return $this->sectionList($request, 'notifications');
    }

    public function read(Request $request, string $notification): Response
    {
        $this->authorize('client_portal.notifications');
        $this->section($request, 'notifications');

        $record = $this->portalUser($request)
            ->notifications()
            ->whereKey($notification)
            ->first();

        abort_unless($record instanceof DatabaseNotification, Response::HTTP_NOT_FOUND);

        $record->markAsRead();

        return $this->answer($request, 'Marked as read.', ['id' => (string) $record->getKey()]);
    }

    public function readAll(Request $request): Response
    {
        $this->authorize('client_portal.notifications');
        $this->section($request, 'notifications');

        $count = $this->portalUser($request)
            ->unreadNotifications()
            ->update(['read_at' => Carbon::now()]);

        return $this->answer($request, $count === 1 ? '1 notification marked as read.' : sprintf('%d notifications marked as read.', $count), ['updated' => $count]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function answer(Request $request, string $message, array $data): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse(array_merge(['message' => $message], $data));
        }

        return back()->with('toast', ['type' => 'success', 'message' => $message]);
    }
}
