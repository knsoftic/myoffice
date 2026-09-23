<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\DataObjects\Institute\SlotCandidate;
use App\DataObjects\Institute\SlotConflict;
use App\DataObjects\Support\CalendarQuery;
use App\DataObjects\Support\MeetingData;
use App\DataObjects\Support\ParticipantInput;
use App\Enums\DeliveryMode;
use App\Enums\MeetingParticipantRole;
use App\Enums\MeetingResponse;
use App\Enums\MeetingStatus;
use App\Enums\PanelType;
use App\Enums\ParticipantType;
use App\Events\Support\MeetingCancelled;
use App\Events\Support\MeetingReminderDue;
use App\Events\Support\MeetingRescheduled;
use App\Events\Support\MeetingScheduled;
use App\Events\Support\MeetingUpdated;
use App\Models\Support\Meeting;
use App\Models\Support\MeetingParticipant;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Institute\ScheduleClashDetector;
use App\Services\Support\Exceptions\SupportRuleException;
use App\Support\Institute\TeacherScope;
use App\Support\ParticipantResolver;
use App\Support\RichText;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The diary: booking, moving, cancelling and recording a meeting (phase-19-23 §6.17, §95).
 *
 * **A meeting holds a room, so it is checked against everything else that holds one.** `create()`,
 * `update()` and `reschedule()` all run one {@see ScheduleClashDetector::check()} — the generic
 * check of phase-14-17 §6.7, not a meetings-only overlap query (D47, audit F-4.7). The parents are
 * locked first and the check is repeated inside the lock, because two people booking the same
 * boardroom in the same second would otherwise both read "free" and both succeed.
 *
 * **A room clash is refused; a clash with no room is a warning.** `support.meeting_room_clash_block`
 * is the switch (PH22-35). The asymmetry is deliberate: two meetings in one room is a physical
 * impossibility somebody will discover at the door, while two meetings a person is double-booked for
 * is an ordinary Tuesday that the person themselves is best placed to resolve.
 *
 * **`participant_type` and the profile foreign keys are derived, never posted** (§6.17), through
 * {@see ParticipantResolver}. A form field for it would let a student be filed as
 * staff, and the §9.4 scope reads that column to decide what they may see.
 *
 * **Moving a meeting resets every acceptance.** An invitation accepted for Tuesday is not an
 * acceptance for Thursday, and a quorum built from stale acceptances is a meeting that nobody turns
 * up to. {@see self::MATERIAL} is the list of changes that count; a corrected typo in the title does
 * not reset twelve people's answers or post twelve notifications.
 *
 * **Nothing is deleted.** A meeting that will not happen is cancelled with a reason, one that moves
 * is postponed and leaves a successor pointing back at it. `cancellation_reason` is required by
 * `chk_me_cancel` at the database for both, because "your 3pm is off" without a reason sends
 * everybody to ask why.
 */
final class MeetingService
{
    use WritesAuditTrail;

    private const MODULE = 'meetings';

    /**
     * The changes that invalidate an acceptance (§6.17 `update()`).
     *
     * Time, length, and *where* — a person who accepted a meeting in the boardroom did not accept
     * one on a video call, and somebody who cleared an hour did not clear two. Everything else is
     * detail they can read when they arrive.
     *
     * @var list<string>
     */
    public const MATERIAL = ['scheduled_at', 'duration_minutes', 'meeting_url', 'location', 'classroom_id', 'delivery_mode'];

    /** A reschedule chain longer than this is a loop, not a history. */
    private const MAX_CHAIN = 50;

