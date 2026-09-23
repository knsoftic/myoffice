<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\PanelType;
use App\Enums\Priority;
use App\Enums\ReplyVisibility;
use App\Enums\TicketStatus;
use App\Models\Collaborator\Collaborator;
use App\Models\Hr\Employee;
use App\Models\Institute\Student;
use App\Models\Institute\Teacher;
use App\Models\Support\SupportTicket;
use App\Models\Support\TicketDepartment;
use App\Models\Support\TicketReply;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Finance\DocumentNumberService;
use App\Services\Support\Exceptions\SupportRuleException;
use App\Support\ClientContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Raising, answering and closing a ticket (phase-19-23 §6.16, INV-22-1, INV-22-2, requirement §93).
 *
 * **The subject foreign keys are derived, never accepted.** `client_id` comes from `ClientContext`
 * and the rest from the requester's own profiles, because a column a form could set is a column
 * somebody can file a ticket *as somebody else* with. Nothing on `SupportTicket` that identifies a
 * subject is mass-assignable, and this is the only writer.
 *
 * **A portal user does not choose their own priority** (§12.2 Q6). A client who could declare
 * "urgent" would, every time, and within a month the word would mean nothing. Staff choose per
 * ticket; a portal ticket starts at `support.ticket_default_priority`.
 *
 * **`first_response_at` is the first *public staff* reply and nothing else** (INV-22-2). An internal
 * note is not a response to anybody, and stamping it would let a queue hit its target by talking to
 * itself.
 *
 * **Status transitions follow §2.28.7 and no other path.** The map below is the whole of it: an
 * attempt outside it is refused by name rather than silently ignored, because a status that moved
 * somewhere nobody intended is worse than one that would not move.
 *
 * **Nothing here is ever deleted** (INV-22-1). A wrong ticket is closed; a wrong reply is corrected
 * by another reply. The model refuses both below the gate, since `Gate::before` walks a Super Admin
 * past every policy (D124, D140).
 */
final class TicketService
{
    use WritesAuditTrail;

    private const MODULE = 'support_tickets';

    /**
     * §2.28.7's table, verbatim: from => the statuses it may become.
     *
     * Written as data rather than as a chain of `if`s so the legal paths can be read at a glance and
     * asserted by a test against the contract.
     */
    private const TRANSITIONS = [
        'open' => ['in_progress', 'waiting', 'resolved'],
        'in_progress' => ['waiting', 'resolved', 'open'],
        'waiting' => ['in_progress', 'resolved', 'open'],
        'resolved' => ['closed', 'open'],
        'closed' => ['open'],
    ];

