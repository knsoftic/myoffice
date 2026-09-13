<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Ability;
use App\Enums\ModuleGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkToggleModuleRequest;
use App\Http\Requests\Admin\ListFilterRequest;
use App\Http\Requests\Admin\ToggleModuleRequest;
use App\Models\Module;
use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\ModuleService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Module switchboard (phase-01 §1.3 / D5, extended by phase-02 §4-§5).
 *
 * Switching a module off denies every one of its permissions to everyone — Super Admin included —
 * hides its sidebar entries and 403s its routes, while leaving all of its data exactly where it
 * was. Core modules are structural and are rendered locked.
 *
 * Phase 2 adds dependency awareness: `impact()` answers what a flip will break before it happens,
 * `toggle()` refuses a disable that would strand an enabled dependent unless the cascade was
 * explicitly confirmed, and `bulkToggle()` applies the same rules to a whole group at once.
 */
final class ModuleController extends Controller
{
    use AuthorizesRequests;

    /**
     * Cards per page. The screen is grouped by `ModuleGroup` and carries a group-wide bulk
     * action, so a group must never be split across pages: the page size is deliberately larger
     * than the final module count (~112) while staying a paginator, which is what keeps the
     * "showing x of y" summary and the query-string filters honest.
     */
    private const PER_PAGE = 150;

    public function __construct(private readonly ModuleService $modules) {}

    public function index(ListFilterRequest $request): View
    {
        $this->authorize('viewAny', Module::class);

        $group = $request->groupFilter();
        $state = (string) $request->stateFilter();

        $modules = Module::query()
            ->withCount('permissions')
            ->with('disabledBy:id,name')
            ->search((string) $request->searchTerm())
            ->when($group instanceof ModuleGroup, static fn (Builder $query) => $query->where('group', $group->value))
            ->when($state === 'enabled', static fn (Builder $query) => $query->enabled())
            ->when($state === 'disabled', static fn (Builder $query) => $query->disabled())
            ->when($state === 'core', static fn (Builder $query) => $query->where('is_core', true))
            ->ordered()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var Collection<int, Module> $page */
        $page = new Collection($modules->items());

        $slugs = $page->map(static fn (Module $module): string => (string) $module->slug)->all();

        return view('admin.modules.index', [
            'modules' => $modules,
            // Cards are grouped by ModuleGroup; the groups shown are the ones on this page.
            'groups' => $this->groupForDisplay($page),
            'groupOptions' => ModuleGroup::options(),
            'stats' => $this->stats(),
            // One query each, shared by every card on the page — never per card.
            'dependencies' => $this->modules->dependencyOverview(array_values($slugs)),
            'routeCounts' => $this->modules->routeCounts(),
            // The ability, not the row: every card re-checks `isCore()` on top of it, and the
            // backend refuses a core module twice over whatever the markup renders.
            'canToggle' => (bool) $request->user()?->can('modules.'.Ability::ChangeStatus->value),
        ]);
    }

