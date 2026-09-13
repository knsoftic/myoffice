<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Ability;
use App\Enums\ModuleGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListFilterRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Core\PermissionMatrix;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;

/**
 * The permission catalogue — read only, by design.
 *
 * Permissions are generated from `App\Support\PermissionRegistry` and written by the seeder, so
 * there is nothing to create or edit here: this screen exists to answer "what does this ability
 * mean, and which roles currently hold it?". Grants are made on the role editor.
 */
final class PermissionController extends Controller
{
    use AuthorizesRequests;

    /** Module slug these permissions are filed under. */
    private const MODULE = 'permissions';

    public function __construct(private readonly PermissionMatrix $matrix) {}

    public function index(ListFilterRequest $request): View
    {
        // There is no PermissionPolicy — permissions are not an editable resource — so the
        // gate is asked for the ability by name and spatie resolves it (`Gate::before` still
        // runs first, which is what keeps a disabled module closed).
        $this->authorize(self::MODULE.'.'.Ability::ViewAny->value);

        $group = $request->groupFilter();
        $ability = (string) $request->filterString('ability');
        $module = (string) $request->filterString('module');

        $permissions = Permission::query()
            // "Which roles hold this?" is the whole point of the screen — eager load or pay N+1.
            ->with(['roles' => static fn ($query) => $query
                ->select('roles.id', 'roles.name', 'roles.label', 'roles.panel', 'roles.level')
                ->orderBy('roles.level'),
            ])
            ->search((string) $request->searchTerm())
            ->when($group instanceof ModuleGroup, static fn (Builder $query) => $query->where('group', $group->value))
            ->when($ability !== '', static fn (Builder $query) => $query->where('ability', $ability))
            ->when($module !== '', static fn (Builder $query) => $query->where('module', $module))
            ->ordered()
            // Rows per page come from `appearance.table_page_size` (per_page()); the page is then
            // grouped by module for rendering.
            ->paginate(per_page())
            ->withQueryString();

        /** @var Collection<int, Permission> $page */
        $page = new Collection($permissions->items());

        return view('admin.permissions.index', [
            'permissions' => $permissions,
            // Same grid builder the role editor uses, fed only with the rows on this page.
            'permissionGroups' => $this->matrix->build($page),
            'groupOptions' => ModuleGroup::options(),
            'abilityOptions' => $this->abilityOptions(),
            'moduleOptions' => $this->moduleOptions(),
            'stats' => [
                'permissions' => Permission::query()->count(),
                'modules' => Permission::query()->distinct()->count('module'),
                'roles' => Role::query()->count(),
                'matching' => $permissions->total(),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Ability values actually present in the table (so the filter never offers an empty result),
     * labelled through the Ability enum where one matches.
     *
     * @return array<string, string>
     */
    private function abilityOptions(): array
    {
        return Permission::query()
            ->distinct()
            ->orderBy('ability')
            ->pluck('ability')
            // Eloquent's pluck applies the cast, so a known ability arrives as an Ability case
            // and a portal ability as a plain string — normalise before using it as a key.
            ->mapWithKeys(static function (mixed $ability): array {
                $value = $ability instanceof Ability ? $ability->value : (string) $ability;

                return [$value => PermissionMatrix::abilityLabel($value)];
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function moduleOptions(): array
    {
        return Permission::query()
            ->distinct()
            ->orderBy('module')
            ->pluck('module')
            ->mapWithKeys(static fn (mixed $module): array => [
                (string) $module => (string) $module,
            ])
            ->all();
    }
}
