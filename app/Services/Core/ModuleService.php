<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Enums\ModuleGroup;
use App\Events\ModuleStateChanged;
use App\Models\Module;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Support\Modules;
use App\Support\PermissionRegistry;
use App\Support\Sidebar;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Enabling and disabling a module (phase-01 §1.3 / D5, extended by phase-02 §3).
 *
 * Flipping the switch changes exactly one boolean plus three audit columns. It never touches the
 * module's data: a disabled module's routes 403, its sidebar entries disappear and `Gate::before`
 * denies every one of its permissions — including for Super Admin — but every row it owns stays
 * untouched and comes back intact when it is switched on again. **No method in this class deletes,
 * truncates or rewrites a business row, and none ever should.**
 *
 * Core modules are structural and can never be disabled; the registry decides what is core,
 * not the stored row.
 *
 * Phase 2 adds dependency awareness. `modules.depends_on` holds plain module slugs (not foreign
 * keys — a dependency may name a module that is declared in `PermissionRegistry` but has not been
 * seeded yet), and a module may not be switched off while a module that depends on it is still
 * enabled, unless the caller explicitly asks for a cascade.
 *
 * **Every switch is decided under a lock.** The dependents rule is read inside the write
 * transaction from `modules` rows locked `FOR UPDATE`, so two concurrent switches are serialised:
 * the second one decides against what the first one committed, never against a stale snapshot.
 *
 * @phpstan-type ModuleGraph array{
 *     ids: array<string, int>,
 *     stored: array<string, bool>,
 *     dependencies: array<string, list<string>>,
 *     dependents: array<string, list<string>>,
 *     enabled: array<string, bool>,
 *     core: array<string, bool>,
 *     names: array<string, string>
 * }
 * @phpstan-type ModuleMove array{id: int, slug: string, enabled: bool, reason: string, cascaded_from: string|null}
 * @phpstan-type RouteEntry array{name: string|null, uri: string, methods: string, requires: list<list<string>>}
 * @phpstan-type SidebarEntry array{panel: string, label: string, path: list<string>, permission: string|null}
 */
final class ModuleService
{
    use WritesAuditTrail;

    private const MODULE = 'modules';

    /**
     * A deadlock between two switches is retried rather than surfaced: the closure re-reads and
     * re-locks the graph on every attempt, so a retry decides against the committed state.
     */
    private const LOCK_ATTEMPTS = 3;

    /*
    |--------------------------------------------------------------------------
    | The declared graph
    |--------------------------------------------------------------------------
    */

    /**
     * The declared dependency graph — read from `PermissionRegistry`, the single declaration site
     * for modules and their `depends_on` edges (it used to live in a constant here, where the
     * CLAUDE.md "adding a module" checklist would never send anyone).
     *
     * `modules.depends_on` is a projection of it, written by syncDependencyGraph(); every read
     * below goes through the stored column, so an install that has never run the sync simply has
     * no dependency rules rather than two disagreeing answers.
     *
     * @return array<string, list<string>>
     */
    public static function dependencyGraph(): array
    {
        return PermissionRegistry::dependencyGraph();
    }

    /**
     * What the graph *declares* for one module, whatever the table currently stores.
     *
     * @return list<string>
     */
    public static function declaredDependencies(string $slug): array
    {
        return self::dependencyGraph()[$slug] ?? [];
    }

    /**
     * Project the declared graph onto `modules.depends_on`.
     *
     * Idempotent, additive and quiet: it writes through the query builder, so it fires no model
     * event, writes no activity row and cannot flip anybody's switch — the only column it touches
     * is `depends_on`. A module with no declared dependency is stored as NULL.
     *
     * Run it after seeding (and after adding an edge above); the module cache is flushed for you.
     *
     * @return list<string> the slugs whose stored dependencies changed
     */
    public function syncDependencyGraph(): array
    {
        $graph = self::dependencyGraph();
        $changed = [];

        /** @var iterable<int, Module> $modules */
        $modules = Module::query()->select(['id', 'slug', 'depends_on'])->cursor();

        foreach ($modules as $module) {
            $slug = (string) $module->slug;
            $desired = $graph[$slug] ?? [];

            $stored = is_array($module->depends_on) ? array_values(array_filter(
                array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $module->depends_on),
                static fn (string $value): bool => $value !== '',
            )) : [];

            if ($stored === $desired) {
                continue;
            }

            DB::table('modules')
                ->where('id', $module->getKey())
                ->update(['depends_on' => $desired === [] ? null : json_encode($desired, JSON_THROW_ON_ERROR)]);

            $changed[] = $slug;
        }

        if ($changed !== []) {
            Modules::flushCache();
        }

