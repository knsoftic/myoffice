<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Support;

use App\Enums\Priority;
use App\Enums\ReplyVisibility;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Support\ReplyToTicketRequest;
use App\Http\Requests\Admin\Support\StoreTicketRequest;
use App\Models\Support\SupportTicket;
use App\Models\Support\TicketDepartment;
use App\Models\User;
use App\Services\Support\TicketAssignmentService;
use App\Services\Support\TicketService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The ticket queue and one ticket — `admin.tickets.*` (phase-19-23 §7.6, §8, §9.4).
 *
 * **The list is scoped in the query, never in the view.** §9.4's two answers — `view_any` sees the
 * queue branch-scoped, `view` sees their own — are applied to the builder, so a person who is not
 * meant to see a row never receives it and pagination counts what they can actually open. Hiding
 * rows in Blade would give them a page of eight tickets with three rendered.
 *
 * **Every refusal the service makes is a validation error on a field**, so a wrong status move or a
 * missing reason returns to the ticket with the sentence attached rather than throwing an error
 * page at somebody mid-conversation. `SupportRuleException` already is one; it only needs letting
 * through.
 */
final class TicketController extends Controller
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly TicketAssignmentService $assignment,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', SupportTicket::class);

        $user = $request->user();

        $tickets = $this->scoped($request)
            ->with(['department:id,name', 'requester:id,name', 'assignee:id,name'])
            ->when($request->string('q')->toString() !== '', function (Builder $query) use ($request): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('q')->toString()).'%';
                $query->where(fn (Builder $q) => $q
                    ->where('subject', 'like', $term)
                    ->orWhere('ticket_number', 'like', $term));
            })
            ->when($request->string('status')->toString() !== '', fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->string('priority')->toString() !== '', fn (Builder $q) => $q->where('priority', $request->string('priority')->toString()))
            ->when($request->integer('department') > 0, fn (Builder $q) => $q->where('ticket_department_id', $request->integer('department')))
            ->when($request->integer('assignee') > 0, fn (Builder $q) => $q->where('assigned_to', $request->integer('assignee')))
            ->when($request->string('assignee')->toString() === 'none', fn (Builder $q) => $q->whereNull('assigned_to'))
            ->when($request->boolean('mine'), fn (Builder $q) => $q->where('assigned_to', $user?->getKey()))
            ->when($request->boolean('breaching'), fn (Builder $q) => $q->breaching())
            ->orderByRaw($this->sort($request))
            ->paginate(20)
            ->withQueryString();

        return view('admin.tickets.index', [
            'tickets' => $tickets,
            'departments' => TicketDepartment::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => TicketStatus::cases(),
            'priorities' => Priority::cases(),
            'agents' => $this->agents(),
            'canCreate' => (bool) $user?->can('create', SupportTicket::class),
            'canViewReports' => (bool) $user?->can('support_tickets.view_reports'),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', SupportTicket::class);

        return view('admin.tickets.create', [
            'departments' => TicketDepartment::query()->active()->orderBy('sort_order')->orderBy('name')->get(),
            'priorities' => Priority::cases(),
        ]);
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $requester = $request->requester();

        $ticket = $this->tickets->create($request->validated(), $requester, $request->user());

        return redirect()
            ->route('admin.tickets.show', $ticket)
            ->with('toast', ['type' => 'success', 'message' => 'Ticket '.$ticket->ticket_number.' raised.']);
    }

    public function show(Request $request, SupportTicket $ticket): View
    {
        Gate::authorize('view', $ticket);

        $user = $request->user();
        $seesInternal = (bool) $user?->can('support_tickets.view_any');

        $replies = $ticket->replies()
            // §9.4: a requester sees public replies only. Filtered in the query, so an internal note
            // is never sent to a browser that merely does not render it.
            ->when(! $seesInternal, fn (Builder $q) => $q->where('visibility', ReplyVisibility::Public->value))
            ->with('author:id,name')
            ->orderBy('id')
            ->get();

        return view('admin.tickets.show', [
            'ticket' => $ticket->load(['department', 'requester:id,name,email', 'assignee:id,name']),
            'replies' => $replies,
            'statuses' => TicketStatus::cases(),
            'priorities' => Priority::cases(),
            'departments' => TicketDepartment::query()->active()->orderBy('name')->get(['id', 'name']),
            'eligible' => $user?->can('assign', $ticket) ? $this->assignment->eligibleFor($ticket) : collect(),
            'canReply' => (bool) $user?->can('reply', $ticket),
            'canNote' => (bool) $user?->can('addInternalNote', $ticket),
            'canAssign' => (bool) $user?->can('assign', $ticket),
            'canChangeStatus' => (bool) $user?->can('changeStatus', $ticket),
            'canChangePriority' => (bool) $user?->can('changePriority', $ticket),
            'canChangeDepartment' => (bool) $user?->can('changeDepartment', $ticket),
        ]);
    }

    public function reply(ReplyToTicketRequest $request, SupportTicket $ticket): RedirectResponse
    {
        $this->tickets->reply($ticket, $request->validated(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Reply added.']);
    }

    public function assign(Request $request, SupportTicket $ticket): RedirectResponse
    {
        Gate::authorize('assign', $ticket);

        $agentId = $request->integer('assigned_to');
        $agent = $agentId > 0 ? User::query()->find($agentId) : null;

        if ($agentId > 0 && $agent === null) {
            throw ValidationException::withMessages(['assigned_to' => 'That person no longer has an account here.']);
        }

        $this->tickets->assign($ticket, $agent, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $agent === null ? 'Ticket returned to the queue.' : 'Assigned to '.$agent->name.'.',
        ]);
    }

    public function status(Request $request, SupportTicket $ticket): RedirectResponse
    {
        Gate::authorize('changeStatus', $ticket);

        $to = TicketStatus::tryFrom((string) $request->input('status'));

        if ($to === null) {
            throw ValidationException::withMessages(['status' => 'That is not a status a ticket can be in.']);
        }

        $this->tickets->changeStatus($ticket, $to, $request->string('reason')->toString(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Ticket is now '.mb_strtolower($to->label()).'.']);
    }

    public function priority(Request $request, SupportTicket $ticket): RedirectResponse
    {
        Gate::authorize('changePriority', $ticket);

        $to = Priority::tryFrom((string) $request->input('priority'));

        if ($to === null) {
            throw ValidationException::withMessages(['priority' => 'That is not a priority.']);
        }

        $this->tickets->changePriority($ticket, $to, $request->string('reason')->toString(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Priority changed — the targets have been recalculated.']);
    }

    public function department(Request $request, SupportTicket $ticket): RedirectResponse
    {
        Gate::authorize('changeDepartment', $ticket);

        $department = TicketDepartment::query()->find($request->integer('ticket_department_id'));

        if ($department === null) {
            throw ValidationException::withMessages(['ticket_department_id' => 'That desk does not exist.']);
        }

        $this->tickets->changeDepartment($ticket, $department, $request->string('reason')->toString(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Moved to '.$department->name.'.']);
    }

    /** The SLA desk — §93's queue figures, gated by its own permission. */
    public function sla(Request $request): View
    {
        Gate::authorize('viewReports', SupportTicket::class);

        $base = $this->scoped($request);

        return view('admin.tickets.sla', [
            'open' => (clone $base)->whereIn('status', $this->openValues())->count(),
            'breaching' => (clone $base)->breaching()->count(),
            'unassigned' => (clone $base)->whereIn('status', $this->openValues())->whereNull('assigned_to')->count(),
            'byDepartment' => (clone $base)
                ->selectRaw('ticket_department_id, COUNT(*) AS total')
                ->whereIn('status', $this->openValues())
                ->groupBy('ticket_department_id')
                ->pluck('total', 'ticket_department_id'),
            'departments' => TicketDepartment::query()->orderBy('name')->get(['id', 'name']),
            'breachingTickets' => (clone $base)
                ->breaching()
                ->with(['department:id,name', 'assignee:id,name'])
                ->orderBy('sla_resolution_due_at')
                ->limit(25)
                ->get(),
        ]);
    }

    // ===============================================================================================

    /**
     * §9.4's two answers, applied to the builder.
     *
     * @return Builder<SupportTicket>
     */
    private function scoped(Request $request): Builder
    {
        $user = $request->user();
        $query = SupportTicket::query();

        if ($user !== null && $user->can('support_tickets.view_any')) {
            $branchId = $user->getAttribute('branch_id');

            return $branchId === null
                ? $query
                : $query->where(fn (Builder $q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'));
        }

        // The [D-P5-8] shape. A staff member holding only `view` sees what they are in, and nothing
        // else — the same three columns the policy checks, so the list and the detail agree.
        $id = $user?->getKey();

        return $query->where(fn (Builder $q) => $q
            ->where('assigned_to', $id)
            ->orWhere('created_by', $id)
            ->orWhere('user_id', $id));
    }

    /**
     * @return list<string>
     */
    private function openValues(): array
    {
        return array_map(
            static fn (TicketStatus $status): string => $status->value,
            array_values(array_filter(TicketStatus::cases(), static fn (TicketStatus $s): bool => $s->isOpen())),
        );
    }

    /**
     * The sort, as a whitelist.
     *
     * Raw because the default is not a column: an urgent ticket that has been waiting an hour
     * belongs above a low one raised a minute ago, and no single column says that.
     */
    private function sort(Request $request): string
    {
        return match ($request->string('sort')->toString()) {
            'oldest' => 'created_at ASC',
            'newest' => 'created_at DESC',
            'due' => 'sla_resolution_due_at IS NULL, sla_resolution_due_at ASC',
            'subject' => 'subject ASC',
            default => "FIELD(priority, 'urgent', 'high', 'medium', 'low'), last_activity_at DESC",
        };
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function agents()
    {
        return User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'branch_id'])
            ->filter(static fn (User $user): bool => $user->can('support_tickets.view_any'))
            ->values();
    }
}
