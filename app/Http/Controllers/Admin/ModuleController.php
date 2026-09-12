<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ModuleGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListFilterRequest;
use App\Http\Requests\Admin\ToggleModuleRequest;
use App\Models\Module;
use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\ModuleService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;

/**
 * Module switchboard (phase-01 §1.3, decision D5).
 *
 * Switching a module off denies every one of its permissions to everyone — Super Admin included —
 * hides its sidebar entries and 403s its routes, while leaving all of its data exactly where it
 * was. Core modules are structural and are rendered locked.
 */
final class ModuleController extends Controller
{
    use AuthorizesRequests;

    /**
     * Cards per page. The page is grouped by ModuleGroup for rendering, so the default is high
     * enough that the common case is a single page.
     */
    private const PER_PAGE = 24;

    public function __construct(private readonly ModuleService $modules) {}

    public function index(ListFilterRequest $request): View
    {
        $this->authorize('viewAny', Module::class);

        $group = $request->groupFilter();
        $state = (string) $request->stateFilter();

        $modules = Module::query()
            ->withCount('permissions')
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

        return view('admin.modules.index', [
            'modules' => $modules,
            // Cards are grouped by ModuleGroup; the groups shown are the ones on this page.
            'groups' => $this->groupForDisplay($page),
            'groupOptions' => ModuleGroup::options(),
            'stats' => $this->stats(),
        ]);
    }

    /**
     * Flip one module. Core modules are refused by the policy and again by the service.
     */
    public function toggle(ToggleModuleRequest $request, Module $module): RedirectResponse
    {
        $this->authorize('toggle', $module);

        $desired = $request->desiredState();

        try {
            $module = $desired === null
                ? $this->modules->toggle($module, $request->reason())
                : $this->modules->setEnabled($module, $desired, $request->reason());
        } catch (ActionNotAllowedException $exception) {
            return back()->with('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return back()->with('toast', [
            'type' => $module->is_enabled ? 'success' : 'warning',
            'message' => $module->is_enabled
                ? sprintf('%s is enabled.', $module->name)
                : sprintf('%s is disabled. Its data is untouched and returns when you switch it back on.', $module->name),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Group the page's modules for the card layout.
     *
     * @param  Collection<int, Module>  $modules
     * @return array<int, array{key: string, label: string, color: string, modules: array<int, Module>}>
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
            ];

            $buckets[$key]['modules'][] = $module;
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