    public function __construct(
        private readonly ScheduleClashDetector $clashes,
        private readonly ParticipantResolver $identities,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Booking
    |--------------------------------------------------------------------------
    */

    /**
     * Put a meeting in the diary.
     *
     * @param  list<ParticipantInput>  $participants
     */
    public function create(MeetingData $data, array $participants, User $organizer): Meeting
    {
        $this->assertBookable($data);

        $wanted = $this->normalise($participants, exclude: [(int) $organizer->getKey()]);

        $candidate = $this->candidateFor($data, null);

        // A pre-flight check outside the transaction, so the ordinary refusal costs no lock. The
        // answer that counts is the one taken under the lock below; this one only saves work.
        $this->assertSlotIsFree($candidate, $data);

        [$meeting, $warnings] = DB::transaction(function () use ($data, $wanted, $organizer, $candidate): array {
            // Lock the room and the batch before re-checking, so a second booking of the same slot
            // waits here rather than reading "free" at the same instant.
            $this->clashes->lockParents($candidate);
            $warnings = $this->assertSlotIsFree($candidate, $data);

            $meeting = new Meeting;
            $meeting->fill($data->toAttributes());
            $meeting->organizer_id = $organizer->getKey();
            $meeting->status = MeetingStatus::Scheduled;
            $meeting->branch_id = $data->branchId ?? $organizer->getAttribute('branch_id');
            $meeting->reminder_minutes_before = $data->reminderMinutesBefore
                ?? (int) setting('support.meeting_default_reminder_minutes', 30);
            $meeting->save();

            // The organizer is in the room by definition, and has plainly accepted: they chose the
            // time. Asking them to accept their own invitation would leave every meeting one
            // acceptance short of quorum until somebody clicked a pointless button.
            $this->seat($meeting, ParticipantInput::user(
                (int) $organizer->getKey(),
                MeetingParticipantRole::Organizer,
            ), $organizer, MeetingResponse::Accepted);

            foreach ($wanted as $input) {
                $this->seat($meeting, $input, $this->userFor($input));
            }

            $this->recount($meeting);

            $this->audit($meeting, 'Meeting scheduled', [
                'title' => $meeting->title,
                'scheduled_at' => $meeting->scheduled_at?->toDateTimeString(),
                'duration_minutes' => $meeting->duration_minutes,
                'delivery_mode' => $meeting->getRawOriginal('delivery_mode'),
                'participants' => $meeting->participants_count,
            ], self::MODULE);

            return [$meeting, $warnings];
        });

        MeetingScheduled::dispatch($meeting, (int) $organizer->getKey());

        return $meeting->withClashWarnings($warnings);
    }

    /**
     * Change a meeting that has not happened yet.
     *
     * A cancelled, postponed, completed or missed meeting is history; editing one would rewrite what
     * people were told. The route to a new time is {@see self::reschedule()}, which leaves the trail.
     */
    public function update(Meeting $meeting, MeetingData $data, User $actor): Meeting
    {
        $this->assertBookable($data);
        $this->assertLive($meeting, 'changed');

        $candidate = $this->candidateFor($data, (int) $meeting->getKey());
        $this->assertSlotIsFree($candidate, $data);

        $changed = DB::transaction(function () use ($meeting, $data, $candidate): array {
            $this->clashes->lockParents($candidate);
            $warnings = $this->assertSlotIsFree($candidate, $data);

            /** @var Meeting $locked */
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();

            $before = $locked->only(self::MATERIAL);

            $locked->fill($data->toAttributes());
            $locked->save();

            $material = $this->materialChanges($locked, $before);

            if ($material !== []) {
                $this->resetResponses($locked);
                $this->recount($locked);
            }

            $this->audit($locked, 'Meeting updated', [
                'material_changes' => $material,
                'scheduled_at' => $locked->scheduled_at?->toDateTimeString(),
            ], self::MODULE);

            return [$locked, $material, $warnings];
        });

        [$locked, $material, $warnings] = $changed;

        if ($material !== []) {
            MeetingUpdated::dispatch($locked, $material, (int) $actor->getKey());
        }

        return $locked->withClashWarnings($warnings);
    }

    /**
     * Move a meeting, leaving the old row behind as a `postponed` record with a successor.
     *
     * `uq_me_resched` is unique on `rescheduled_from_id`, so one meeting has at most one successor.
     * Two coordinators rescheduling the same meeting at once therefore produce one new row and one
     * refusal, rather than two successors and a fork in the history.
     */
    public function reschedule(Meeting $meeting, CarbonInterface $to, string $reason, User $actor): Meeting
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw SupportRuleException::reasonRequired(
                'reason',
                'Say why the meeting is moving — everybody invited will be told, and "postponed" on its own only prompts the question.',
            );
        }

        $this->assertLive($meeting, 'rescheduled');

        $data = $this->dataFrom($meeting)->startingAt($to);
        $this->assertBookable($data);

        $candidate = $this->candidateFor($data, (int) $meeting->getKey());
        $this->assertSlotIsFree($candidate, $data);

        $result = DB::transaction(function () use ($meeting, $data, $candidate, $reason): array {
            $this->clashes->lockParents($candidate);
            $warnings = $this->assertSlotIsFree($candidate, $data);

            /** @var Meeting $original */
            $original = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();

            if (! $original->status->isLive()) {
                throw SupportRuleException::refuse('status', 'Somebody has just moved or cancelled this meeting. Reload it to see where it stands.');
            }

            $replacement = new Meeting;
            $replacement->fill($data->toAttributes());
            $replacement->organizer_id = $original->organizer_id;
            $replacement->status = MeetingStatus::Scheduled;
            $replacement->rescheduled_from_id = $original->getKey();
            $replacement->reminder_minutes_before = $original->reminder_minutes_before;

            try {
                $replacement->save();
            } catch (UniqueConstraintViolationException) {
                // uq_me_resched. Somebody else got there first; theirs is the successor.
                throw SupportRuleException::refuse(
                    'scheduled_at',
                    'This meeting has already been moved by somebody else. Open the replacement rather than creating a second one.',
                );
            }

            // The guest list travels, the answers do not: accepting Tuesday was never accepting
            // Thursday. The organizer keeps their acceptance — they chose the new time.
            foreach ($original->participants()->get() as $participant) {
                $this->copySeat($replacement, $participant);
            }

            $this->recount($replacement);

            $original->status = MeetingStatus::Postponed;
            // chk_me_cancel covers `postponed` as well as `cancelled`: a meeting that moved without
            // a stated reason is the same unanswered question as one that vanished.
            $original->cancellation_reason = mb_substr($reason, 0, 255);
            $original->save();

            $this->audit($original, 'Meeting postponed', [
                'replacement_id' => $replacement->getKey(),
                'from' => $original->scheduled_at?->toDateTimeString(),
                'to' => $replacement->scheduled_at?->toDateTimeString(),
            ], self::MODULE, $reason);

            return [$original, $replacement, $warnings];
        });

        [$original, $replacement, $warnings] = $result;

        MeetingRescheduled::dispatch($original, $replacement, $reason, (int) $actor->getKey());

