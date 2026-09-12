<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Ability;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListFilterRequest;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Models\Activity;
use App\Models\Branch;
use App\Models\LoginHistory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\PermissionMatrix;
use App\Services\Core\UserService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * User accounts — the people half of the RBAC control centre.
 *
 * Authorization is belt-and-braces: the route carries `can:users.*` middleware, every action
 * calls `authorize()` against UserPolicy (which adds the rank rule — you may only touch accounts
 * weaker than your own best role), and the two invariants a Super Admin must also obey (no
 * self-deletion, never remove the last Super Admin) live in UserService so no caller can skip
 * them.
 */
final class UserController extends Controller
{
    // The framework's base controller carries no traits in Laravel 12, so each controller opts
    // in to $this->authorize() itself.
    use AuthorizesRequests;

    /** Columns a user is allowed to sort by — anything else falls back to `name`. */
    private const SORTABLE = ['name', 'email', 'status', 'last_login_at', 'created_at'];

    private const PER_PAGE = 20;

    public function __construct(
        private readonly UserService $users,
        private readonly PermissionMatrix $matrix,
    ) {}

    /**
     * Searchable, filterable, sortable list.
     *
     * Every filter arrives through `ListFilterRequest`, so a hostile query string (`?search[]=x`,
     * `?status[]=`, a 10 kB sort column) answers 422 instead of blowing up inside `Stringable`.
     */
    public function index(ListFilterRequest $request): View
    {
        $this->authorize('viewAny', User::class);

        $sort = $request->sortColumn(self::SORTABLE, 'name');
        $direction = $request->sortDirection('asc');

        $search = $request->searchTerm();
        $roleId = $request->idFilter('role');
        $status = $request->statusFilter();
        $branchId = $request->idFilter('branch');

        $users = User::query()
            // Roles and branch are rendered on every row: eager load both or pay N+1.
            ->with([
                'roles' => static fn ($query) => $query
                    ->select('roles.id', 'roles.name', 'roles.label', 'roles.panel', 'roles.level')
                    ->orderBy('roles.level'),
                'branch:id,code,name',
            ])
            ->search($search)
            ->when($roleId !== null, static fn (Builder $query) => $query->whereHas(
                'roles',
                static fn (Builder $roles) => $roles->where('roles.id', $roleId),
            ))
            ->when($status instanceof UserStatus, static fn (Builder $query) => $query->where('status', $status->value))
            ->when($branchId !== null, static fn (Builder $query) => $query->where('branch_id', $branchId))
            ->orderBy($sort, $direction)
            // A deterministic tie-break keeps pagination stable when the sort column repeats.
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roleOptions' => $this->roleOptions(),
            'branchOptions' => $this->branchOptions(),
            'statusOptions' => UserStatus::options(),
            'counts' => $this->statusCounts(),
            'sort' => $sort,
            'direction' => $direction,
            'filters' => [
                'search' => $search ?? '',
                'role' => $roleId,
                'status' => $status?->value,
                'branch' => $branchId,
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.create', [
            'user' => new User(['status' => UserStatus::Active->value]),
            'roles' => $this->assignableRoles(new User),
            'branches' => $this->branches(),
            'statusOptions' => UserStatus::options(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $user = $this->users->create($request->payload(), $request->file('avatar'));

        return redirect()
            ->route('admin.users.show', $user)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('%s was added.', $user->name),
            ]);
    }

