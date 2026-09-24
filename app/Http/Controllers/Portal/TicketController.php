<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\ReplyVisibility;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ServesPortalSupport;
use App\Http\Requests\Portal\RaiseTicketRequest;
use App\Http\Requests\Portal\ReplyToOwnTicketRequest;
use App\Models\Support\SupportTicket;
use App\Models\Support\TicketDepartment;
use App\Services\Support\TicketService;
use App\Support\ClientContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A portal's own tickets — `{panel}.tickets.*` (phase-19-23 §7.6, §8, §9.4).
 *
 * **The list is `SupportTicketPolicy`'s answer applied to a query, not a second opinion.** A client
 * sees their company's tickets minus anything private to somebody else; a student, a teacher and a
 * collaborator see the ones they raised. Writing that twice — once in the policy for the detail page
 * and once here for the list — is how a portal ends up showing a row it then refuses to open.
 *
 * **An internal note is filtered out in the query.** A note the browser merely does not render is a
 * note that was still sent to it.
 *
 * **A portal user never chooses a priority** (§12.2 Q6) and never picks an assignee. Both are staff
 * decisions, and a requester who could declare "urgent" would, every time.
 */
final class TicketController extends Controller
{
    use ServesPortalSupport;

    public function __construct(
        private readonly TicketService $tickets,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', SupportTicket::class);

        $user = $request->user();

        $tickets = $this->mine($request)
            ->with(['department:id,name', 'assignee:id,name'])
            ->when($request->string('q')->toString() !== '', function (Builder $query) use ($request): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('q')->toString()).'%';
                $query->where(fn (Builder $q) => $q->where('subject', 'like', $term)->orWhere('ticket_number', 'like', $term));
            })
            ->when($request->string('status')->toString() !== '', fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->boolean('open'), fn (Builder $q) => $q->open())
            ->orderByDesc('last_reply_at')
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return view($this->screen($request, 'tickets.index'), [
            'tickets' => $tickets,
            'statuses' => TicketStatus::cases(),
            'panel' => $this->panel($request),
            'canCreate' => (bool) $user?->can('create', SupportTicket::class) && $this->desks($request)->isNotEmpty(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', SupportTicket::class);

        $desks = $this->desks($request);

        if ($desks->isEmpty()) {
            // No desk accepts tickets from this panel. Saying so beats an empty select that
            // refuses whatever is chosen.
            abort(404);
        }

        return view($this->screen($request, 'tickets.create'), [
            'departments' => $desks,
            'panel' => $this->panel($request),
        ]);
    }

    public function store(RaiseTicketRequest $request): RedirectResponse
    {
        $ticket = $this->tickets->create($request->validated(), $request->user(), $request->user());

        return redirect()
            ->to($this->route($request, 'tickets.show', $ticket))
            ->with('toast', ['type' => 'success', 'message' => 'Ticket '.$ticket->ticket_number.' raised. You will be told when somebody replies.']);
    }

    public function show(Request $request, SupportTicket $ticket): View
    {
        Gate::authorize('view', $ticket);

        $user = $request->user();

        $replies = $ticket->replies()
            ->where('visibility', ReplyVisibility::Public->value)
            ->with('author:id,name')
            ->chronological()
            ->get();

        return view($this->screen($request, 'tickets.show'), [
            'ticket' => $ticket->load(['department:id,name', 'assignee:id,name']),
            'replies' => $replies,
            'panel' => $this->panel($request),
            'canReply' => (bool) $user?->can('reply', $ticket),
            'canReopen' => (bool) $user?->can('changeStatus', $ticket),
        ]);
    }

    public function reply(ReplyToOwnTicketRequest $request, SupportTicket $ticket): RedirectResponse
    {
        // Forced public whatever arrived: the service does the same, and saying it twice means a
        // portal form can never mint a note only staff were meant to read.
        $this->tickets->reply($ticket, [
            'body' => $request->validated('body'),
            'visibility' => ReplyVisibility::Public->value,
        ], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Reply sent.']);
    }

    /**
     * Reopening. The one status move §2.28.7 gives a portal.
     */
    public function reopen(Request $request, SupportTicket $ticket): RedirectResponse
    {
        Gate::authorize('changeStatus', $ticket);

        $reason = $request->string('reason')->toString();

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Say what is still wrong — that sentence is what the desk reads first.',
            ]);
        }

        $this->tickets->changeStatus($ticket, TicketStatus::Open, $reason, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Reopened. The desk has been told.']);
    }

    // ===============================================================================================

    /**
     * §9.4's portal clause, as a builder.
     *
     * A client sees their company's, minus anything marked private to its creator; everybody else
     * sees what they raised. Deliberately *not* `assigned_to` or `created_by` — those are the staff
     * clauses, and a portal user is never either.
     *
     * @return Builder<SupportTicket>
     */
    private function mine(Request $request): Builder
    {
        $user = $request->user();
        $id = (int) $user->getKey();

        $query = SupportTicket::query();

        if ($this->panel($request) !== 'client') {
            return $query->where('user_id', $id);
        }

        $context = app(ClientContext::class);

        if (! $context->has()) {
            return $query->where('user_id', $id);
        }

        return $query->where(fn (Builder $q) => $q
            ->where('user_id', $id)
            ->orWhere(fn (Builder $company) => $company
                ->where('client_id', $context->clientId())
                ->where('is_private_to_creator', false)));
    }

    /**
     * The desks this panel may file against.
     *
     * `allowed_panels` is the same column `TicketService::assertMayRaise()` reads, so the form
     * offers exactly what the service would accept — a select that listed a desk the service
     * refuses is a form that fails on submit for a reason the person cannot see.
     *
     * @return Collection<int, TicketDepartment>
     */
    private function desks(Request $request)
    {
        $panel = $this->panel($request);
        $setting = 'support.ticket_allow_'.$panel.'_create';

        if (! (bool) setting($setting, true)) {
            return collect();
        }

        return TicketDepartment::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(static fn (TicketDepartment $desk): bool => in_array(
                $panel,
                array_map(static fn ($p): string => $p->value, $desk->panels()),
                true,
            ))
            ->values();
    }
}