    /** The transitions §2.28.7 marks *reason* mandatory. */
    private const NEEDS_REASON = [
        'closed' => ['open'],
    ];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly TicketSlaService $sla,
        private readonly TicketAssignmentService $assignment,
    ) {}

    /**
     * Raise a ticket. One transaction, and the number is spent inside it.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $requester, ?User $actor = null): SupportTicket
    {
        $actor ??= Auth::user() ?? $requester;

        $panel = $requester->primaryPanel();
        $department = $this->resolveDepartment($data, $panel);

        $this->assertMayRaise($panel, $department);

        $isStaff = $panel === PanelType::Admin;

        // Staff choose; a portal starts at the institute's default. See the class note.
        $priority = $isStaff
            ? (Priority::tryFrom((string) ($data['priority'] ?? '')) ?? Priority::Medium)
            : (Priority::tryFrom((string) setting('support.ticket_default_priority', 'medium')) ?? Priority::Medium);

        $subjects = $this->subjectsFor($requester, $data);

        return $this->numbers->assign(
            'support.ticket_prefix',
            'support.ticket_next_number',
            '%05d',
            function (string $number) use ($data, $requester, $actor, $panel, $department, $priority, $subjects): SupportTicket {
                $ticket = new SupportTicket;
                $ticket->forceFill(array_merge($subjects, [
                    'ticket_number' => $number,
                    'ticket_department_id' => $department->getKey(),
                    'user_id' => $requester->getKey(),
                    'requester_panel' => $panel->value,
                    'subject' => mb_substr(trim((string) ($data['subject'] ?? '')), 0, 255),
                    'description' => (string) ($data['description'] ?? ''),
                    'priority' => $priority->value,
                    'status' => TicketStatus::Open->value,
                    'is_private_to_creator' => (bool) ($data['is_private_to_creator'] ?? false),
                    'tags' => $data['tags'] ?? null,
                ]));
                $ticket->save();

                $this->stampSlaTargets($ticket);
                $this->autoAssign($ticket, $actor);
                $this->recountDepartment($department);

                $this->audit($ticket, 'Ticket raised', [
                    'attributes' => ['ticket_number' => $number, 'department' => $department->getAttribute('name')],
                ], self::MODULE);

                return $ticket->refresh();
            },
            'uq_tk_number',
        );
    }

    /**
     * Add a reply.
     *
     * A portal user's reply is **forced** public — a field the requester controls must not be able
     * to mint a note only staff were meant to read, and validating it would leave the shape of the
     * mistake in the request.
     *
     * @param  array<string, mixed>  $data
     */
    public function reply(SupportTicket $ticket, array $data, User $author): TicketReply
    {
        $panel = $author->primaryPanel();
        $isStaff = $panel === PanelType::Admin;

        if (! $isStaff && ! $ticket->status->requesterCanReply()) {
            throw SupportRuleException::refuse(
                'body',
                'This ticket is closed. Raise a new one and it will reach the same desk.',
            );
        }

        $body = trim((string) ($data['body'] ?? ''));
        $attachments = (int) ($data['attachments_count'] ?? 0);

        if ($body === '' && $attachments < 1) {
            throw SupportRuleException::refuse('body', 'Write something, or attach a file.');
        }

        $visibility = $isStaff
            ? (ReplyVisibility::tryFrom((string) ($data['visibility'] ?? '')) ?? ReplyVisibility::Public)
            : ReplyVisibility::Public;

        return DB::transaction(function () use ($ticket, $body, $attachments, $author, $panel, $isStaff, $visibility): TicketReply {
            $locked = SupportTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            // The first public staff reply is the first response, once. See the class note.
            $isFirstResponse = $isStaff
                && $visibility->reachesRequester()
                && $locked->getAttribute('first_response_at') === null;

            $reply = new TicketReply;
            $reply->forceFill([
                'support_ticket_id' => $locked->getKey(),
                'user_id' => $author->getKey(),
                'panel' => $panel->value,
                'body' => $body === '' ? null : $body,
                'visibility' => $visibility->value,
                'is_first_response' => $isFirstResponse,
                'attachments_count' => $attachments,
            ]);
            $reply->save();

            if ($isFirstResponse) {
                $locked->forceFill([
                    'first_response_at' => Carbon::now(),
                    'first_response_by' => $author->getKey(),
                ])->save();
            }

            $this->reactToReply($locked, $author, $isStaff, $visibility);
            $this->recountCaches($locked);

            return $reply->refresh();
        });
    }

    /** Hand it to an agent, or take it off one. */
    public function assign(SupportTicket $ticket, ?User $agent, User $actor): SupportTicket
    {
        if ($agent !== null && ! $agent->can('support_tickets.view_any')) {
            throw SupportRuleException::refuse(
                'assigned_to',
                sprintf('%s cannot be assigned tickets — they do not have access to the queue.', $agent->getAttribute('name')),
            );
        }

        return DB::transaction(function () use ($ticket, $agent, $actor): SupportTicket {
            $locked = SupportTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'assigned_to' => $agent?->getKey(),
                'assigned_at' => $agent === null ? null : Carbon::now(),
                'assigned_by' => $agent === null ? null : $actor->getKey(),
            ])->save();

            $this->systemReply($locked, $agent === null
                ? 'Unassigned.'
                : sprintf('Assigned to %s.', $agent->getAttribute('name')), 'ticket.assigned');

            $this->audit($locked, 'Ticket assigned', [
                'attributes' => ['assigned_to' => $agent?->getKey()],
            ], self::MODULE);

            return $locked->refresh();
        });
    }

    /**
     * Move the status, along one of §2.28.7's paths and no other.
     *
     * **The SLA pause and resume bracket the write**, in that order, because `chk_tk_waiting` binds
     * `waiting_since` to the status: the column is cleared while the ticket is still waiting and set
     * once it already is. `TicketSlaService` documents the same asymmetry from its side.
     */
    public function changeStatus(SupportTicket $ticket, TicketStatus $to, ?string $reason, User $actor): SupportTicket
    {
        $from = $ticket->status;

        if ($from === $to) {
            return $ticket;
        }

        $legal = self::TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $legal, true)) {
            throw SupportRuleException::refuse('status', sprintf(
                'A %s ticket does not become %s. From here it can be: %s.',
                mb_strtolower($from->label()),
                mb_strtolower($to->label()),
                $this->describe($legal),
            ));
        }

        $reason = trim((string) $reason);

        if (in_array($to->value, self::NEEDS_REASON[$from->value] ?? [], true) && $reason === '') {
            throw SupportRuleException::reasonRequired('reason', sprintf(
                'Say why this closed ticket is being reopened — it was finished, and somebody will want to know what changed.',
            ));
        }

        return DB::transaction(function () use ($ticket, $from, $to, $reason, $actor): SupportTicket {
            $locked = SupportTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            // Leaving `waiting`: give the paused time back before the status moves.
            if ($from === TicketStatus::Waiting) {
                $this->sla->resume($locked);
                $locked->refresh();
            }

            $changes = ['status' => $to->value];

            if ($to === TicketStatus::Resolved) {
                $changes['resolved_at'] = Carbon::now();
                $changes['resolved_by'] = $actor->getKey();
            }

            if ($to === TicketStatus::Closed) {
                $changes['closed_at'] = Carbon::now();
                $changes['closed_by'] = $actor->getKey();
                $changes['closure_reason'] = $reason === '' ? null : mb_substr($reason, 0, 255);
            }

            if ($to === TicketStatus::Open && $from->isResolvedOrClosed()) {
                $changes['reopened_count'] = (int) $locked->getAttribute('reopened_count') + 1;
                $changes['last_reopened_at'] = Carbon::now();
                // A reopened ticket gets a fresh resolution target: the old one was met or missed,
                // and measuring the second attempt against the first would be meaningless.
                $changes['resolved_at'] = null;
                $changes['resolved_by'] = null;
                $changes['resolution_breached'] = false;
            }

            $locked->forceFill($changes)->save();

            // Entering `waiting`: stop the clock now the status says so.
            if ($to === TicketStatus::Waiting) {
                $this->sla->pause($locked->refresh());
            }

            if ($to === TicketStatus::Open && $from->isResolvedOrClosed()) {
                $this->stampSlaTargets($locked->refresh(), resolutionOnly: true);
            }

            $this->systemReply(
                $locked,
                sprintf('Status changed from %s to %s.', $from->label(), $to->label()),
                'ticket.status',
                $from,
                $to,
            );

            $this->audit($locked, 'Ticket status changed', [
                'old' => ['status' => $from->value],
                'attributes' => ['status' => $to->value],
            ], self::MODULE, $reason === '' ? null : $reason);

            return $locked->refresh();
        });
    }

    /** Change the priority. Staff only, reason mandatory, targets recomputed. */
    public function changePriority(SupportTicket $ticket, Priority $to, string $reason, User $actor): SupportTicket
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw SupportRuleException::reasonRequired('reason', 'Say why the priority is changing — it moves the response targets.');
        }

        if ($ticket->priority === $to) {
            return $ticket;
        }

        return DB::transaction(function () use ($ticket, $to, $reason): SupportTicket {
            $locked = SupportTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->priority;

            $locked->forceFill(['priority' => $to->value])->save();

            $this->stampSlaTargets($locked->refresh());

            $this->systemReply($locked, sprintf(
                'Priority changed from %s to %s, so the response targets moved with it.',
                $from->label(),
                $to->label(),
            ), 'ticket.priority');

            $this->audit($locked, 'Ticket priority changed', [
                'old' => ['priority' => $from->value],
                'attributes' => ['priority' => $to->value],
            ], self::MODULE, $reason);

            return $locked->refresh();
        });
    }

    /** Move it to another desk. Reason mandatory, targets recomputed, both counts refreshed. */
    public function changeDepartment(SupportTicket $ticket, TicketDepartment $to, string $reason, User $actor): SupportTicket
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw SupportRuleException::reasonRequired('reason', 'Say why this is moving desk — the new desk will want the context.');
        }

        if ((int) $ticket->getAttribute('ticket_department_id') === (int) $to->getKey()) {
            return $ticket;
        }

        if (! (bool) $to->getAttribute('is_active')) {
            throw SupportRuleException::refuse('ticket_department_id', sprintf(
                'The %s desk has been retired, so nothing new is filed there.',
                $to->getAttribute('name'),
            ));
        }

        return DB::transaction(function () use ($ticket, $to, $reason): SupportTicket {
            $locked = SupportTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $previous = $locked->department;

            $locked->forceFill(['ticket_department_id' => $to->getKey()])->save();

            $this->stampSlaTargets($locked->refresh());

            $this->systemReply($locked, sprintf(
                'Moved from %s to %s.',
                $previous?->getAttribute('name') ?? 'no desk',
                $to->getAttribute('name'),
            ), 'ticket.department');

            if ($previous !== null) {
                $this->recountDepartment($previous);
            }

            $this->recountDepartment($to);

            $this->audit($locked, 'Ticket moved desk', [
                'old' => ['ticket_department_id' => $previous?->getKey()],
                'attributes' => ['ticket_department_id' => $to->getKey()],
            ], self::MODULE, $reason);

            return $locked->refresh();
        });
    }

    /**
     * Recount the four caches under a row lock, and the department's with them.
     *
     * COUNT, never `++`: two replies landing at once would each read the old value and write the
     * same new one, and the ticket would say four replies when it has five.
     */
    public function recountCaches(SupportTicket $ticket): void
    {
        $replies = TicketReply::query()->where('support_ticket_id', $ticket->getKey());

        $ticket->forceFill([
            'replies_count' => (clone $replies)->where('is_system', false)->count(),
            'staff_replies_count' => (clone $replies)->where('is_system', false)->where('panel', PanelType::Admin->value)->count(),
            'requester_replies_count' => (clone $replies)->where('is_system', false)->where('panel', '!=', PanelType::Admin->value)->count(),
            'attachments_count' => (int) (clone $replies)->sum('attachments_count'),
        ])->save();

        if ($ticket->department !== null) {
            $this->recountDepartment($ticket->department);
        }
    }

    // ===============================================================================================

    /** The department named, or the institute's default, or the only one this panel may use. */
    private function resolveDepartment(array $data, PanelType $panel): TicketDepartment
    {
        $id = $data['ticket_department_id'] ?? setting('support.ticket_default_department_id');

        $department = $id === null
            ? TicketDepartment::query()->active()->where('is_default', true)->first()
            : TicketDepartment::query()->whereKey($id)->first();

        $department ??= TicketDepartment::query()->active()->forPanel($panel)->ordered()->first();

        if ($department === null) {
            throw SupportRuleException::refuse(
                'ticket_department_id',
                'There is no support desk to file this into. Create one, or mark an existing one as the default.',
            );
        }

        return $department;
    }

    /**
     * May somebody on this panel file here?
     *
     * Two gates: the institute's per-panel switch, and the department's own `allowed_panels` — which
     * is what stops a student filing into a client-billing queue whose agents have no reason to read
     * it.
     */
    private function assertMayRaise(PanelType $panel, TicketDepartment $department): void
    {
        $key = match ($panel) {
            PanelType::Client => 'support.ticket_allow_client_create',
            PanelType::Student => 'support.ticket_allow_student_create',
            PanelType::Teacher => 'support.ticket_allow_teacher_create',
            PanelType::Collaborator => 'support.ticket_allow_collaborator_create',
            PanelType::Admin => null,
        };

        if ($key !== null && ! (bool) setting($key, true)) {
            throw SupportRuleException::refuse('subject', 'Tickets cannot be raised from this panel at the moment.');
        }

        if ($panel !== PanelType::Admin && ! $department->accepts($panel)) {
            throw SupportRuleException::refuse('ticket_department_id', sprintf(
                'The %s desk does not take tickets from here. Choose another.',
                $department->getAttribute('name'),
            ));
        }
    }

    /**
     * The subject keys, from the requester's own profiles.
     *
     * **Never from `$data`** — see the class note. The context keys a form *may* set (`project_id`,
     * `course_id`, `batch_id`) are taken from it, because they say what the ticket is about rather
     * than who it is from, and the controller validates that they belong to the requester.
     *
     * @return array<string, mixed>
     */
    private function subjectsFor(User $requester, array $data): array
    {
        $context = app(ClientContext::class);

        return [
            'branch_id' => $requester->getAttribute('branch_id'),
            'client_id' => $context->inspect() === null ? $context->clientId() : null,
            'student_id' => Student::query()->where('user_id', $requester->getKey())->value('id'),
            'teacher_id' => Teacher::query()->where('user_id', $requester->getKey())->value('id'),
            'collaborator_id' => Collaborator::query()->where('user_id', $requester->getKey())->value('id'),
            'employee_id' => Employee::query()->where('user_id', $requester->getKey())->value('id'),
            'project_id' => $data['project_id'] ?? null,
            'course_id' => $data['course_id'] ?? null,
            'batch_id' => $data['batch_id'] ?? null,
        ];
    }

    /** Stamp the SLA due dates. A no-op with the feature off — every column simply stays null. */
    private function stampSlaTargets(SupportTicket $ticket, bool $resolutionOnly = false): void
    {
        $minutes = $this->sla->minutesFor($ticket);

        if (! $minutes->applies()) {
            return;
        }

        $from = Carbon::now();

        $changes = ['resolution_due_at' => $this->sla->dueAt($from, $minutes->resolution)];

        if (! $resolutionOnly) {
            $changes['first_response_due_at'] = $this->sla->dueAt($from, $minutes->firstResponse);
        }

        $ticket->forceFill($changes)->save();
    }

    private function autoAssign(SupportTicket $ticket, User $actor): void
    {
        $agent = $this->assignment->assignAutomatically($ticket);

        if ($agent === null) {
            return;
        }

        $ticket->forceFill([
            'assigned_to' => $agent->getKey(),
            'assigned_at' => Carbon::now(),
            'assigned_by' => $actor->getKey(),
        ])->save();
    }

    /**
     * What a reply does to the ticket around it.
     *
     * A requester reply on a waiting ticket wakes it up; on a resolved one inside the reopen window
     * it reopens it — which is how somebody says "this is not fixed" without arguing with a form.
     */
    private function reactToReply(SupportTicket $ticket, User $author, bool $isStaff, ReplyVisibility $visibility): void
    {
        $ticket->forceFill([
            'last_reply_at' => Carbon::now(),
            'last_reply_by' => $author->getKey(),
            'last_reply_panel' => $author->primaryPanel()->value,
        ])->save();

        if ($isStaff) {
            return;
        }

        if ($ticket->status === TicketStatus::Waiting) {
            $this->changeStatus($ticket, TicketStatus::InProgress, null, $author);

            return;
        }

        if ($ticket->status === TicketStatus::Resolved && $this->withinReopenWindow($ticket)) {
            $this->changeStatus($ticket, TicketStatus::Open, null, $author);
        }
    }

    /** Is a resolved ticket still close enough to its resolution for a reply to reopen it? */
    private function withinReopenWindow(SupportTicket $ticket): bool
    {
        $days = (int) setting('support.ticket_reopen_window_days', 14);

        if ($days <= 0) {
            return false;
        }

        $resolved = $ticket->getAttribute('resolved_at');

        return $resolved !== null && $resolved->diffInDays(Carbon::now()) <= $days;
    }

    private function recountDepartment(TicketDepartment $department): void
    {
        $department->forceFill([
            'open_tickets_count' => SupportTicket::query()
                ->where('ticket_department_id', $department->getKey())
                ->open()
                ->count(),
        ])->save();
    }

    /** An entry in the timeline with no author — a status change, an assignment, a move. */
    private function systemReply(
        SupportTicket $ticket,
        string $body,
        string $event,
        ?TicketStatus $from = null,
        ?TicketStatus $to = null,
    ): TicketReply {
        $reply = new TicketReply;
        $reply->forceFill([
            'support_ticket_id' => $ticket->getKey(),
            'user_id' => null,
            'body' => $body,
            'visibility' => ReplyVisibility::Public->value,
            'is_system' => true,
            'system_event' => $event,
            'status_from' => $from?->value,
            'status_to' => $to?->value,
        ]);
        $reply->save();

        return $reply;
    }

    /** "in progress, waiting or resolved" — a list a person can read. */
    private function describe(array $statuses): string
    {
        $labels = array_map(
            static fn (string $value): string => mb_strtolower(TicketStatus::from($value)->label()),
            $statuses,
        );

        if ($labels === []) {
            return 'nothing — it is finished';
        }

        if (count($labels) === 1) {
            return $labels[0];
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' or '.$last;
    }
}
