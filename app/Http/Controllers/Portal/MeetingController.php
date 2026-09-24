<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\MeetingResponse;
use App\Enums\MeetingStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ServesPortalSupport;
use App\Models\Support\Meeting;
use App\Services\Support\MeetingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A portal's own diary — `{panel}.meetings.*` (phase-19-23 §7.6, §6.17, §9.4).
 *
 * **Being in the room is the whole right.** A client invited to a kick-off holds no `meetings.*`
 * permission at all; their access runs through `client_portal.meetings` and their *membership*.
 * `MeetingService::visibleTo()` is the scope, the same one the admin calendar uses, so the two
 * cannot disagree about which meetings exist.
 *
 * **Accept, decline, maybe — and nothing else.** A portal user does not reschedule, cancel, invite
 * or take the register. The only other thing they get is the `.ics`, which is what makes an
 * invitation useful at all.
 *
 * **Notes appear only on a completed meeting** (§9.4, PH22-38). Working notes taken during one are
 * not minutes, and a client reading half a sentence about their own project is worse than waiting
 * a day. `MeetingPolicy::viewNotes()` decides; this screen only asks.
 */
final class MeetingController extends Controller
{
    use ServesPortalSupport;

    public function __construct(
        private readonly MeetingService $meetings,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Meeting::class);

        $user = $request->user();
        $past = $request->string('when')->toString() === 'past';

        $meetings = $this->meetings->visibleTo(Meeting::query(), $user)
            // A portal list is *my* meetings, always — a teacher's batch clause belongs to the
            // admin calendar, where it answers a different question.
            ->involving($user)
            ->with(['organizer:id,name', 'classroom:id,name'])
            ->when($past, fn ($q) => $q->where('scheduled_at', '<', now())->orderByDesc('scheduled_at'))
            ->when(! $past, fn ($q) => $q->where('scheduled_at', '>=', now()->startOfDay())->orderBy('scheduled_at'))
            ->paginate(15)
            ->withQueryString();

        return view($this->screen($request, 'meetings.index'), [
            'meetings' => $meetings,
            'statuses' => MeetingStatus::cases(),
            'panel' => $this->panel($request),
            'past' => $past,
        ]);
    }

    public function show(Request $request, Meeting $meeting): View
    {
        Gate::authorize('view', $meeting);

        $user = $request->user();

        $mine = $meeting->participants()->where('user_id', $user->getKey())->first();

        return view($this->screen($request, 'meetings.show'), [
            'meeting' => $meeting->load(['organizer:id,name', 'classroom:id,name', 'participants.user:id,name']),
            'mine' => $mine,
            'responses' => MeetingResponse::cases(),
            'panel' => $this->panel($request),
            'canRespond' => (bool) $user?->can('respond', $meeting),
            'canViewNotes' => (bool) $user?->can('viewNotes', $meeting),
            'canDownloadIcs' => (bool) $user?->can('downloadIcs', $meeting),
        ]);
    }

    public function respond(Request $request, Meeting $meeting): RedirectResponse
    {
        Gate::authorize('respond', $meeting);

        $response = MeetingResponse::tryFrom((string) $request->input('response'));

        if ($response === null) {
            throw ValidationException::withMessages(['response' => 'Accept, decline, or say maybe.']);
        }

        $this->meetings->respond($meeting, $request->user(), $response);

        return back()->with('toast', [
            'type' => 'success',
            'message' => match ($response) {
                MeetingResponse::Accepted => 'Accepted. It is in your diary.',
                MeetingResponse::Declined => 'Declined. The organiser has been told.',
                default => 'Answer recorded.',
            },
        ]);
    }

    public function ics(Request $request, Meeting $meeting): Response
    {
        Gate::authorize('downloadIcs', $meeting);

        return $this->meetings->ics($meeting, $request->user());
    }
}
