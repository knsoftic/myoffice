<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\PanelType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListFilterRequest;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\PermissionMatrix;
use App\Services\Core\RoleService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Roles and their permission matrix.
 *
 * The create/edit screens render the full grid — every module group, every module, a column per
 * ability — and post a flat `permissions[]` of names. `RoleService` resolves those names, refuses
 * anything that is not in the `permissions` table, calls `syncPermissions()` inside a transaction
 * and writes an audit entry naming what was granted and revoked.
 */
final class RoleController extends Controller
{
    use AuthorizesRequests;

    private const SORTABLE = ['name', 'label', 'panel', 'level', 'users_count', 'permissions_count'];

    private const PER_PAGE = 25;

    public function __construct(
        private readonly RoleService $roles,
        private readonly PermissionMatrix $matrix,
    ) {}

    /**
     * Every filter arrives through `ListFilterRequest`, so `?search[]=x` answers 422 rather than
     * dying inside `Stringable`'s string-typed constructor.
     */
    public function index(ListFilterRequest $request): View
    {
        $this->authorize('viewAny', Role::class);

        $sort = $request->sortColumn(self::SORTABLE, 'level');
        $direction = $request->sortDirection('asc');

        $panel = $request->panelFilter();
        $system = $request->systemFilter();

        $roles = Role::query()
            // Counts rather than relations: the index shows numbers, not the rows themselves.
            ->withCount(['users', 'permissions'])
            ->search($request->searchTerm())
            ->when($panel instanceof PanelType, static fn (Builder $query) => $query->where('panel', $panel->value))
            ->when($system === 'system', static fn (Builder $query) => $query->where('is_system', true))
            ->when($system === 'custom', static fn (Builder $query) => $query->where('is_system', false))
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.roles.index', [
            'roles' => $roles,
            'panelOptions' => PanelType::options(),
            'sort' => $sort,
            'direction' => $direction,
            'totalPermissions' => Permission::query()->count(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Role::class);

        $role = new Role([
            'panel' => PanelType::Admin->value,
            'level' => 50,
        ]);

        return view('admin.roles.create', array_merge($this->matrixData(), [
            'role' => $role,
            'selected' => [],
        ]));
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $this->authorize('create', Role::class);

        try {
            $role = $this->roles->create($request->payload(), $request->permissions());
        } catch (ActionNotAllowedException $exception) {
            return back()->withInput()->with('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.roles.edit', $role)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('The %s role was created.', $role->displayName()),
            ]);
    }

    /**
     * Read-only detail: what the role is, who holds it, what it can do.
     *
     * `roles.view` does not grant a directory of people. The role itself — its level, its panel, how
     * many accounts hold it — is as visible here as on the index, but each *member row* carries a
     * name, an e-mail address and a status, so it is shown only to an actor `UserPolicy::view()`
     * would let open that account on `/admin/users/{id}`. Without the filter, `roles.view` alone
     * listed every Super Admin's e-mail address — accounts the same actor is refused on the users
     * screen precisely because they outrank them.
     */
    public function show(Role $role): View
    {
        $this->authorize('view', $role);

        $role->loadCount(['users', 'permissions']);

        /** @var Collection<int, User> $members */
        $members = $role->users()
            ->select('users.id', 'users.name', 'users.email', 'users.status', 'users.avatar_path')
            // The visibility filter below asks `UserPolicy::view()` about every row, and the rank
            // rule inside it reads `$member->roles`. Without the eager load that is one query per
            // listed member — twenty-five of them on a full page (T15).
            ->with(['roles' => static fn ($query) => $query
                ->select('roles.id', 'roles.name', 'roles.label', 'roles.panel', 'roles.level')
                ->orderBy('roles.level')])
            ->orderBy('users.name')
            ->limit(25)
            ->get();

        $visible = $members->filter(static fn ($member): bool => Gate::allows('view', $member))->values();

        return view('admin.roles.show', [
            'role' => $role,
            'permissionGroups' => $this->matrix->build($this->heldPermissions($role)),
            'members' => $visible,
            'hiddenMembers' => $members->count() - $visible->count(),
        ]);
    }

    public function edit(Role $role): View
    {
        $this->authorize('update', $role);

        return view('admin.roles.edit', array_merge($this->matrixData(), [
            'role' => $role,
            'selected' => $this->heldPermissions($role)
                ->map(static fn (Permission $permission): string => (string) $permission->name)
                ->values()
                ->all(),
        ]));
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $this->authorize('update', $role);

        try {
            $this->roles->update($role, $request->payload(), $request->permissions());
        } catch (ActionNotAllowedException $exception) {
            return back()->withInput()->with('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.roles.edit', $role)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('The %s role was saved.', $role->displayName()),
            ]);
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        $name = $role->displayName();

        try {
            $this->roles->delete($role);
        } catch (ActionNotAllowedException $exception) {
            return back()->with('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.roles.index')
            ->with('toast', ['type' => 'success', 'message' => sprintf('The %s role was deleted.', $name)]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The grid the editor renders, plus everything the Alpine component needs to count against.
     *
     * @return array<string, mixed>
     */
    private function matrixData(): array
    {
        $groups = $this->matrix->build($this->allPermissions());

        return [
            'permissionGroups' => $groups,
            'allPermissionNames' => $this->matrix->names($groups),
            'panelOptions' => PanelType::options(),
        ];
    }

    /**
     * Every grantable permission, ordered the way the matrix renders.
     *
     * @return Collection<int, Permission>
     */
    private function allPermissions(): Collection
    {
        return Permission::query()
            ->ordered()
            ->get(['id', 'name', 'module', 'ability', 'group', 'label', 'description', 'sort_order']);
    }

    /**
     * @return Collection<int, Permission>
     */
    private function heldPermissions(Role $role): Collection
    {
        /** @var Collection<int, Permission> $permissions */
        $permissions = $role->permissions()
            ->select(['permissions.id', 'permissions.name', 'permissions.module', 'permissions.ability', 'permissions.group', 'permissions.label', 'permissions.sort_order'])
            ->orderBy('permissions.sort_order')
            ->orderBy('permissions.name')
            ->get();

        return $permissions;
    }
}
