<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Support;

use App\DataObjects\Support\CalendarQuery;
use App\DataObjects\Support\MeetingData;
use App\DataObjects\Support\ParticipantInput;
use App\Enums\MeetingParticipantRole;
use App\Enums\MeetingResponse;
use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Support\StoreMeetingRequest;
use App\Http\Requests\Admin\Support\UpdateMeetingRequest;
use App\Models\Institute\Classroom;
use App\Models\Support\Meeting;
use App\Models\Support\MeetingParticipant;
use App\Models\User;
use App\Services\Support\MeetingService;
use App\Support\RichText;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The diary — `admin.meetings.*` (phase-19-23 §7.6, §8, §9.4).
 *
 * **A clash the institute allowed is shown, not swallowed.** `MeetingService` refuses a room
 * double-booking and hands back the rest as warnings; this turns them into a second toast naming
 * each conflict, because a booking that quietly overlapped somebody else's is one nobody discovers
 * until they are both standing in the corridor.
 *
 * **The list and the calendar are the same scope, asked twice.** Both go through
 * `MeetingService::visibleTo()`, so a teacher's calendar and a teacher's list cannot disagree about
 * which meetings exist.
 */
final class MeetingController extends Controller
{
    public function __construct(
        private readonly MeetingService $meetings,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Meeting::class);

        $user = $request->user();