        return $replacement->withClashWarnings($warnings);
    }

    /** Call a meeting off. The reason is mandatory and reaches everybody who was invited. */
    public function cancel(Meeting $meeting, string $reason, User $actor): Meeting
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw SupportRuleException::reasonRequired(
                'reason',
                'Say why the meeting is cancelled — everybody invited is told, and the reason is the whole of that message.',
            );
        }

        $this->assertLive($meeting, 'cancelled');

        $cancelled = DB::transaction(function () use ($meeting, $reason): Meeting {
            /** @var Meeting $locked */
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isLive()) {
                throw SupportRuleException::refuse('status', 'This meeting is no longer in the diary — somebody has just cancelled or moved it.');
            }

            $locked->status = MeetingStatus::Cancelled;
            $locked->cancellation_reason = mb_substr($reason, 0, 255);
            $locked->save();

            $this->audit($locked, 'Meeting cancelled', [
                'scheduled_at' => $locked->scheduled_at?->toDateTimeString(),
                'participants' => $locked->participants_count,
            ], self::MODULE, $reason);

            return $locked;
        });

        MeetingCancelled::dispatch($cancelled, $reason, (int) $actor->getKey());

        return $cancelled;
    }

    /*
    |--------------------------------------------------------------------------
    | The guest list
    |--------------------------------------------------------------------------
    */

    /**
     * Accept, decline or answer "maybe".
     *
     * Only somebody in the room may answer. The policy 404s a non-participant so the meeting's
     * existence is not leaked; this refusal is the backstop for a caller that reached the service
     * another way.
     */
    public function respond(Meeting $meeting, User $user, MeetingResponse $response): MeetingParticipant
    {
        return DB::transaction(function () use ($meeting, $user, $response): MeetingParticipant {
            /** @var Meeting $locked */
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();

            $participant = $locked->participants()->where('user_id', $user->getKey())->first();

            if (! $participant instanceof MeetingParticipant) {
                throw SupportRuleException::refuse('response', 'You are not on the guest list for this meeting, so there is nothing to answer.');
            }

            if (! $locked->status->isLive()) {
                throw SupportRuleException::refuse(
                    'response',
                    'This meeting is '.$locked->status->label().' — there is nothing left to accept or decline.',
                );
            }

            $participant->response = $response;
            $participant->responded_at = $response->isAnswered() ? Carbon::now() : null;
            $participant->save();

            $this->recount($locked);

            $this->audit($locked, 'Meeting invitation answered', [
                'participant_id' => $participant->getKey(),
                'response' => $response->value,
            ], self::MODULE);

            return $participant;
        });
    }

    /**
     * Record who actually turned up, and close the meeting if its time has passed.
     *
     * @param  array<int, bool>  $attended  participant id => present
     */
    public function markAttendance(Meeting $meeting, array $attended, User $actor): Meeting
    {
        return DB::transaction(function () use ($meeting, $attended, $actor): Meeting {
            /** @var Meeting $locked */
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [MeetingStatus::Cancelled, MeetingStatus::Postponed], true)) {
                throw SupportRuleException::refuse(
                    'attendance',
                    'This meeting was '.$locked->status->label().', so there is no attendance to record.',
                );
            }

            $seats = $locked->participants()->get()->keyBy(static fn (MeetingParticipant $p): int => (int) $p->getKey());
            $now = Carbon::now();

            foreach ($attended as $participantId => $present) {
                $participant = $seats->get((int) $participantId);

                if (! $participant instanceof MeetingParticipant) {
                    // A stale form naming somebody already removed. Ignoring it is right: the other
                    // twenty marks are correct and refusing the lot would lose them.
                    continue;
                }

                $participant->attended = (bool) $present;
                $participant->attendance_marked_at = $now;
                $participant->attendance_marked_by = $actor->getKey();
                $participant->save();
            }

            // A meeting whose time has passed and whose attendance has been taken is over. Leaving
            // it `scheduled` would put it on everybody's "upcoming" tile for ever, and the nightly
            // sweep would eventually mark as `missed` a meeting somebody had already written up.
            if ($locked->status === MeetingStatus::Scheduled && $locked->isOverdue()) {
                $locked->status = MeetingStatus::Completed;
            }

            $this->recount($locked);

            $this->audit($locked, 'Meeting attendance recorded', [
                'marked' => count($attended),
                'attended' => $locked->attended_count,
                'status' => $locked->getRawOriginal('status'),
            ], self::MODULE);

            return $locked;
        });
    }

    /**
     * Invite more people.
     *
     * @param  list<ParticipantInput>  $participants
     */
    public function addParticipants(Meeting $meeting, array $participants, User $actor): Meeting
    {
        $this->assertLive($meeting, 'added to');

        $existing = $meeting->participants()->get();
        $seated = $existing
            ->map(static fn (MeetingParticipant $p): string => $p->user_id !== null
                ? 'user:'.$p->user_id
                : 'external:'.$p->external_email)
            ->all();

        $wanted = array_values(array_filter(
            $this->normalise($participants),
            static fn (ParticipantInput $input): bool => ! in_array($input->identity(), $seated, true),
        ));

        if ($wanted === []) {
            return $meeting;
        }

        return DB::transaction(function () use ($meeting, $wanted, $actor): Meeting {
            /** @var Meeting $locked */
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();

            foreach ($wanted as $input) {
                $this->seat($locked, $input, $this->userFor($input));
            }

            $this->recount($locked);

            $this->audit($locked, 'Meeting participants added', [
                'added' => array_map(static fn (ParticipantInput $i): string => $i->identity(), $wanted),
                'participants' => $locked->participants_count,
                'by' => $actor->getKey(),
            ], self::MODULE);

            return $locked;
        });
    }

    /**
     * Take somebody off the guest list, with a reason on the record.
     *
     * **A removed participant loses access the moment this returns**, because every meeting scope
     * reads `meeting_participants` live rather than a snapshot taken when they were invited.
     */
    public function removeParticipant(Meeting $meeting, MeetingParticipant $participant, string $reason, User $actor): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw SupportRuleException::reasonRequired(
                'reason',
                'Say why this person is being taken off the guest list — they will notice, and the record should be able to answer them.',
            );
        }

        if ((int) $participant->meeting_id !== (int) $meeting->getKey()) {
            throw SupportRuleException::refuse('participant', 'That person is not on this meeting.');
        }

        if ($participant->role === MeetingParticipantRole::Organizer) {
            throw SupportRuleException::refuse(
                'participant',
                'The organiser cannot be removed from their own meeting. Cancel it, or hand it over by rescheduling under a new organiser.',
            );
        }

        DB::transaction(function () use ($meeting, $participant, $reason, $actor): void {
            /** @var Meeting $locked */
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();

            $this->audit($locked, 'Meeting participant removed', [
                'participant_id' => $participant->getKey(),
                'name' => $participant->displayName(),
                'by' => $actor->getKey(),
            ], self::MODULE, $reason);

            $participant->delete();

            $this->recount($locked);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | What happened
    |--------------------------------------------------------------------------
    */

    /**
     * Write the minutes.
     *
     * **A portal participant never writes notes** (§6.17, PH22-38). A client, student or
     * collaborator reads the minutes of a meeting they attended; the record of what was decided is
     * kept by the people who keep records. The check is here rather than only in the policy because
     * `Gate::before` waves a Super Admin past a policy, and a rule that matters cannot live
     * somewhere that can be walked past (D124, D140).
     */
    public function saveNotes(Meeting $meeting, ?string $notes, User $actor, ?string $outcomeSummary = null): Meeting
    {
        $isOrganizer = (int) $meeting->organizer_id === (int) $actor->getKey();

        if (! $isOrganizer && ! $actor->can('meetings.edit')) {
            throw SupportRuleException::refuse(
                'notes',
                'Only the organiser, or somebody who may edit meetings, writes the notes for one.',
            );
        }

        $panels = $actor->panels()->map(static fn (PanelType $panel): string => $panel->value)->all();
        $writes = array_intersect([PanelType::Admin->value, PanelType::Teacher->value], $panels);

        if ($writes === []) {
            throw SupportRuleException::refuse(
                'notes',
                'The minutes of a meeting are written by staff. You can read them here once they are published.',
            );
        }

        return DB::transaction(function () use ($meeting, $notes, $outcomeSummary): Meeting {
            /** @var Meeting $locked */
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();

            $locked->notes = RichText::sanitize($notes, 'cms');

            if ($outcomeSummary !== null) {
                $summary = trim($outcomeSummary);
                $locked->outcome_summary = $summary === '' ? null : mb_substr($summary, 0, 500);
            }

            $locked->save();

            $this->audit($locked, 'Meeting notes saved', [
                'length' => mb_strlen((string) $locked->notes),
            ], self::MODULE);

            return $locked;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * The month / week / day / agenda feed, scoped per §9.4.
     *
     * @return EloquentCollection<int, Meeting>
     */
    public function calendar(CalendarQuery $query, User $user): EloquentCollection
    {
        $builder = $this->visibleTo(Meeting::query(), $user)
            ->whereBetween('scheduled_at', [$query->from, $query->to])
            ->with(['organizer:id,name', 'classroom:id,name', 'branch:id,name'])
            ->orderBy('scheduled_at');

        if ($query->statuses !== []) {
            $builder->whereIn('status', $query->statusValues());
        }

        foreach ([
            'branch_id' => $query->branchId,
            'classroom_id' => $query->classroomId,
            'project_id' => $query->projectId,
            'batch_id' => $query->batchId,
            'client_id' => $query->clientId,
            'organizer_id' => $query->organizerId,
        ] as $column => $value) {
            if ($value !== null) {
                $builder->where($column, $value);
            }
        }

        if ($query->search !== null) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $query->search).'%';
            $builder->where(fn (Builder $q) => $q->where('title', 'like', $term)->orWhere('location', 'like', $term));
        }

        if ($query->mineOnly) {
            $builder->involving($user);
        }

        return $builder->get();
    }

    /**
     * The "upcoming meetings" tile, shared by all five panels and by the project detail page.
     *
     * One query, one definition (INV-23-1). A second "upcoming" written for a panel would disagree
     * with this one the first time either changed.
     *
     * @return EloquentCollection<int, Meeting>
     */
    public function upcomingFor(User $user, int $limit = 5): EloquentCollection
    {
        return $this->visibleTo(Meeting::query(), $user)
            ->involving($user)
            ->where('status', MeetingStatus::Scheduled->value)
            ->where('scheduled_at', '>=', Carbon::now()->subMinutes(15))
            ->with(['organizer:id,name', 'classroom:id,name'])
            ->orderBy('scheduled_at')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * The RFC 5545 `VEVENT` for one viewer.
     *
     * **The viewer is the only `ATTENDEE`.** A calendar file listing twenty colleagues' email
     * addresses is a contact list, and it would be handed to anybody invited — including an outside
     * guest, who would receive the internal address book as a side effect of accepting a meeting.
     *
     * **`SEQUENCE` counts the reschedules.** RFC 5545 uses it to decide whether an update supersedes
     * what a calendar already holds; a file that always said `0` would be ignored as a duplicate of
     * the original, and the new time would never appear.
     */
    public function ics(Meeting $meeting, User $viewer): Response
    {
        $body = $this->icsBody($meeting, $viewer);

        return new Response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            // The filename carries the id so a diary full of downloads is still navigable.
            'Content-Disposition' => 'attachment; filename="meeting-'.$meeting->getKey().'.ics"',
            // A calendar file is a snapshot of a mutable thing; a cached copy would hand somebody
            // the time the meeting used to be at.
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    /**
     * The `VEVENT` text on its own — what {@see self::ics()} wraps, and what a test can read.
     *
     * Separate from the `Response` so an assertion about the file's contents does not have to
     * unwrap an HTTP object to make it, and so a future mail attachment can reuse the body.
     */
    public function icsBody(Meeting $meeting, User $viewer): string
    {
        if (! (bool) setting('support.meeting_ics_enabled', true)) {
            throw SupportRuleException::refuse('ics', 'Calendar downloads are switched off for this installation.');
        }

        $host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
        $start = CarbonImmutable::parse($meeting->scheduled_at)->utc();
        $end = $start->addMinutes((int) $meeting->duration_minutes);

        $description = trim(implode("\n\n", array_filter([
            // The agenda is sanitised HTML; a calendar shows text, so the tags come out here.
            trim(html_entity_decode(strip_tags((string) $meeting->agenda), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            $meeting->meeting_url !== null ? 'Join: '.$meeting->meeting_url : null,
        ])));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.$this->fold((string) config('app.name', 'Office')).'//Meetings//EN',
            'CALSCALE:GREGORIAN',
            // REQUEST would ask the recipient's client to send a reply we have nowhere to receive;
            // PUBLISH says "here is the event", which is what a download button means.
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:meeting-'.$meeting->getKey().'@'.$host,
            'SEQUENCE:'.$this->sequenceOf($meeting),
            'DTSTAMP:'.CarbonImmutable::now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$start->format('Ymd\THis\Z'),
            'DTEND:'.$end->format('Ymd\THis\Z'),
            'SUMMARY:'.$this->escape((string) $meeting->title),
            'STATUS:'.($meeting->status === MeetingStatus::Cancelled ? 'CANCELLED' : 'CONFIRMED'),
        ];

        $location = $meeting->delivery_mode === DeliveryMode::Online
            ? (string) $meeting->meeting_url
            : (string) ($meeting->location ?? $meeting->classroom?->name ?? '');

        if ($location !== '') {
            $lines[] = 'LOCATION:'.$this->escape($location);
        }

        if ($description !== '') {
            $lines[] = 'DESCRIPTION:'.$this->escape($description);
        }

        $organizer = $meeting->organizer;

        if ($organizer !== null && $organizer->email !== null) {
            $lines[] = 'ORGANIZER;CN='.$this->escape((string) $organizer->name).':mailto:'.$organizer->email;
        }

        if ($viewer->email !== null) {
            $lines[] = 'ATTENDEE;CN='.$this->escape((string) $viewer->name).';ROLE=REQ-PARTICIPANT:mailto:'.$viewer->email;
        }

        $reminder = (int) ($meeting->reminder_minutes_before ?? 0);

        if ($reminder > 0) {
            $lines = array_merge($lines, [
                'BEGIN:VALARM',
                'ACTION:DISPLAY',
                'DESCRIPTION:'.$this->escape((string) $meeting->title),
                'TRIGGER:-PT'.$reminder.'M',
                'END:VALARM',
            ]);
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        // RFC 5545 §3.1: CRLF, and no line over 75 octets.
        return implode("\r\n", array_map(fn (string $line): string => $this->fold($line), $lines))."\r\n";
    }

    /**
     * §9.4's meeting clause, as one builder.
     *
     * The order matters: the widest right comes first, so somebody holding both a portal profile and
     * `meetings.view_any` is not narrowed to their portal rows.
     *
     * @param  Builder<Meeting>  $query
     * @return Builder<Meeting>
     */
    public function visibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('meetings.view_any')) {
            $branchId = $user->getAttribute('branch_id');

            // D11: institute staff see their own branch. A meeting with no branch is everybody's.
            return $branchId === null
                ? $query
                : $query->where(fn (Builder $q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'));
        }

        $id = (int) $user->getKey();
        $batchIds = $this->teacherBatchIds($user);

        return $query->where(function (Builder $outer) use ($id, $batchIds, $user): void {
            $outer->where('organizer_id', $id)
                ->orWhereHas('participants', fn (Builder $p) => $p->where('user_id', $id));

            // A teacher also sees the meetings about their own batches, whether or not somebody
            // remembered to invite them — a meeting about a batch is a meeting its teacher is in.
            if ($batchIds !== []) {
                $outer->orWhereIn('batch_id', $batchIds);
            }

            // A collaborator sees their own firm's meetings, but only where they are in the room:
            // a second login of the same partner firm is not the same person (§9.4).
            $collaboratorId = $this->collaboratorIdOf($user);

            if ($collaboratorId !== null) {
                $outer->orWhere(fn (Builder $q) => $q->where('collaborator_id', $collaboratorId)
                    ->whereHas('participants', fn (Builder $p) => $p->where('user_id', $user->getKey())));
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | The sweeps (§10.5)
    |--------------------------------------------------------------------------
    */

    /**
     * `meetings:send-reminders`, every five minutes.
     *
     * **The stamp goes inside the transaction and the notification after it**, which is the
     * `crm:follow-up-reminders` pattern and the only arrangement a crash cannot turn into a double
     * send. Stamping after dispatch would re-send everything the next run; dispatching inside the
     * transaction would send a reminder for a row a rollback removed.
     *
     * **`skipLocked` rather than waiting.** Two workers reaching the same meeting is ordinary on a
     * five-minute schedule; the second one skipping it and doing useful work beats it blocking.
     *
     * **A declined participant is not reminded.** They said they were not coming; reminding them is
     * the system arguing.
     *
     * @return int how many meetings were reminded about
     */
    public function sendDueReminders(?CarbonImmutable $asOf = null, int $limit = 200): int
    {
        $asOf ??= CarbonImmutable::now();
        $limit = max(1, min(1000, $limit));

        $due = Meeting::query()
            ->where('status', MeetingStatus::Scheduled->value)
            ->whereNull('reminder_sent_at')
            ->whereNotNull('reminder_minutes_before')
            ->where('reminder_minutes_before', '>', 0)
            ->where('scheduled_at', '>', $asOf)
            ->whereRaw('`scheduled_at` <= ? + INTERVAL `reminder_minutes_before` MINUTE', [$asOf])
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        if ($due === []) {
            return 0;
        }

        $reminded = [];

        foreach ($due as $id) {
            DB::transaction(function () use ($id, $asOf, &$reminded): void {
                $meeting = Meeting::query()
                    ->whereKey($id)
                    ->whereNull('reminder_sent_at')
                    ->lockForUpdate()
                    ->first();

                if (! $meeting instanceof Meeting) {
                    return;
                }

                $meeting->forceFill(['reminder_sent_at' => $asOf])->save();
                $reminded[] = $meeting;
            });
        }

        foreach ($reminded as $meeting) {
            MeetingReminderDue::dispatch($meeting);
        }

        return count($reminded);
    }

    /**
     * `meetings:close-past`, hourly.
     *
     * A meeting whose time passed more than two hours ago is over, whatever the diary still says.
     * **Attendance decides which ending it gets**: somebody marked the register, so it happened —
     * `completed`. Nobody did, and nobody ever will now — `missed`.
     *
     * Two hours rather than zero because a meeting that overruns is still a meeting, and marking it
     * missed while people are in the room is the kind of thing that teaches everybody to ignore the
     * status column.
     *
     * @return array{completed: int, missed: int}
     */
    public function closePast(?CarbonImmutable $asOf = null, int $limit = 500): array
    {
        $asOf ??= CarbonImmutable::now();
        $cutoff = $asOf->subHours(2);
        $limit = max(1, min(5000, $limit));

        $ids = Meeting::query()
            ->where('status', MeetingStatus::Scheduled->value)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $cutoff)
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $completed = 0;
        $missed = 0;

        foreach (array_chunk($ids, 50) as $chunk) {
            DB::transaction(function () use ($chunk, &$completed, &$missed): void {
                $meetings = Meeting::query()
                    ->whereIn('id', $chunk)
                    ->where('status', MeetingStatus::Scheduled->value)
                    ->lockForUpdate()
                    ->get();

                foreach ($meetings as $meeting) {
                    $attended = DB::table('meeting_participants')
                        ->where('meeting_id', $meeting->getKey())
                        ->whereNotNull('attendance_marked_at')
                        ->exists();

                    $meeting->status = $attended ? MeetingStatus::Completed : MeetingStatus::Missed;
                    $meeting->save();

                    $attended ? $completed++ : $missed++;

                    $this->audit($meeting, $attended ? 'Meeting closed as completed' : 'Meeting closed as missed', [
                        'attributes' => ['status' => $meeting->getRawOriginal('status')],
                    ], self::MODULE);
                }
            });
        }

        return ['completed' => $completed, 'missed' => $missed];
    }

    /** Re-derive the four cached counts from the participant rows. */
    public function recount(Meeting $meeting): void
    {
        $counts = DB::table('meeting_participants')
            ->where('meeting_id', $meeting->getKey())
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(response = ?) AS accepted', [MeetingResponse::Accepted->value])
            ->selectRaw('SUM(response = ?) AS declined', [MeetingResponse::Declined->value])
            ->selectRaw('SUM(attended = 1) AS attended')
            ->first();

        $meeting->participants_count = (int) ($counts->total ?? 0);
        $meeting->accepted_count = (int) ($counts->accepted ?? 0);
        $meeting->declined_count = (int) ($counts->declined ?? 0);
        $meeting->attended_count = (int) ($counts->attended ?? 0);
        $meeting->save();

        // The relation was loaded before the counts moved; a caller reading it now would see the
        // old guest list beside the new totals.
        $meeting->unsetRelation('participants');
    }

    // ===============================================================================================

    /**
     * The rules a form can break that the database would answer with a 4025.
     *
     * Saying it here means the person is told which field and why, rather than being shown a
     * constraint name.
     */
    private function assertBookable(MeetingData $data): void
    {
        if ($data->title === '') {
            throw SupportRuleException::refuse('title', 'Give the meeting a title — it is what everybody sees in their diary.');
        }

        if ($data->durationMinutes < 5 || $data->durationMinutes > 1440) {
            throw SupportRuleException::refuse(
                'duration_minutes',
                'A meeting runs between 5 minutes and a full day. '.$data->durationMinutes.' minutes is outside that.',
            );
        }

        if ($data->deliveryMode === DeliveryMode::Online && $data->meetingUrl === null) {
            throw SupportRuleException::refuse(
                'meeting_url',
                'An online meeting needs a joining link, or the invitation tells people to be nowhere.',
            );
        }

        if ($data->deliveryMode->needsClassroom() && $data->classroomId === null && $data->location === null) {
            throw SupportRuleException::refuse(
                'location',
                'Say where the meeting is — a room, or an address.',
            );
        }
    }

    private function assertLive(Meeting $meeting, string $verb): void
    {
        if ($meeting->status->isLive()) {
            return;
        }

        throw SupportRuleException::refuse(
            'status',
            'This meeting is '.$meeting->status->label().' and cannot be '.$verb.'. '
                .($meeting->status === MeetingStatus::Postponed
                    ? 'Open the replacement instead.'
                    : 'Schedule a new one if it needs to happen.'),
        );
    }

    private function candidateFor(MeetingData $data, ?int $ignoreId): SlotCandidate
    {
        return new SlotCandidate(
            classroomId: $data->occupiedClassroomId(),
            batchId: $data->batchId,
            startsAt: $data->scheduledAt,
            endsAt: $data->endsAt(),
            ignoreType: 'meeting',
            ignoreId: $ignoreId,
            deliveryMode: $data->deliveryMode,
        );
    }

    /**
     * Refuse a room clash when the institute says so; otherwise hand the conflicts back as warnings.
     *
     * A batch clash is never a warning — `ClashReport` marks those non-overridable, because students
     * cannot be in two places and nobody is going to resolve it at the door (D-IN-14).
     *
     * @return list<SlotConflict>
     */
    private function assertSlotIsFree(SlotCandidate $candidate, MeetingData $data): array
    {
        $report = $this->clashes->check($candidate);

        if ($report->clean) {
            return [];
        }

        $blocking = array_values(array_filter(
            $report->conflicts,
            static fn (SlotConflict $conflict): bool => ! $conflict->isOverridable(),
        ));

        $roomClash = array_values(array_filter(
            $report->conflicts,
            static fn (SlotConflict $conflict): bool => $conflict->dimension === SlotConflict::CLASSROOM,
        ));

        if ($roomClash !== [] && $data->occupiedClassroomId() !== null && (bool) setting('support.meeting_room_clash_block', true)) {
            $blocking = array_merge($blocking, $roomClash);
        }

        if ($blocking !== []) {
            throw SupportRuleException::withMessages([
                'scheduled_at' => array_map(static fn (SlotConflict $c): string => $c->message(), $this->unique($blocking)),
            ]);
        }

        return $report->conflicts;
    }

    /**
     * @param  list<SlotConflict>  $conflicts
     * @return list<SlotConflict>
     */
    private function unique(array $conflicts): array
    {
        $seen = [];
        $out = [];

        foreach ($conflicts as $conflict) {
            $key = $conflict->type.':'.$conflict->id;

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $conflict;
            }
        }

        return $out;
    }

    /**
     * Drop the empty lines, the duplicates and the people who may not be here at all.
     *
     * @param  list<ParticipantInput>  $participants
     * @param  list<int>  $exclude
     * @return list<ParticipantInput>
     */
    private function normalise(array $participants, array $exclude = []): array
    {
        $allowExternal = (bool) setting('support.meeting_allow_external_participants', true);
        $seen = [];
        $out = [];

        foreach ($participants as $input) {
            if (! $input instanceof ParticipantInput) {
                continue;
            }

            if ($input->isExternal() && ! $allowExternal) {
                throw SupportRuleException::refuse(
                    'participants',
                    'Outside guests are switched off for this installation. Everybody in the room needs an account here.',
                );
            }

            $problem = $input->problem();

            if ($problem !== null) {
                throw SupportRuleException::refuse('participants', $problem);
            }

            if (! $input->isExternal() && in_array((int) $input->userId, $exclude, true)) {
                continue;
            }

            // `uq_mp_user` and `uq_mp_external` would refuse the second row anyway; dropping it here
            // means one duplicated line does not lose the whole guest list.
            if (isset($seen[$input->identity()])) {
                continue;
            }

            $seen[$input->identity()] = true;
            $out[] = $input;
        }

        return $out;
    }

    private function userFor(ParticipantInput $input): ?User
    {
        if ($input->isExternal()) {
            return null;
        }

        $user = User::query()->find($input->userId);

        if (! $user instanceof User) {
            throw SupportRuleException::refuse('participants', 'One of the people invited no longer has an account here.');
        }

        return $user;
    }

    /** Write one participant row, with the identity columns derived rather than posted. */
    private function seat(
        Meeting $meeting,
        ParticipantInput $input,
        ?User $user,
        MeetingResponse $response = MeetingResponse::Pending,
    ): MeetingParticipant {
        $participant = new MeetingParticipant;
        $participant->meeting_id = $meeting->getKey();

        $columns = $user instanceof User
            ? $this->identities->columnsFor($user)
            : [
                'participant_type' => ParticipantType::External->value,
                'user_id' => null,
                'client_id' => null,
                'student_id' => null,
                'teacher_id' => null,
                'collaborator_id' => null,
                'employee_id' => null,
                'external_name' => $input->externalName,
                'external_email' => $input->externalEmail,
            ];

        foreach ($columns as $column => $value) {
            $participant->setAttribute($column, $value);
        }

        $participant->role = $input->role;
        $participant->response = $response;
        $participant->responded_at = $response->isAnswered() ? Carbon::now() : null;
        $participant->notes = $input->notes;
        $participant->save();

        return $participant;
    }

    /** Copy a seat onto the successor of a rescheduled meeting, answers cleared. */
    private function copySeat(Meeting $replacement, MeetingParticipant $original): void
    {
        $copy = new MeetingParticipant;
        $copy->meeting_id = $replacement->getKey();

        foreach ([
            'participant_type', 'user_id', 'client_id', 'student_id', 'teacher_id',
            'collaborator_id', 'employee_id', 'external_name', 'external_email', 'notes',
        ] as $column) {
            $copy->setAttribute($column, $original->getAttribute($column));
        }

        $copy->role = $original->role;

        $isOrganizer = $original->role === MeetingParticipantRole::Organizer;
        $copy->response = $isOrganizer ? MeetingResponse::Accepted : MeetingResponse::Pending;
        $copy->responded_at = $isOrganizer ? Carbon::now() : null;
        $copy->save();
    }

    /**
     * Clear every acceptance but the organizer's.
     *
     * The organizer chose the new time, so treating them as undecided about their own meeting would
     * leave every rescheduled meeting one answer short of quorum until they clicked "accept" on
     * something they had just arranged.
     */
    private function resetResponses(Meeting $meeting): void
    {
        DB::table('meeting_participants')
            ->where('meeting_id', $meeting->getKey())
            ->where('role', '!=', MeetingParticipantRole::Organizer->value)
            ->update([
                'response' => MeetingResponse::Pending->value,
                'responded_at' => null,
                'notified_at' => null,
                'reminder_sent_at' => null,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Which of {@see self::MATERIAL} actually moved.
     *
     * Compared as strings through the raw values, because `scheduled_at` is a Carbon on one side of
     * a save and a string on the other, and `!==` on two Carbons of the same instant is true.
     *
     * @param  array<string, mixed>  $before
     * @return list<string>
     */
    private function materialChanges(Meeting $meeting, array $before): array
    {
        $changed = [];

        foreach (self::MATERIAL as $field) {
            if ($this->scalar($before[$field] ?? null) !== $this->scalar($meeting->getAttribute($field))) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private function scalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }

    /** The meeting's current state as a {@see MeetingData}, for a reschedule to start from. */
    private function dataFrom(Meeting $meeting): MeetingData
    {
        return new MeetingData(
            title: (string) $meeting->title,
            scheduledAt: CarbonImmutable::parse($meeting->scheduled_at),
            durationMinutes: (int) $meeting->duration_minutes,
            deliveryMode: $meeting->delivery_mode,
            location: $meeting->location,
            meetingUrl: $meeting->meeting_url,
            agenda: $meeting->agenda,
            branchId: $meeting->branch_id,
            classroomId: $meeting->classroom_id,
            projectId: $meeting->project_id,
            courseId: $meeting->course_id,
            batchId: $meeting->batch_id,
            clientId: $meeting->client_id,
            leadId: $meeting->lead_id,
            collaboratorId: $meeting->collaborator_id,
            supportTicketId: $meeting->support_ticket_id,
            isPrivate: (bool) $meeting->is_private,
            reminderMinutesBefore: $meeting->reminder_minutes_before,
        );
    }

    /**
     * How many times this meeting has already moved.
     *
     * Walks `rescheduled_from_id` backwards. The bound is a guard, not an expectation: the column is
     * unique so a cycle cannot form through the normal path, and a meeting that has genuinely moved
     * fifty times has a bigger problem than its `.ics`.
     */
    private function sequenceOf(Meeting $meeting): int
    {
        $sequence = 0;
        $previous = $meeting->rescheduled_from_id;

        while ($previous !== null && $sequence < self::MAX_CHAIN) {
            $sequence++;
            $previous = DB::table('meetings')->where('id', $previous)->value('rescheduled_from_id');
        }

        return $sequence;
    }

    /**
     * @return list<int>
     */
    private function teacherBatchIds(User $user): array
    {
        $teacherId = DB::table('teachers')
            ->where('user_id', $user->getKey())
            ->whereNull('deleted_at')
            ->value('id');

        return $teacherId === null ? [] : TeacherScope::batchIds((int) $teacherId);
    }

    private function collaboratorIdOf(User $user): ?int
    {
        $id = DB::table('collaborators')
            ->where('user_id', $user->getKey())
            ->whereNull('deleted_at')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /** RFC 5545 §3.3.11 — the four characters that need a backslash inside a property value. */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\n", "\r", ';', ','],
            ['\\\\', '\\n', '', '\\;', '\\,'],
            $value,
        );
    }

    /**
     * RFC 5545 §3.1 — no content line over 75 octets; continuations begin with one space.
     *
     * Folded on **octets, not characters**, and never inside a multi-byte sequence: splitting a
     * UTF-8 character across two lines produces a file that some calendars refuse and others render
     * as a replacement glyph in the middle of a name.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $current = '';

        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $limit = $out === '' ? 75 : 74;

            if (strlen($current) + strlen($character) > $limit) {
                $out .= ($out === '' ? '' : "\r\n ").$current;
                $current = '';
            }

            $current .= $character;
        }

        return $out.($out === '' ? '' : "\r\n ").$current;
    }
}
