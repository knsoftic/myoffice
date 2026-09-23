<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Support;

use App\Enums\PanelType;
use App\Enums\TicketAssignStrategy;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Support\StoreTicketDepartmentRequest;
use App\Http\Requests\Admin\Support\UpdateTicketDepartmentRequest;
use App\Models\Support\TicketDepartment;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The support desks — `admin.ticket-departments.*` (phase-19-23 §7.6, §6.16).
 *
 * **One screen, not four.** A department is five fields and a list; a separate create page and edit
 * page for something an administrator touches twice a year is three screens of Blade nobody reads.
 * The index carries the form, and the row being edited is passed back through the query string so a
 * validation failure returns to the right one open.
 *
 * **Deleting is offered only for a desk that has never been used.** `TicketDepartmentPolicy::delete()`
 * answers that, so the button is absent rather than present-and-failing — and the honest alternative,
 * retiring it, is right beside it.
 */
final class TicketDepartmentController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', TicketDepartment::class);

        $departments = TicketDepartment::query()
            ->withCount('tickets')
            ->when($request->string('q')->toString() !== '', function ($query) use ($request): void {
                $term = '%'.$request->string('q')->toString().'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('slug', 'like', $term));
            })
            ->when($request->string('status')->toString() === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->string('status')->toString() === 'retired', fn ($q) => $q->where('is_active', false))
            ->when($request->boolean('trashed'), fn ($q) => $q->onlyTrashed())
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $editing = $request->integer('edit') > 0
            ? TicketDepartment::query()->find($request->integer('edit'))
            : null;

        return view('admin.ticket-departments.index', [
            'departments' => $departments,
            'editing' => $editing,
            'panels' => PanelType::cases(),
            'strategies' => TicketAssignStrategy::cases(),
            'agents' => $this->agents(),
            'canCreate' => (bool) $request->user()?->can('create', TicketDepartment::class),
        ]);
    }

    public function store(StoreTicketDepartmentRequest $request): RedirectResponse
    {
        $department = new TicketDepartment;
        $department->fill($request->attributes())->save();

        return redirect()
            ->route('admin.ticket-departments.index')
            ->with('toast', ['type' => 'success', 'message' => 'Desk created.']);
    }

    public function update(UpdateTicketDepartmentRequest $request, TicketDepartment $department): RedirectResponse
    {
        $department->fill($request->attributes())->save();

        return redirect()
            ->route('admin.ticket-departments.index')
            ->with('toast', ['type' => 'success', 'message' => 'Desk saved.']);
    }

    /** Retiring or reopening a desk. A retired one stays on every ticket it ever held. */
    public function status(Request $request, TicketDepartment $department): RedirectResponse
    {
        Gate::authorize('changeStatus', $department);

        $active = $request->boolean('is_active');
        $department->forceFill(['is_active' => $active])->save();

        return redirect()
            ->route('admin.ticket-departments.index')
            ->with('toast', [
                'type' => 'success',
                'message' => $active
                    ? 'Desk reopened — it will appear on the raise-a-ticket form again.'
                    : 'Desk retired. Its tickets keep it; new ones cannot be raised against it.',
            ]);
    }

    public function destroy(Request $request, TicketDepartment $department): RedirectResponse
    {
        Gate::authorize('delete', $department);

        $department->delete();

        return redirect()
            ->route('admin.ticket-departments.index')
            ->with('toast', ['type' => 'success', 'message' => 'Desk deleted.']);
    }

    /**
     * Everybody who could be a default assignee.
     *
     * The same definition the automatic assignment uses — holds `support_tickets.view_any` and is
     * active — because a default assignee the engine would never have chosen is a default assignee
     * whose tickets sit unread.
     *
     * @return Collection<int, User>
     */
    private function agents()
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'status', 'branch_id'])
            ->filter(static fn (User $user): bool => $user->can('support_tickets.view_any'))
            ->values();
    }
}