        $meetings = $this->meetings->visibleTo(Meeting::query(), $user)
            ->with(['organizer:id,name', 'classroom:id,name'])
            ->when($request->string('q')->toString() !== '', function ($query) use ($request): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('q')->toString()).'%';
                $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('location', 'like', $term));
            })
            ->when($request->string('status')->toString() !== '', fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->boolean('mine'), fn ($q) => $q->involving($user))
            ->when($request->string('when')->toString() === 'past', fn ($q) => $q->where('scheduled_at', '<', now()))
            ->when($request->string('when')->toString() !== 'past', fn ($q) => $q->where('scheduled_at', '>=', now()->startOfDay()))
            ->orderBy('scheduled_at', $request->string('when')->toString() === 'past' ? 'desc' : 'asc')
            ->paginate(20)
            ->withQueryString();

        return view('admin.meetings.index', [
            'meetings' => $meetings,
            'statuses' => MeetingStatus::cases(),
            'canCreate' => (bool) $user?->can('create', Meeting::class),
        ]);
    }

    public function calendar(Request $request): View
    {
        Gate::authorize('viewAny', Meeting::class);

        $query = CalendarQuery::fromArray($request->all());
        $meetings = $this->meetings->calendar($query, $request->user());

        return view('admin.meetings.calendar', [
            'query' => $query,
            'meetings' => $meetings,
            'statuses' => MeetingStatus::cases(),
            'canCreate' => (bool) $request->user()?->can('create', Meeting::class),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Meeting::class);

        return view('admin.meetings.create', [
            'classrooms' => Classroom::query()->orderBy('name')->get(['id', 'name', 'type']),
            'people' => $this->people(),
            'roles' => MeetingParticipantRole::cases(),
            'defaultDuration' => (int) setting('support.meeting_default_duration_minutes', 30),
            'defaultReminder' => (int) setting('support.meeting_default_reminder_minutes', 30),
            'allowExternal' => (bool) setting('support.meeting_allow_external_participants', true),
        ]);
    }

    public function store(StoreMeetingRequest $request): RedirectResponse
    {
        $meeting = $this->meetings->create(
            MeetingData::fromArray($request->validated()),
            $request->participants(),
            $request->user(),
        );

        return redirect()
            ->route('admin.meetings.show', $meeting)
            ->with('toast', ['type' => 'success', 'message' => 'Meeting scheduled.'])
            ->with('clashes', $this->warnings($meeting));
    }

    public function show(Request $request, Meeting $meeting): View
    {
        Gate::authorize('view', $meeting);

        $user = $request->user();

        return view('admin.meetings.show', [
            'meeting' => $meeting->load(['organizer:id,name,email', 'classroom:id,name', 'participants.user:id,name,email']),
            'quorum' => $meeting->quorum(),
            'responses' => MeetingResponse::cases(),
            'roles' => MeetingParticipantRole::cases(),
            'people' => $user?->can('assign', $meeting) ? $this->people() : collect(),
            'canUpdate' => (bool) $user?->can('update', $meeting),
            'canAssign' => (bool) $user?->can('assign', $meeting),
            'canRespond' => (bool) $user?->can('respond', $meeting),
            'canMarkAttendance' => (bool) $user?->can('markAttendance', $meeting),
            'canSaveNotes' => (bool) $user?->can('saveNotes', $meeting),
            'canViewNotes' => (bool) $user?->can('viewNotes', $meeting),
            'canDownloadIcs' => (bool) $user?->can('downloadIcs', $meeting),
            'canDelete' => (bool) $user?->can('delete', $meeting),
            'allowExternal' => (bool) setting('support.meeting_allow_external_participants', true),
        ]);
    }

    public function update(UpdateMeetingRequest $request, Meeting $meeting): RedirectResponse
    {
        $updated = $this->meetings->update($meeting, MeetingData::fromArray($request->validated()), $request->user());

        return back()
            ->with('toast', ['type' => 'success', 'message' => 'Meeting saved.'])
            ->with('clashes', $this->warnings($updated));
    }

    public function reschedule(Request $request, Meeting $meeting): RedirectResponse
    {
        Gate::authorize('reschedule', $meeting);

        $to = $request->string('scheduled_at')->toString();

        if ($to === '') {
            throw ValidationException::withMessages(['scheduled_at' => 'Say when it is moving to.']);
        }

        $replacement = $this->meetings->reschedule(
            $meeting,
            CarbonImmutable::parse($to),
            $request->string('reason')->toString(),
            $request->user(),
        );

        return redirect()
            ->route('admin.meetings.show', $replacement)
            ->with('toast', ['type' => 'success', 'message' => 'Moved. Everybody invited has been told, and their answers have been reset.'])
            ->with('clashes', $this->warnings($replacement));
    }

    /** Cancelling. The one status change a meeting takes from a screen. */
    public function status(Request $request, Meeting $meeting): RedirectResponse
    {
        Gate::authorize('cancel', $meeting);

        $this->meetings->cancel($meeting, $request->string('reason')->toString(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Meeting cancelled, and everybody invited has been told why.']);
    }

    public function addParticipants(Request $request, Meeting $meeting): RedirectResponse
    {
        Gate::authorize('assign', $meeting);

        $this->meetings->addParticipants($meeting, $this->participantsFrom($request), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Invitations sent.']);
    }

    public function removeParticipant(Request $request, Meeting $meeting, MeetingParticipant $participant): RedirectResponse
    {
        Gate::authorize('assign', $meeting);

        $this->meetings->removeParticipant($meeting, $participant, $request->string('reason')->toString(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Taken off the guest list.']);
    }

    public function respond(Request $request, Meeting $meeting): RedirectResponse
    {
        Gate::authorize('respond', $meeting);

        $response = MeetingResponse::tryFrom((string) $request->input('response'));

        if ($response === null) {
            throw ValidationException::withMessages(['response' => 'Accept, decline, or say maybe.']);
        }

        $this->meetings->respond($meeting, $request->user(), $response);

        return back()->with('toast', ['type' => 'success', 'message' => 'Answer recorded.']);
    }

    public function attendance(Request $request, Meeting $meeting): RedirectResponse
    {
        Gate::authorize('markAttendance', $meeting);

        /** @var array<int, bool> $attended */
        $attended = [];

        foreach ((array) $request->input('attended', []) as $id => $present) {
            $attended[(int) $id] = (bool) $present;
        }

        $this->meetings->markAttendance($meeting, $attended, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Attendance recorded.']);
    }

    public function notes(Request $request, Meeting $meeting): RedirectResponse
    {
        Gate::authorize('saveNotes', $meeting);

        $this->meetings->saveNotes(
            $meeting,
            RichText::sanitize($request->string('notes')->toString(), 'cms'),
            $request->user(),
            $request->string('outcome_summary')->toString(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Notes saved.']);
    }

    public function ics(Request $request, Meeting $meeting): Response
    {
        Gate::authorize('downloadIcs', $meeting);

        return $this->meetings->ics($meeting, $request->user());
    }

    public function destroy(Request $request, Meeting $meeting): RedirectResponse
    {
        Gate::authorize('delete', $meeting);

        $meeting->delete();

        return redirect()
            ->route('admin.meetings.index')
            ->with('toast', ['type' => 'success', 'message' => 'Meeting removed from the diary.']);
    }

    // ===============================================================================================

    /**
     * The conflicts the service allowed through, as sentences.
     *
     * @return list<string>
     */
    private function warnings(Meeting $meeting): array
    {
        return array_map(
            static fn ($conflict): string => $conflict->message(),
            $meeting->clashWarnings(),
        );
    }

    /**
     * @return list<ParticipantInput>
     */
    private function participantsFrom(Request $request): array
    {
        $out = [];

        foreach ((array) $request->input('participants', []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $input = ParticipantInput::fromArray($row);

            if ($input !== null) {
                $out[] = $input;
            }
        }

        return $out;
    }

    /**
     * Everybody who could be invited.
     *
     * Deliberately every active user rather than a permission-filtered list: a meeting is the one
     * place in the system where a client, a student and a staff member sit in the same room, and
     * the §94 matrix that governs *messaging* does not govern an invitation.
     *
     * @return Collection<int, User>
     */
    private function people()
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'email']);
    }
}