        return $changed;
    }

    /*
    |--------------------------------------------------------------------------
    | Reading the graph (phase-02 §3) — always from `modules.depends_on`
    |--------------------------------------------------------------------------
    */

    /**
     * The slugs this module declares as dependencies, as stored.
     *
     * @return list<string>
     */
    public function dependencies(string $slug): array
    {
        return $this->graph()['dependencies'][$slug] ?? [];
    }

    /**
     * The dependencies of this module that are **not usable right now** — either switched off or
     * not present in the `modules` table at all.
     *
     * This is what the screen warns about when a module is enabled while something it needs is
     * still off: the switch is honoured (a missing dependency is never a reason to refuse an
     * *enable*), but the administrator is told what is still dark.
     *
     * @return list<string>
     */
    public function missingDependencies(string $slug): array
    {
        return $this->missingDependenciesIn($this->graph(), $slug);
    }

    /**
     * The slugs of the modules that declare a dependency on this one.
     *
     * @return list<string>
     */
    public function dependents(string $slug): array
    {
        return $this->graph()['dependents'][$slug] ?? [];
    }

    /**
     * The dependents that are switched on — the set that blocks a disable.
     *
     * @return list<string>
     */
    public function enabledDependents(string $slug): array
    {
        return $this->enabledDependentsIn($this->graph(), $slug);
    }

    /**
     * Every enabled module that would have to go down with this one, transitively, deepest first.
     *
     * The order is what makes the cascade safe to apply in sequence: a dependent is always
     * switched off before the module it depends on, so the system is never left in a state where
     * an enabled module has a disabled dependency.
     *
     * This is a preview. The switch itself recomputes the set from locked rows (switchModule()).
     *
     * @return list<string>
     */
    public function cascadeSet(string $slug): array
    {
        return $this->cascadeSetIn($this->graph(), $slug);
    }

    /**
     * Chip data for a page of module cards, in **one** query (phase-02 §5 "dependency chips").
     *
     * @param  list<string>  $slugs
     * @return array<string, array{
     *     dependencies: list<array{slug: string, name: string, enabled: bool, is_core: bool}>,
     *     dependents: list<array{slug: string, name: string, enabled: bool, is_core: bool}>,
     *     missing: list<string>,
     *     blocking: list<string>
     * }>
     */
    public function dependencyOverview(array $slugs): array
    {
        $graph = $this->graph();
        $overview = [];

        foreach ($slugs as $slug) {
            $overview[$slug] = [
                'dependencies' => $this->describeIn($graph, $graph['dependencies'][$slug] ?? []),
                'dependents' => $this->describeIn($graph, $graph['dependents'][$slug] ?? []),
                'missing' => $this->missingDependenciesIn($graph, $slug),
                'blocking' => $this->enabledDependentsIn($graph, $slug),
            ];
        }

        return $overview;
    }

    /**
     * Human names for a list of slugs, in screen order, for a message or a chip row.
     *
     * @param  list<string>  $slugs
     * @return list<string>
     */
    public function namesFor(array $slugs): array
    {
        return $this->namesIn($this->graph(), $slugs);
    }

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    /**
     * Set the module's state. Returns the refreshed row.
     *
     * @param  bool  $cascade  when disabling, also switch off every module that depends on this
     *                         one (transitively). Must be asked for explicitly — never implied.
     *
     * **D63 — a disable needs a human reason.** An empty (or whitespace-only) reason on a disable
     * is refused here as well as in `ToggleModuleRequest` / `BulkToggleModuleRequest` (which also
     * demand 5–255 characters), because a Super Admin passes every policy and a later caller may
     * reach this service without a Form Request. An *enable* needs no reason; one is generated so
     * the audit trail is never blank.
     *
     * @throws ActionNotAllowedException when the module is core and the caller wants it off, when a
     *                                   disable carries no reason, or when enabled modules depend
     *                                   on it and `$cascade` is false
     */
    public function setEnabled(Module $module, bool $enabled, ?string $reason = null, bool $cascade = false): Module
    {
        return $this->switchModule($module, $enabled, $reason, $cascade)['module'];
    }

    /**
     * setEnabled(), reporting what actually moved.
     *
     * The report comes from the same locked snapshot that decided the switch, so a caller's message
     * can never name a cascade that did not happen (or miss one that did because a dependent was
     * switched on a moment earlier).
     *
     * @return array{module: Module, changed: bool, cascaded: list<string>}
     *
     * @throws ActionNotAllowedException
     */
    public function switchModule(Module $module, bool $enabled, ?string $reason = null, bool $cascade = false): array
    {
        if (! $enabled && ! $module->canBeDisabled()) {
            throw ActionNotAllowedException::coreModule((string) $module->name);
        }

        $slug = (string) $module->slug;
        $name = (string) $module->name;

        $given = $this->clean($reason);

        if (! $enabled && $given === null) {
            throw $this->reasonRequired($module);
        }

        $reason = $given ?? $this->enableReason($slug);
        $actorId = $this->actorId();

        // The dependents rule, the cascade set and the "is it already in that state?" question are
        // all answered inside the transaction, from rows locked FOR UPDATE. The state change and
        // its reasoned audit rows then commit together or not at all.
        [$moves, $rows] = $this->underLock(
            fn (array &$graph): array => $this->planSwitch($graph, $slug, $name, $enabled, $reason, $cascade),
            $actorId,
        );

        $this->announce($moves, $rows, $actorId);

        $row = $rows[(int) $module->getKey()] ?? null;

        if ($row instanceof Module) {
            $module->setRawAttributes($row->getAttributes(), true);
        } else {
            // Nothing moved for this row (a no-op, or only its dependents went down): the caller
            // still gets the committed state rather than whatever its instance held.
            $module->refresh();
        }

        return [
            'module' => $module,
            'changed' => $moves !== [],
            'cascaded' => array_values(array_map(
                static fn (array $move): string => $move['slug'],
                array_filter($moves, static fn (array $move): bool => $move['cascaded_from'] !== null),
            )),
        ];
    }

    /**
     * Flip the module (phase-02 §3).
     *
     * `$enabled` is the state to move **to**; pass null to flip whatever it is now, which is what
     * the switch on the modules screen does when it posts no explicit state.
     *
     * @throws ActionNotAllowedException
     */
    public function toggle(Module $module, ?bool $enabled = null, ?string $reason = null, bool $cascade = false): Module
    {
        return $this->setEnabled(
            $module,
            $enabled ?? ! (bool) $module->is_enabled,
            $reason,
            $cascade,
        );
    }

    /**
     * Switch a set of modules at once (the modules screen's per-group bulk action).
     *
     * The batch is applied in dependency order — when disabling, a module's dependents go first;
     * when enabling, its dependencies do — so a self-contained group switches cleanly without
     * anybody having to ask for a cascade, and the system is never left holding an enabled module
     * whose dependency has already gone dark.
     *
     * Each module is then decided on its own: a core module, or one still blocked by a dependent
     * outside the batch, is skipped with its reason rather than taking the whole batch down with
     * it. The whole group is decided against **one** locked read of the graph (each decision sees
     * the ones before it) and written in **one** transaction: one UPDATE per distinct state and
     * reason, one audit row per module, one reload of the rows that moved.
     *
     * @param  iterable<int, Module>  $modules
     * @return array{changed: list<string>, skipped: array<string, string>, core: list<string>, cascaded: list<string>}
     */
    public function bulkSetEnabled(iterable $modules, bool $enabled, ?string $reason = null, bool $cascade = false): array
    {
        // D63: refused up front rather than reported as N identical per-module skips.
        $given = $this->clean($reason);

        if (! $enabled && $given === null) {
            throw new ActionNotAllowedException('A reason is required to switch modules off.');
        }

        $candidates = [];

        foreach ($modules as $module) {
            if ($module instanceof Module) {
                $candidates[] = $module;
            }
        }

        $changed = [];
        $skipped = [];
        $core = [];

        if ($candidates === []) {
            return ['changed' => [], 'skipped' => [], 'core' => [], 'cascaded' => []];
        }

        $actorId = $this->actorId();

        [$moves, $rows] = $this->underLock(
            function (array &$graph) use ($candidates, $enabled, $given, $cascade, &$changed, &$skipped, &$core): array {
                // Reset on every attempt: a deadlock retry starts the whole decision again.
                $changed = [];
                $skipped = [];
                $core = [];
                $moves = [];

                foreach ($this->orderForWrite($graph, $candidates, $enabled) as $module) {
                    $slug = (string) $module->slug;

                    if ($module->isCore() || ($graph['core'][$slug] ?? false) === true) {
                        $core[] = $slug;

                        continue;
                    }

                    if (($graph['stored'][$slug] ?? null) === $enabled) {
                        continue;
                    }

                    try {
                        $planned = $this->planSwitch(
                            $graph,
                            $slug,
                            $graph['names'][$slug] ?? (string) $module->name,
                            $enabled,
                            $given ?? $this->enableReason($slug),
                            $cascade,
                        );
                    } catch (ActionNotAllowedException $exception) {
                        $skipped[$slug] = $exception->getMessage();

                        continue;
                    }

                    if ($planned === []) {
                        continue;
                    }

                    $changed[] = $slug;

                    foreach ($planned as $move) {
                        $moves[] = $move;
                    }
                }

                return $moves;
            },
            $actorId,
        );

        $this->announce($moves, $rows, $actorId);

        $cascaded = [];

        foreach ($moves as $move) {
            if ($move['cascaded_from'] !== null && ! in_array($move['slug'], $changed, true) && ! in_array($move['slug'], $cascaded, true)) {
                $cascaded[] = $move['slug'];
            }
        }

        return [
            'changed' => $changed,
            'skipped' => $skipped,
            'core' => $core,
            'cascaded' => $cascaded,
        ];
    }

    /**
     * Order a batch so each module is written after the modules that have to move first.
     *
     * Disabling walks the dependents (a module that nothing depends on goes first); enabling walks
     * the dependencies (a module that needs nothing goes first). Depth is the longest chain below
     * a module, so ordering by it ascending is a topological sort; ties keep the caller's order,
     * which is the screen's `sort_order`.
     *
     * @param  ModuleGraph  $graph
     * @param  list<Module>  $modules
     * @return list<Module>
     */
    private function orderForWrite(array $graph, array $modules, bool $enabled): array
    {
        $edges = $enabled ? $graph['dependencies'] : $graph['dependents'];

        /** @var array<string, int> $depth */
        $depth = [];

        $resolve = function (string $slug, array $seen) use (&$resolve, $edges, &$depth): int {
            if (array_key_exists($slug, $depth)) {
                return $depth[$slug];
            }

            if (in_array($slug, $seen, true)) {
                // A declared cycle must not recurse for ever; it also must not be memoised as 0.
                return 0;
            }

            $deepest = -1;

            foreach ($edges[$slug] ?? [] as $next) {
                $deepest = max($deepest, $resolve($next, [...$seen, $slug]));
            }

            return $depth[$slug] = $deepest + 1;
        };

        $ordered = [];
        $index = 0;

        foreach ($modules as $module) {
            $ordered[] = [$resolve((string) $module->slug, []), $index++, $module];
        }

        usort($ordered, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_values(array_map(static fn (array $row): Module => $row[2], $ordered));
    }

    /*
    |--------------------------------------------------------------------------
    | Impact preview (phase-02 §4, `admin.modules.impact`)
    |--------------------------------------------------------------------------
    */

    /**
     * Everything the confirm dialog has to say before a switch is flipped.
     *
     * Read-only: it answers "what changes if I do this?" and writes nothing. The one thing it can
     * promise in every branch is that **no data is deleted** — disabling a module closes its doors
     * and leaves every row where it is.
     *
     * **Scoped to the viewer.** `modules.view` is enough to open the preview, but it is not a licence
     * to read the application's route map. A route is named only when the viewer holds every
     * permission that route itself demands; a sidebar entry only when they hold the permission it
     * is shown for. Everything else is still counted (`routes.count`, `sidebar.count`), so the
     * dialog's figures stay true, but nothing about it is disclosed. A Super Admin sees it all; no
     * viewer at all sees names for nothing.
     *
     * @return array<string, mixed>
     */
    public function impact(Module $module, ?Authenticatable $viewer = null): array
    {
        $slug = (string) $module->slug;
        $graph = $this->graph();

        $isCore = $module->isCore();
        $on = $module->isEnabled();
        $target = ! $on;

        $blocking = $this->enabledDependentsIn($graph, $slug);
        $discloses = $this->disclosureFor($viewer);

        $routes = $this->routeMap()[$slug] ?? [];
        $visibleRoutes = [];

        foreach ($routes as $route) {
            if ($discloses($route['requires'])) {
                $visibleRoutes[] = ['name' => $route['name'], 'uri' => $route['uri'], 'methods' => $route['methods']];
            }
        }

        $sidebar = $this->sidebarEntriesFor($slug);
        $visibleSidebar = [];

        foreach ($sidebar as $item) {
            if ($discloses($item['permission'] === null ? [] : [[$item['permission']]])) {
                $visibleSidebar[] = ['panel' => $item['panel'], 'label' => $item['label'], 'path' => $item['path']];
            }
        }

        return [
            'module' => [
                'id' => (int) $module->getKey(),
                'slug' => $slug,
                'name' => (string) $module->name,
                'description' => $module->description === null ? null : (string) $module->description,
                'icon' => $module->icon === null ? null : (string) $module->icon,
                'group' => $module->group instanceof ModuleGroup ? $module->group->label() : (string) $module->group,
                'is_core' => $isCore,
                'is_enabled' => $on,
                'disabled_at' => $module->disabled_at instanceof Carbon ? $module->disabled_at->toIso8601String() : null,
                'disable_reason' => $module->disable_reason === null ? null : (string) $module->disable_reason,
            ],

            // What the dialog is about to do.
            'action' => $target ? 'enable' : 'disable',
            'can_change' => ! $isCore,
            'blocked' => ! $isCore && $target === false && $blocking !== [],
            'requires_cascade' => ! $isCore && $target === false && $blocking !== [],

            'dependents' => $this->describeIn($graph, $graph['dependents'][$slug] ?? []),
            'blocking_dependents' => $this->describeIn($graph, $blocking),
            'cascade' => $this->describeIn($graph, $target ? [] : $this->cascadeSetIn($graph, $slug)),

            'dependencies' => $this->describeIn($graph, $graph['dependencies'][$slug] ?? []),
            'missing_dependencies' => $this->describeIn($graph, $this->missingDependenciesIn($graph, $slug)),

            'permissions' => [
                'count' => count(PermissionRegistry::permissionNamesFor($slug)),
            ],
            'routes' => [
                'count' => count($routes),
                'hidden' => count($routes) - count($visibleRoutes),
                'items' => $visibleRoutes,
            ],
            'sidebar' => [
                'count' => count($sidebar),
                'hidden' => count($sidebar) - count($visibleSidebar),
            ],
            'sidebar_items' => $visibleSidebar,

            // Said in every payload, because it is the one thing an administrator needs to trust.
            'data_safety' => 'No data is deleted. Every row this module owns stays exactly where it is and comes back untouched when the module is switched on again.',
        ];
    }

    /**
     * `slug => number of registered routes the module gates`, for the cards.
     *
     * @return array<string, int>
     */
    public function routeCounts(): array
    {
        $counts = [];

        foreach ($this->routeMap() as $slug => $routes) {
            $counts[$slug] = count($routes);
        }

        return $counts;
    }

    /**
     * The registered routes a module closes when it goes off — unscoped, for internal callers.
     * The impact preview never hands this list to a viewer as is (see impact()).
     *
     * @return list<array{name: string|null, uri: string, methods: string}>
     */
    public function routesFor(string $slug): array
    {
        return array_values(array_map(
            static fn (array $route): array => ['name' => $route['name'], 'uri' => $route['uri'], 'methods' => $route['methods']],
            $this->routeMap()[$slug] ?? [],
        ));
    }

    /**
     * The navigation entries that disappear when a module goes off, across every panel — unscoped,
     * for internal callers.
     *
     * Read straight from the declarative `Sidebar` tree, so it says what the sidebar will really
     * do — including the panel each item belongs to. Items whose route does not exist yet (a later
     * phase's screen) are left out: nothing visible would change.
     *
     * @return list<array{panel: string, label: string, path: list<string>}>
     */
    public function sidebarItemsFor(string $slug): array
    {
        return array_values(array_map(
            static fn (array $item): array => ['panel' => $item['panel'], 'label' => $item['label'], 'path' => $item['path']],
            $this->sidebarEntriesFor($slug),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals — the graph
    |--------------------------------------------------------------------------
    */

    /**
     * One snapshot of the `modules` table, resolved into the maps every reader needs.
     *
     * Deliberately not memoised across calls: 79 narrow rows cost less than the risk of answering
     * a dependency question from a snapshot taken before somebody else's write.
     *
     * With `$lock`, every row is read `FOR UPDATE`. Every row is involved in a switch: a module's
     * dependents are found by scanning each row's `depends_on` (an unindexed JSON column), so a
     * locking read of "just the dependents" would lock every scanned row anyway. Holding them all
     * until the transaction ends means no concurrent switch can enable a dependent, or disable a
     * dependency, between the check and the write. Both concurrent readers scan in the same order,
     * so they queue rather than deadlock.
     *
     * @return ModuleGraph
     */
    private function graph(bool $lock = false): array
    {
        $ids = [];
        $stored = [];
        $dependencies = [];
        $dependents = [];
        $enabled = [];
        $core = [];
        $names = [];

        /** @var array<int, object{id: int|string, slug: string, name: string, is_enabled: mixed, is_core: mixed, depends_on: string|null}> $rows */
        $rows = DB::table('modules')
            ->select(['id', 'slug', 'name', 'is_enabled', 'is_core', 'depends_on', 'sort_order'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->get()
            ->all();

        foreach ($rows as $row) {
            $slug = (string) $row->slug;

            $ids[$slug] = (int) $row->id;
            $names[$slug] = (string) $row->name;
            $stored[$slug] = (bool) $row->is_enabled;
            $core[$slug] = (bool) $row->is_core || Modules::isCore($slug);
            $enabled[$slug] = $core[$slug] || $stored[$slug];
            $dependencies[$slug] = [];
        }

        foreach ($rows as $row) {
            $slug = (string) $row->slug;

            foreach ($this->decodeDependsOn($row->depends_on ?? null) as $dependency) {
                // A dependency has to name something real — a registered module, or at least a row
                // in this table — or it is a typo, and a typo may not block anybody's switch.
                if ($dependency === $slug || ! (isset($names[$dependency]) || Modules::exists($dependency))) {
                    continue;
                }

                if (! in_array($dependency, $dependencies[$slug], true)) {
                    $dependencies[$slug][] = $dependency;
                }

                $dependents[$dependency] ??= [];

                if (! in_array($slug, $dependents[$dependency], true)) {
                    $dependents[$dependency][] = $slug;
                }
            }
        }

        return [
            'ids' => $ids,
            'stored' => $stored,
            'dependencies' => $dependencies,
            'dependents' => $dependents,
            'enabled' => $enabled,
            'core' => $core,
            'names' => $names,
        ];
    }

    /**
     * @param  ModuleGraph  $graph
     * @return list<string>
     */
    private function missingDependenciesIn(array $graph, string $slug): array
    {
        return array_values(array_filter(
            $graph['dependencies'][$slug] ?? [],
            static fn (string $dependency): bool => ($graph['enabled'][$dependency] ?? false) === false,
        ));
    }

    /**
     * @param  ModuleGraph  $graph
     * @return list<string>
     */
    private function enabledDependentsIn(array $graph, string $slug): array
    {
        return array_values(array_filter(
            $graph['dependents'][$slug] ?? [],
            static fn (string $dependent): bool => ($graph['enabled'][$dependent] ?? false) === true,
        ));
    }

    /**
     * @param  ModuleGraph  $graph
     * @return list<string>
     */
    private function cascadeSetIn(array $graph, string $slug): array
    {
        $order = [];

        $walk = function (string $current) use (&$walk, $graph, &$order): void {
            foreach ($graph['dependents'][$current] ?? [] as $dependent) {
                if (in_array($dependent, $order, true) || ($graph['enabled'][$dependent] ?? false) === false) {
                    continue;
                }

                // Claim the slot before recursing so a cyclic declaration cannot loop for ever.
                $order[] = $dependent;
                $walk($dependent);
            }
        };

        $walk($slug);

        // Deepest dependents first: reverse discovery order.
        return array_values(array_reverse($order));
    }

    /**
     * @param  ModuleGraph  $graph
     * @param  list<string>  $slugs
     * @return list<array{slug: string, name: string, enabled: bool, is_core: bool}>
     */
    private function describeIn(array $graph, array $slugs): array
    {
        return array_values(array_map(
            static fn (string $slug): array => [
                'slug' => $slug,
                'name' => $graph['names'][$slug] ?? $slug,
                'enabled' => ($graph['enabled'][$slug] ?? false) === true,
                'is_core' => ($graph['core'][$slug] ?? false) === true,
            ],
            $slugs,
        ));
    }

    /**
     * @param  ModuleGraph  $graph
     * @param  list<string>  $slugs
     * @return list<string>
     */
    private function namesIn(array $graph, array $slugs): array
    {
        return array_values(array_map(
            static fn (string $slug): string => $graph['names'][$slug] ?? $slug,
            $slugs,
        ));
    }

    /**
     * `modules.depends_on` as a clean list of slugs, whatever shape the column holds.
     *
     * @return list<string>
     */
    private function decodeDependsOn(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($value)) {
            return [];
        }

        $slugs = [];

        foreach ($value as $slug) {
            if (! is_string($slug)) {
                continue;
            }

            $slug = trim($slug);

            if ($slug !== '' && ! in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals — deciding and writing a switch
    |--------------------------------------------------------------------------
    */

    /**
     * Run a switch decision inside one transaction, against the graph read `FOR UPDATE`, and write
     * whatever it decided before the locks are released.
     *
     * @param  Closure(ModuleGraph): list<ModuleMove>  $plan  receives the locked graph by reference
     * @return array{0: list<ModuleMove>, 1: array<int, Module>}
     */
    private function underLock(Closure $plan, ?int $actorId): array
    {
        /** @var array{0: list<ModuleMove>, 1: array<int, Module>} $result */
        $result = DB::transaction(function () use ($plan, $actorId): array {
            $graph = $this->graph(lock: true);

            $moves = $plan($graph);

            return [$moves, $this->writeMoves($moves, $actorId)];
        }, self::LOCK_ATTEMPTS);

        return $result;
    }

    /**
     * Decide one switch against the locked graph and record its effect on that graph, so the next
     * decision in the same batch sees it. A refusal is thrown before the graph is touched.
     *
     * @param  ModuleGraph  $graph
     * @return list<ModuleMove> dependents first, then the module itself
     *
     * @throws ActionNotAllowedException
     */
    private function planSwitch(array &$graph, string $slug, string $name, bool $enabled, string $reason, bool $cascade): array
    {
        if (! isset($graph['ids'][$slug])) {
            throw new ActionNotAllowedException(sprintf('"%s" is not installed, so it cannot be switched.', $name));
        }

        $name = $graph['names'][$slug] ?? $name;

        // The dependents rule only ever guards a disable: turning a module *on* can break nothing.
        $cascadeSlugs = [];

        if (! $enabled) {
            if (($graph['core'][$slug] ?? false) === true) {
                throw ActionNotAllowedException::coreModule($name);
            }

            $blocking = $this->enabledDependentsIn($graph, $slug);

            if ($blocking !== [] && ! $cascade) {
                throw $this->dependentsBlockDisable($graph, $name, $blocking);
            }

            if ($blocking !== []) {
                $cascadeSlugs = $this->cascadeSetIn($graph, $slug);
                $this->assertCascadeIsPermitted($graph, $name, $cascadeSlugs);
            }
        }

        $moves = [];

        // Dependents come down first, so no enabled module is ever left pointing at a module that
        // has already gone dark.
        foreach ($cascadeSlugs as $dependent) {
            if (! isset($graph['ids'][$dependent]) || ($graph['stored'][$dependent] ?? false) === false) {
                continue;
            }

            $moves[] = [
                'id' => $graph['ids'][$dependent],
                'slug' => $dependent,
                'enabled' => false,
                'reason' => $this->cascadeReason($slug, $reason),
                'cascaded_from' => $slug,
            ];
        }

        if (($graph['stored'][$slug] ?? null) !== $enabled) {
            $moves[] = [
                'id' => $graph['ids'][$slug],
                'slug' => $slug,
                'enabled' => $enabled,
                'reason' => $reason,
                'cascaded_from' => null,
            ];
        }

        foreach ($moves as $move) {
            $graph['stored'][$move['slug']] = $move['enabled'];
            $graph['enabled'][$move['slug']] = ($graph['core'][$move['slug']] ?? false) || $move['enabled'];
        }

        return $moves;
    }

    /**
     * The writes for a decided set of moves, inside the caller's transaction.
     *
     * Only `is_enabled` and the three audit columns move — the settings json and every related
     * table are deliberately left alone, which is what makes disabling reversible. Moves that land
     * on the same state with the same stored reason share one UPDATE (a whole group is usually
     * one statement, plus one per cascade source). The query builder fires no model event, so the
     * model's automatic "Module updated" row is never written: each switch is recorded exactly once,
     * by auditSwitch(), with the reason, the old and new state and the slug.
     *
     * @param  list<ModuleMove>  $moves
     * @return array<int, Module> id => the row as it now stands
     */
    private function writeMoves(array $moves, ?int $actorId): array
    {
        if ($moves === []) {
            return [];
        }

        $now = now();
        $batches = [];

        foreach ($moves as $move) {
            $stored = $move['enabled'] ? null : mb_substr($move['reason'], 0, 255);
            $key = json_encode([$move['enabled'], $stored], JSON_THROW_ON_ERROR);

            $batches[$key] ??= ['enabled' => $move['enabled'], 'reason' => $stored, 'ids' => []];
            $batches[$key]['ids'][] = $move['id'];
        }

        foreach ($batches as $batch) {
            DB::table('modules')
                ->whereIn('id', $batch['ids'])
                ->update([
                    'is_enabled' => $batch['enabled'],
                    'disabled_at' => $batch['enabled'] ? null : $now,
                    'disabled_by' => $batch['enabled'] ? null : $actorId,
                    'disable_reason' => $batch['reason'],
                    'updated_at' => $now,
                ]);
        }

        /** @var array<int, Module> $rows */
        $rows = Module::query()
            ->whereIn('id', array_values(array_unique(array_column($moves, 'id'))))
            ->get()
            ->keyBy(static fn (Module $row): int => (int) $row->getKey())
            ->all();

        foreach ($moves as $move) {
            $row = $rows[$move['id']] ?? null;

            if ($row instanceof Module) {
                $this->auditSwitch($row, $move['enabled'], $move['reason'], $move['cascaded_from']);
            }
        }

        return $rows;
    }

    /**
     * After the commit: one cache flush, then one event per module that actually moved.
     *
     * @param  list<ModuleMove>  $moves
     * @param  array<int, Module>  $rows
     */
    private function announce(array $moves, array $rows, ?int $actorId): void
    {
        if ($moves === []) {
            // Nothing moved: no write, no audit row, no cache churn.
            return;
        }

        // Done after the commit, so no request can rebuild the gate map from inside the
        // transaction window.
        Modules::flushCache();

        // Listeners only ever hear about a state that has actually been committed.
        foreach ($moves as $move) {
            $row = $rows[$move['id']] ?? null;

            if ($row instanceof Module) {
                ModuleStateChanged::dispatch(
                    $row,
                    $move['enabled'],
                    $move['reason'],
                    $actorId,
                    $move['cascaded_from'] !== null,
                    $move['cascaded_from'],
                );
            }
        }
    }

    /**
     * The single reasoned activity entry for one switch (phase-02 §6 "Module audit").
     */
    private function auditSwitch(Module $module, bool $enabled, string $reason, ?string $cascadedFrom): void
    {
        $this->audit(
            $module,
            $enabled ? 'Module enabled' : 'Module disabled',
            [
                'old' => ['is_enabled' => ! $enabled],
                'attributes' => ['is_enabled' => $enabled],
                'slug' => (string) $module->slug,
            ] + ($cascadedFrom !== null ? ['cascaded_from' => $cascadedFrom] : []),
            self::MODULE,
            $reason,
        );
    }

    /**
     * D63: the refusal for a disable that carries no reason.
     */
    private function reasonRequired(Module $module): ActionNotAllowedException
    {
        return new ActionNotAllowedException(sprintf(
            'A reason is required to switch "%s" off.',
            (string) $module->name,
        ));
    }

    /**
     * The refusal, naming every dependent — an administrator must never have to guess which
     * module is holding the switch (phase-02 §6 "names the dependents").
     *
     * @param  ModuleGraph  $graph
     * @param  list<string>  $dependents
     */
    private function dependentsBlockDisable(array $graph, string $name, array $dependents): ActionNotAllowedException
    {
        $names = $this->namesIn($graph, $dependents);
        $one = count($names) === 1;

        return new ActionNotAllowedException(sprintf(
            '"%s" cannot be switched off: %s %s still enabled and %s on it. Switch %s off first, or confirm the cascade.',
            $name,
            implode(', ', $names),
            $one ? 'is' : 'are',
            $one ? 'depends' : 'depend',
            $one ? 'it' : 'them',
        ));
    }

    /**
     * A cascade may not smuggle a core module off the air.
     *
     * @param  ModuleGraph  $graph
     * @param  list<string>  $cascadeSlugs
     *
     * @throws ActionNotAllowedException
     */
    private function assertCascadeIsPermitted(array $graph, string $name, array $cascadeSlugs): void
    {
        $core = array_values(array_filter(
            $cascadeSlugs,
            static fn (string $slug): bool => ($graph['core'][$slug] ?? false) === true,
        ));

        if ($core === []) {
            return;
        }

        throw new ActionNotAllowedException(sprintf(
            '"%s" cannot be switched off: %s would have to go with it, and a core module can never be disabled.',
            $name,
            implode(', ', $this->namesIn($graph, $core)),
        ));
    }

    private function cascadeReason(string $slug, string $reason): string
    {
        return mb_substr(sprintf('Cascaded from %s: %s', $slug, $reason), 0, 255);
    }

    private function enableReason(string $slug): string
    {
        return sprintf('Module %s enabled from the admin panel', $slug);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals — routes, sidebar and what a viewer may be told
    |--------------------------------------------------------------------------
    */

    /**
     * `slug => routes it gates`, read from the router itself.
     *
     * A route belongs to a module when it carries that module's `module:<slug>` middleware or any
     * `can:<slug>.<ability>` / `permission:<slug>.<ability>` check — the two mechanisms phase-01 §6
     * uses to close a disabled module's doors.
     *
     * Each entry also records what the route demands: one any-of list per permission-bearing
     * middleware, every list of which must be satisfied. A route guarded only by `module:` (its
     * permission decided in a policy) demands nothing that can be named here.
     *
     * @return array<string, list<RouteEntry>>
     */
    private function routeMap(): array
    {
        $map = [];

        foreach (Route::getRoutes() as $route) {
            $slugs = [];
            $requires = [];

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_contains($middleware, ':')) {
                    continue;
                }

                [$name, $argument] = explode(':', $middleware, 2);

                if ($name === 'module' || $name === 'site_module') {
                    foreach (explode(',', $argument) as $value) {
                        $value = trim($value);

                        if ($value !== '' && ! in_array($value, $slugs, true)) {
                            $slugs[] = $value;
                        }
                    }

                    continue;
                }

                if (! in_array($name, ['can', 'permission', 'role_or_permission'], true)) {
                    continue;
                }

                // `can:<ability>,<model>` — only the ability names a permission. `permission:` and
                // `role_or_permission:` take `a|b` (any of) followed by an optional guard.
                $first = trim(explode(',', $argument, 2)[0]);
                $candidates = $name === 'can' ? [$first] : array_map('trim', explode('|', $first));
                $permissions = [];

                foreach ($candidates as $value) {
                    $slug = $value === '' ? null : Modules::moduleForPermission($value);

                    if (! is_string($slug) || $slug === '') {
                        continue;
                    }

                    $permissions[] = $value;

                    if (! in_array($slug, $slugs, true)) {
                        $slugs[] = $slug;
                    }
                }

                if ($permissions !== []) {
                    $requires[] = $permissions;
                }
            }

            if ($slugs === []) {
                continue;
            }

            $entry = [
                'name' => $route->getName(),
                'uri' => '/'.ltrim((string) $route->uri(), '/'),
                'methods' => implode('|', array_values(array_diff($route->methods(), ['HEAD']))),
                'requires' => $requires,
            ];

            foreach ($slugs as $slug) {
                $map[$slug][] = $entry;
            }
        }

        return $map;
    }

    /**
     * @return list<SidebarEntry>
     */
    private function sidebarEntriesFor(string $slug): array
    {
        $found = [];

        foreach (['admin', 'collaborator', 'student', 'teacher', 'client'] as $panel) {
            foreach (Sidebar::tree($panel) as $group) {
                foreach ($group['items'] ?? [] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $this->collectSidebarItems($item, $slug, $panel, [(string) ($group['label'] ?? $panel)], $found);
                }
            }
        }

        return $found;
    }

    /**
     * Walk one declared sidebar item (and its children) looking for this module.
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $path
     * @param  list<SidebarEntry>  $found
     */
    private function collectSidebarItems(array $item, string $slug, string $panel, array $path, array &$found): void
    {
        $label = isset($item['label']) ? (string) $item['label'] : '';
        $module = isset($item['module']) ? (string) $item['module'] : null;
        $route = isset($item['route']) ? (string) $item['route'] : null;
        $permission = isset($item['permission']) && is_string($item['permission']) && $item['permission'] !== ''
            ? $item['permission']
            : null;

        if ($module === $slug && $label !== '' && $route !== null && Route::has($route)) {
            $found[] = ['panel' => $panel, 'label' => $label, 'path' => $path, 'permission' => $permission];
        }

        foreach ($item['children'] ?? [] as $child) {
            if (is_array($child)) {
                $this->collectSidebarItems($child, $slug, $panel, [...$path, $label], $found);
            }
        }
    }

    /**
     * What this viewer may be told by name: a predicate over a list of any-of permission groups.
     *
     * Permission *holding* is read from the viewer's grants, not through the Gate: `Gate::before`
     * denies every ability of a disabled module, and the preview of switching one back on is
     * exactly when an operator needs to see what returns. Fails closed — no viewer, an empty
     * requirement, or a grant lookup that throws all disclose nothing.
     *
     * @return Closure(list<list<string>>): bool
     */
    private function disclosureFor(?Authenticatable $viewer): Closure
    {
        if (! $viewer instanceof User) {
            return static fn (array $requires): bool => false;
        }

        if ($viewer->isSuperAdmin()) {
            return static fn (array $requires): bool => true;
        }

        $held = [];

        try {
            foreach ($viewer->getAllPermissions() as $permission) {
                $name = (string) ($permission->name ?? '');

                if ($name !== '') {
                    $held[$name] = true;
                }
            }
        } catch (Throwable) {
            $held = [];
        }

        return static function (array $requires) use ($held): bool {
            if ($requires === [] || $held === []) {
                return false;
            }

            foreach ($requires as $anyOf) {
                $satisfied = false;

                foreach ($anyOf as $name) {
                    if (isset($held[$name])) {
                        $satisfied = true;

                        break;
                    }
                }

                if (! $satisfied) {
                    return false;
                }
            }

            return true;
        };
    }

    private function actorId(): ?int
    {
        $id = Auth::id();

        return is_numeric($id) ? (int) $id : null;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 500);
    }
}