    /**
     * Profile, roles, the effective permission list, recent logins and the account's audit trail.
     *
     * The last two are log data, not user data. `users.view` buys the staff directory — name,
     * contact, roles, effective permissions — and nothing more; IP addresses, devices, failed
     * sign-in attempts and the change history are what `/admin/login-history` and
     * `/admin/activity-log` exist to gate, so this screen asks for the **same** abilities those
     * routes demand and renders nothing at all when they are missing.
     */
    public function show(User $user): View
    {
        $this->authorize('view', $user);

        $actor = $this->actor();

        $user->load([
            'roles' => static fn ($query) => $query->orderBy('level'),
            'branch:id,code,name',
            'creator:id,name',
            'editor:id,name',
        ]);

        $canSeeLogins = $actor->can('login_history.'.Ability::ViewLogs->value);
        $canSeeActivity = $actor->can('activity_log.'.Ability::ViewLogs->value);

        return view('admin.users.show', [
            'user' => $user,
            // Grouped by module group → module → ability, the same grid the role editor renders.
            'permissionGroups' => $this->matrix->build($this->effectivePermissions($user)),
            'canSeeLogins' => $canSeeLogins,
            'canSeeActivity' => $canSeeActivity,
            'logins' => $canSeeLogins
                ? LoginHistory::query()
                    ->forUser($user)
                    ->latestFirst()
                    ->limit(10)
                    ->get()
                : new EloquentCollection,
            'activities' => $canSeeActivity
                ? Activity::query()
                    ->where('subject_type', $user->getMorphClass())
                    ->where('subject_id', $user->getKey())
                    ->with('causer:id,name')
                    ->latestFirst()
                    ->limit(10)
                    ->get()
                : new EloquentCollection,
            'statusOptions' => UserStatus::options(),
            'temporaryPassword' => session('temporary_password'),
        ]);
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        $user->load('roles:id,name,label,panel,level');

        return view('admin.users.edit', [
            'user' => $user,
            // Roles grantable **to this account** — the target's own rank is half the rule
            // (UserPolicy::assignRoles), which is what makes your own edit screen offer none.
            'roles' => $this->assignableRoles($user),
            'branches' => $this->branches(),
            'statusOptions' => UserStatus::options(),
            'canAssignRoles' => Gate::allows('assignRoles', [$user]),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        try {
            $this->users->update(
                $user,
                $request->payload(),
                $this->actor(),
                $request->file('avatar'),
                $request->shouldRemoveAvatar(),
            );
        } catch (ActionNotAllowedException $exception) {
            // The Form Request refuses these payloads first; this keeps the service's own
            // invariants from surfacing as a 500 if a later caller reaches them another way.
            return back()->withInput()->with('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.users.show', $user)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('%s was updated.', $user->name),
            ]);
    }

    /**
     * Activate / deactivate / suspend, with the reason written to `status_reason` and an audit row.
     */
    public function updateStatus(UpdateUserStatusRequest $request, User $user): RedirectResponse
    {
        $this->authorize('changeStatus', $user);

        $status = $request->newStatus();

        if (! $status instanceof UserStatus) {
            return back()->with('toast', ['type' => 'error', 'message' => 'That status is not recognised.']);
        }

        try {
            $this->users->changeStatus($user, $status, $this->actor(), $request->reason());
        } catch (ActionNotAllowedException $exception) {
            return back()->with('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return back()->with('toast', [
            'type' => $status->canLogin() ? 'success' : 'warning',
            'message' => sprintf('%s is now %s.', $user->name, mb_strtolower($status->label())),
        ]);
    }

    /**
     * Issue a temporary password. It is flashed once and never stored in plain text.
     */
    public function resetPassword(ResetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $this->authorize('resetPassword', $user);

        try {
            $password = $this->users->resetPassword($user, $this->actor(), $request->reason());
        } catch (ActionNotAllowedException $exception) {
            return back()->with('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.users.show', $user)
            ->with('temporary_password', $password)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('A temporary password was issued for %s.', $user->name),
            ]);
    }

    /**
     * Soft delete. Refused for your own account and for the last Super Admin.
     */
    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $actor = $this->actor();

        try {
            $this->users->delete($user, $actor);
        } catch (ActionNotAllowedException $exception) {
            return back()->with('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.users.index')
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('%s was deleted.', $user->name),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Role id => label, for the filter dropdown.
     *
     * @return array<int, string>
     */
    private function roleOptions(): array
    {
        return Role::query()
            ->ordered()
            ->get(['id', 'name', 'label'])
            ->mapWithKeys(static fn (Role $role): array => [(int) $role->getKey() => $role->displayName()])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function branchOptions(): array
    {
        return $this->branches()
            ->mapWithKeys(static fn (Branch $branch): array => [(int) $branch->getKey() => $branch->name])
            ->all();
    }

    /**
     * @return Collection<int, Branch>
     */
    private function branches(): Collection
    {
        return Branch::query()
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_active']);
    }

    /**
     * Roles the actor is allowed to hand out **to this account**, so the form never offers a grant
     * validation would reject.
     *
     * The same `assignRoles` ability the Form Request asks for, with the same arguments — one
     * question, asked twice, which is why the form and the server cannot disagree. Pass an unsaved
     * `User` for the create screen: there is no target rank yet.
     *
     * @return Collection<int, Role>
     */
    private function assignableRoles(User $target): Collection
    {
        return Role::query()
            ->ordered()
            ->get(['id', 'name', 'label', 'description', 'panel', 'level', 'is_system'])
            ->filter(static fn (Role $role): bool => Gate::allows('assignRoles', [$target, $role]))
            ->values();
    }

    /**
     * Totals per status for the header cards — one grouped query, not four counts.
     *
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $counts = User::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $result = ['total' => 0];

        foreach (UserStatus::cases() as $case) {
            $value = (int) ($counts[$case->value] ?? 0);
            $result[$case->value] = $value;
            $result['total'] += $value;
        }

        return $result;
    }

    /**
     * Every permission the user actually holds, through their roles or directly.
     *
     * @return Collection<int, Permission>
     */
    private function effectivePermissions(User $user): Collection
    {
        /** @var Collection<int, Permission> $permissions */
        $permissions = $user->getAllPermissions();

        return $permissions->sortBy('name')->values();
    }

    private function actor(): User
    {
        $actor = request()->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