    /**
     * Flip one module. Core modules are refused by the policy and again by the service.
     */
    public function toggle(ToggleModuleRequest $request, Module $module): RedirectResponse
    {
        $this->authorize('toggle', $module);

        $desired = $request->desiredState() ?? ! (bool) $module->is_enabled;

        try {
            // The service reports the cascade it actually applied, decided under the same row
            // locks as the switch — never a list read beforehand that a concurrent toggle could
            // have made stale.
            $outcome = $this->modules->switchModule($module, $desired, $request->reason(), $request->cascade());
        } catch (ActionNotAllowedException $exception) {
            return back()->with('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        $module = $outcome['module'];

        return back()->with('toast', [
            'type' => $module->is_enabled ? 'success' : 'warning',
            'message' => $module->is_enabled
                ? $this->enabledMessage($module)
                : $this->disabledMessage($module, $outcome['cascaded']),
        ]);
    }

    /**
     * Switch every module in a group (or an explicitly named set) at once.
     *
     * The policy is re-run for every module: a core module, or one this actor may not touch, is
     * reported as skipped rather than quietly flipped.
     */
    public function bulkToggle(BulkToggleModuleRequest $request): RedirectResponse
    {
        // A bulk action names no single module, so there is no row for `ModulePolicy` to judge up
        // front: the ability is checked here (never only in routes/admin.php, which this class does
        // not own), and the policy is then re-run per module below.
        abort_unless(
            $request->user()?->can('modules.'.Ability::ChangeStatus->value) === true,
            403,
            'You may not change a module’s state.',
        );

        $enabled = $request->desiredState();
        $group = $request->groupFilter();
        $ids = $request->moduleIds();

        $candidates = Module::query()
            ->when($group instanceof ModuleGroup, static fn (Builder $query) => $query->where('group', $group->value))
            ->when($ids !== [], static fn (Builder $query) => $query->whereIn('id', $ids))
            ->ordered()
            ->get();

        if ($candidates->isEmpty()) {
            return back()->with('toast', [
                'type' => 'info',
                'message' => 'No modules matched that selection, so nothing was changed.',
            ]);
        }

        $refused = [];

        $allowed = $candidates->filter(function (Module $module) use (&$refused): bool {
            if (Gate::allows('toggle', $module)) {
                return true;
            }

            $refused[] = (string) $module->name;

            return false;
        });

        try {
            $result = $this->modules->bulkSetEnabled($allowed, $enabled, $request->reason(), $request->cascade());
        } catch (ActionNotAllowedException $exception) {
            return back()->with('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return back()->with('toast', $this->bulkToast($enabled, $result, $refused));
    }

    /**
     * What a flip will actually do — read by the impact dialog before it is confirmed.
     *
     * JSON only, read-only, and gated by `modules.view`: it lists the dependent modules, counts
     * the routes that will answer 403 and the sidebar entries that will disappear, and states that
     * no data is deleted. Routes and sidebar entries are *named* only as far as the viewer holds
     * the permissions behind them (ModuleService::impact()); `modules.view` alone is never a
     * licence to read the route map.
     */
    public function impact(Request $request, Module $module): JsonResponse
    {
        $this->authorize('view', $module);

        return response()->json($this->modules->impact($module, $request->user()));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function enabledMessage(Module $module): string
    {
        $missing = $this->modules->missingDependencies((string) $module->slug);

        if ($missing === []) {
            return sprintf('%s is enabled.', $module->name);
        }

        $names = $this->modules->namesFor($missing);

        return sprintf(
            '%s is enabled, but %s still switched off — %s will not work fully until %s %s on.',
            $module->name,
            implode(', ', $names).(count($names) === 1 ? ' is' : ' are'),
            $module->name,
            count($names) === 1 ? 'it' : 'they',
            count($names) === 1 ? 'is' : 'are',
        );
    }

    /**
     * @param  list<string>  $cascaded
     */
    private function disabledMessage(Module $module, array $cascaded): string
    {
        $base = sprintf(
            '%s is disabled. Its data is untouched and returns when you switch it back on.',
            $module->name,
        );

        if ($cascaded === []) {
            return $base;
        }

        $names = $this->modules->namesFor($cascaded);

        return sprintf(
            '%s %s %s disabled with it (they depend on it); no data was deleted.',
            $base,
            implode(', ', $names),
            count($names) === 1 ? 'was' : 'were',
        );
    }

    /**
     * @param  array{changed: list<string>, skipped: array<string, string>, core: list<string>, cascaded: list<string>}  $result
     * @param  list<string>  $refused
     * @return array{type: string, message: string}
     */
    private function bulkToast(bool $enabled, array $result, array $refused): array
    {
        $changed = count($result['changed']);
        $blocked = $result['skipped'];
        $core = count($result['core']);
        $parts = [];

        $parts[] = $changed === 0
            ? 'No module changed state.'
            : sprintf(
                '%d %s %s.',
                $changed,
                Str::plural('module', $changed),
                $enabled ? 'enabled' : 'disabled — no data was deleted',
            );

        if ($result['cascaded'] !== []) {
            $parts[] = sprintf(
                '%s %s taken down as %s.',
                implode(', ', $this->modules->namesFor($result['cascaded'])),
                count($result['cascaded']) === 1 ? 'was' : 'were',
                count($result['cascaded']) === 1 ? 'a dependent' : 'dependents',
            );
        }

        if ($blocked !== []) {
            // The service's own refusals, which already name the blocking modules.
            $parts[] = sprintf(
                '%d %s skipped: %s',
                count($blocked),
                Str::plural('module', count($blocked)),
                implode(' ', array_values($blocked)),
            );
        }

        if ($core > 0) {
            $parts[] = sprintf('%d core %s left on (they can never be disabled).', $core, Str::plural('module', $core));
        }

        if ($refused !== []) {
            $parts[] = sprintf(
                '%d %s skipped — you may not change %s.',
                count($refused),
                Str::plural('module', count($refused)),
                count($refused) === 1 ? 'it' : 'them',
            );
        }

        return [
            'type' => match (true) {
                $changed === 0 => 'info',
                $blocked !== [] || $refused !== [] => 'warning',
                $enabled => 'success',
                default => 'warning',
            },
            'message' => implode(' ', $parts),
        ];
    }

    /**
     * Group the page's modules for the card layout.
     *
     * @param  Collection<int, Module>  $modules
     * @return array<int, array{key: string, label: string, color: string, modules: array<int, Module>, toggleable: array<int, int>}>
     */
    private function groupForDisplay(Collection $modules): array
    {
        $order = array_map(static fn (ModuleGroup $case): string => $case->value, ModuleGroup::cases());

        $buckets = [];

        foreach ($modules as $module) {
            $group = $module->group instanceof ModuleGroup ? $module->group : ModuleGroup::tryFrom((string) $module->group);
            $key = $group?->value ?? 'other';

            $buckets[$key] ??= [
                'key' => $key,
                'label' => $group?->label() ?? 'Other',
                'color' => $group?->color() ?? 'slate',
                'modules' => [],
                // Ids the group's bulk action may actually move — core modules are never in it.
                'toggleable' => [],
            ];

            $buckets[$key]['modules'][] = $module;

            if (! $module->isCore()) {
                $buckets[$key]['toggleable'][] = (int) $module->getKey();
            }
        }

        uksort($buckets, static function (string $a, string $b) use ($order): int {
            $left = array_search($a, $order, true);
            $right = array_search($b, $order, true);

            return [$left === false ? PHP_INT_MAX : $left, $a] <=> [$right === false ? PHP_INT_MAX : $right, $b];
        });

        return array_values($buckets);
    }

    /**
     * Header figures: one grouped query instead of three counts.
     *
     * @return array<string, int>
     */
    private function stats(): array
    {
        $rows = Module::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_core = 1 THEN 1 ELSE 0 END) as core')
            ->selectRaw('SUM(CASE WHEN is_enabled = 1 OR is_core = 1 THEN 1 ELSE 0 END) as enabled')
            ->first();

        $total = (int) ($rows?->total ?? 0);
        $enabled = (int) ($rows?->enabled ?? 0);

        return [
            'total' => $total,
            'core' => (int) ($rows?->core ?? 0),
            'enabled' => $enabled,
            'disabled' => max(0, $total - $enabled),
        ];
    }
}
