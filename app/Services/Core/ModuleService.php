<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Enums\ModuleGroup;
use App\Events\ModuleStateChanged;
use App\Models\Module;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Support\Modules;
use App\Support\PermissionRegistry;
use App\Support\Sidebar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

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
 */
final class ModuleService
{
    use WritesAuditTrail;

    private const MODULE = 'modules';

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
        $graph = $this->graph();

        return array_values(array_filter(
            $graph['dependencies'][$slug] ?? [],
            static fn (string $dependency): bool => ($graph['enabled'][$dependency] ?? false) === false,
        ));
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
        $graph = $this->graph();

        return array_values(array_filter(
            $graph['dependents'][$slug] ?? [],
            static fn (string $dependent): bool => ($graph['enabled'][$dependent] ?? false) === true,
        ));
    }

    /**
     * Every enabled module that would have to go down with this one, transitively, deepest first.
     *
     * The order is what makes the cascade safe to apply in sequence: a dependent is always
     * switched off before the module it depends on, so the system is never left in a state where
     * an enabled module has a disabled dependency.
     *
     * @return list<string>
     */
    public function cascadeSet(string $slug): array
    {
        $graph = $this->graph();
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

        // Deepest dependents first: reverse discovery order, then drop duplicates.
        return array_values(array_reverse($order));
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
        $names = $graph['names'];

        $describe = static fn (array $list): array => array_values(array_map(
            static fn (string $slug): array => [
                'slug' => $slug,
                'name' => $names[$slug] ?? $slug,
                'enabled' => ($graph['enabled'][$slug] ?? false) === true,
                'is_core' => ($graph['core'][$slug] ?? false) === true,
            ],
            $list,
        ));

        $overview = [];

        foreach ($slugs as $slug) {
            $dependencies = $graph['dependencies'][$slug] ?? [];
            $dependents = $graph['dependents'][$slug] ?? [];

            $overview[$slug] = [
                'dependencies' => $describe($dependencies),
                'dependents' => $describe($dependents),
                'missing' => array_values(array_filter(
                    $dependencies,
                    static fn (string $one): bool => ($graph['enabled'][$one] ?? false) === false,
                )),
                'blocking' => array_values(array_filter(
                    $dependents,
                    static fn (string $one): bool => ($graph['enabled'][$one] ?? false) === true,
                )),
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
        $names = $this->graph()['names'];

        return array_values(array_map(
            static fn (string $slug): string => $names[$slug] ?? $slug,
            $slugs,
        ));
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
        if (! $enabled && ! $module->canBeDisabled()) {
            throw ActionNotAllowedException::coreModule((string) $module->name);
        }

        $slug = (string) $module->slug;

        $given = $this->clean($reason);

        if (! $enabled && $given === null) {
            throw $this->reasonRequired($module);
        }

        $reason = $given ?? sprintf('Module %s enabled from the admin panel', $slug);

        // The dependents rule only ever guards a disable: turning a module *on* can break nothing.
        $cascadeSlugs = [];

        if (! $enabled) {
            $blocking = $this->enabledDependents($slug);

            if ($blocking !== [] && ! $cascade) {
                throw $this->dependentsBlockDisable($module, $blocking);
            }

            if ($blocking !== []) {
                $cascadeSlugs = $this->cascadeSet($slug);
                $this->assertCascadeIsPermitted($module, $cascadeSlugs);
            }
        }

        if ((bool) $module->is_enabled === $enabled && $cascadeSlugs === []) {
            // Nothing moved: no write, no audit row, no cache churn.
            return $module;
        }

        /** @var list<Module> $cascaded */
        $cascaded = $cascadeSlugs === []
            ? []
            : Module::query()->whereIn('slug', $cascadeSlugs)->get()
                ->sortBy(static fn (Module $row): int => (int) array_search((string) $row->slug, $cascadeSlugs, true))
                ->values()
                ->all();

        $actorId = $this->actorId();
        $moved = [];

        // The state change and its reasoned audit row commit together or not at all: a switch
        // that went through without its "Module disabled" entry would be an unexplained change.
        DB::transaction(function () use ($module, $enabled, $reason, $cascaded, $actorId, $slug, &$moved): void {
            // Dependents come down first, so no enabled module is ever left pointing at a module
            // that has already gone dark.
            foreach ($cascaded as $dependent) {
                $dependentReason = $this->cascadeReason($slug, $reason);

                if ((bool) $dependent->is_enabled === false) {
                    continue;
                }

                $this->write($dependent, false, $dependentReason, $actorId);
                $this->auditSwitch($dependent, false, $dependentReason, $slug);

                $moved[] = [$dependent, false, $dependentReason, true, $slug];
            }

            if ((bool) $module->is_enabled !== $enabled) {
                $this->write($module, $enabled, $reason, $actorId);
                $this->auditSwitch($module, $enabled, $reason, null);

                $moved[] = [$module, $enabled, $reason, false, null];
            }
        });

        // Module::booted() flushes on save; doing it again after the commit guarantees that no
        // request can read a stale gate map from inside the transaction window.
        Modules::flushCache();

        // Listeners only ever hear about a state that has actually been committed.
        foreach ($moved as [$changed, $state, $why, $wasCascaded, $because]) {
            ModuleStateChanged::dispatch($changed->refresh(), $state, $why, $actorId, $wasCascaded, $because);
        }

        return $module->refresh();
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
     * it. Every successful flip is a transaction of its own — a partially applied bulk action is a
     * real state that the report names, a silently abandoned one is not.
     *
     * @param  iterable<int, Module>  $modules
     * @return array{changed: list<string>, skipped: array<string, string>, core: list<string>, cascaded: list<string>}
     */
    public function bulkSetEnabled(iterable $modules, bool $enabled, ?string $reason = null, bool $cascade = false): array
    {
        // D63: refused up front rather than reported as N identical per-module skips.
        if (! $enabled && $this->clean($reason) === null) {
            throw new ActionNotAllowedException('A reason is required to switch modules off.');
        }

        $changed = [];
        $skipped = [];
        $core = [];
        $cascaded = [];

        foreach ($this->orderForWrite($modules, $enabled) as $module) {
            $slug = (string) $module->slug;

            if ($module->isCore()) {
                $core[] = $slug;

                continue;
            }

            if ((bool) $module->is_enabled === $enabled) {
                continue;
            }

            $before = $enabled ? [] : $this->cascadeSet($slug);

            try {
                $this->setEnabled($module, $enabled, $reason, $cascade);
            } catch (ActionNotAllowedException $exception) {
                $skipped[$slug] = $exception->getMessage();

                continue;
            }

            $changed[] = $slug;

            foreach ($before as $dependent) {
                if (! in_array($dependent, $cascaded, true) && ! in_array($dependent, $changed, true)) {
                    $cascaded[] = $dependent;
                }
            }
        }

        return [
            'changed' => $changed,
            'skipped' => $skipped,
            'core' => $core,
            'cascaded' => array_values($cascaded),
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
     * @param  iterable<int, Module>  $modules
     * @return list<Module>
     */
    private function orderForWrite(iterable $modules, bool $enabled): array
    {
        $graph = $this->graph();
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
     * @return array<string, mixed>
     */
    public function impact(Module $module): array
    {
        $slug = (string) $module->slug;
        $graph = $this->graph();
        $names = $graph['names'];

        $isCore = $module->isCore();
        $on = $module->isEnabled();
        $target = ! $on;

        $dependents = $graph['dependents'][$slug] ?? [];
        $blocking = array_values(array_filter(
            $dependents,
            static fn (string $dependent): bool => ($graph['enabled'][$dependent] ?? false) === true,
        ));

        $routes = $this->routesFor($slug);
        $sidebar = $this->sidebarItemsFor($slug);

        $describe = static fn (array $slugs): array => array_values(array_map(
            static fn (string $one): array => [
                'slug' => $one,
                'name' => $names[$one] ?? $one,
                'enabled' => ($graph['enabled'][$one] ?? false) === true,
                'is_core' => ($graph['core'][$one] ?? false) === true,
            ],
            $slugs,
        ));

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

            'dependents' => $describe($dependents),
            'blocking_dependents' => $describe($blocking),
            'cascade' => $describe($target ? [] : $this->cascadeSet($slug)),

            'dependencies' => $describe($graph['dependencies'][$slug] ?? []),
            'missing_dependencies' => $describe($this->missingDependencies($slug)),

            'permissions' => [
                'count' => count(PermissionRegistry::permissionNamesFor($slug)),
            ],
            'routes' => [
                'count' => count($routes),
                'items' => $routes,
            ],
            'sidebar_items' => $sidebar,

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
     * The registered routes a module closes when it goes off.
     *
     * @return list<array{name: string|null, uri: string, methods: string}>
     */
    public function routesFor(string $slug): array
    {
        return $this->routeMap()[$slug] ?? [];
    }

    /**
     * The navigation entries that disappear when a module goes off, across every panel.
     *
     * Read straight from the declarative `Sidebar` tree, so it says what the sidebar will really
     * do — including the panel each item belongs to. Items whose route does not exist yet (a later
     * phase's screen) are left out: nothing visible would change.
     *
     * @return list<array{panel: string, label: string, path: list<string>}>
     */
    public function sidebarItemsFor(string $slug): array
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

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * One snapshot of the `modules` table, resolved into the four maps every reader needs.
     *
     * Deliberately not memoised across calls: 79 narrow rows cost less than the risk of answering
     * a dependency question from a snapshot taken before somebody else's write.
     *
     * @return array{
     *     dependencies: array<string, list<string>>,
     *     dependents: array<string, list<string>>,
     *     enabled: array<string, bool>,
     *     core: array<string, bool>,
     *     names: array<string, string>
     * }
     */
    private function graph(): array
    {
        $dependencies = [];
        $dependents = [];
        $enabled = [];
        $core = [];
        $names = [];

        /** @var array<int, object{slug: string, name: string, is_enabled: mixed, is_core: mixed, depends_on: string|null}> $rows */
        $rows = DB::table('modules')
            ->select(['slug', 'name', 'is_enabled', 'is_core', 'depends_on', 'sort_order'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->all();

        foreach ($rows as $row) {
            $slug = (string) $row->slug;

            $names[$slug] = (string) $row->name;
            $core[$slug] = (bool) $row->is_core || Modules::isCore($slug);
            $enabled[$slug] = $core[$slug] || (bool) $row->is_enabled;
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
            'dependencies' => $dependencies,
            'dependents' => $dependents,
            'enabled' => $enabled,
            'core' => $core,
            'names' => $names,
        ];
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

    /**
     * The one write. Only `is_enabled` and the three audit columns move — the settings json and
     * every related table are deliberately left alone, which is what makes disabling reversible.
     *
     * The model's automatic "Module updated" activity row is suppressed for this save: the switch
     * is recorded exactly once, by auditSwitch(), with the reason, the old and new state and the
     * slug. Two rows for one switch made every toggle look like two changes.
     */
    private function write(Module $module, bool $enabled, string $reason, ?int $actorId): void
    {
        $module->withReason($reason)->fill([
            'is_enabled' => $enabled,
            'disabled_at' => $enabled ? null : now(),
            'disabled_by' => $enabled ? null : $actorId,
            'disable_reason' => $enabled ? null : mb_substr($reason, 0, 255),
        ]);

        $module->disableLogging();

        try {
            $module->save();
        } finally {
            $module->enableLogging();
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
     * @param  list<string>  $dependents
     */
    private function dependentsBlockDisable(Module $module, array $dependents): ActionNotAllowedException
    {
        $names = $this->namesFor($dependents);
        $one = count($names) === 1;

        return new ActionNotAllowedException(sprintf(
            '"%s" cannot be switched off: %s %s still enabled and %s on it. Switch %s off first, or confirm the cascade.',
            (string) $module->name,
            implode(', ', $names),
            $one ? 'is' : 'are',
            $one ? 'depends' : 'depend',
            $one ? 'it' : 'them',
        ));
    }

    /**
     * A cascade may not smuggle a core module off the air.
     *
     * @param  list<string>  $cascadeSlugs
     *
     * @throws ActionNotAllowedException
     */
    private function assertCascadeIsPermitted(Module $module, array $cascadeSlugs): void
    {
        $graph = $this->graph();

        $core = array_values(array_filter(
            $cascadeSlugs,
            static fn (string $slug): bool => ($graph['core'][$slug] ?? false) === true,
        ));

        if ($core === []) {
            return;
        }

        throw new ActionNotAllowedException(sprintf(
            '"%s" cannot be switched off: %s would have to go with it, and a core module can never be disabled.',
            (string) $module->name,
            implode(', ', $this->namesFor($core)),
        ));
    }

    private function cascadeReason(string $slug, string $reason): string
    {
        return mb_substr(sprintf('Cascaded from %s: %s', $slug, $reason), 0, 255);
    }

    /**
     * `slug => routes it gates`, read from the router itself.
     *
     * A route belongs to a module when it carries that module's `module:<slug>` middleware or any
     * `can:<slug>.<ability>` / `permission:<slug>.<ability>` check — the two mechanisms phase-01 §6
     * uses to close a disabled module's doors.
     *
     * @return array<string, list<array{name: string|null, uri: string, methods: string}>>
     */
    private function routeMap(): array
    {
        $map = [];

        foreach (Route::getRoutes() as $route) {
            $slugs = [];

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_contains($middleware, ':')) {
                    continue;
                }

                [$name, $argument] = explode(':', $middleware, 2);

                foreach (explode(',', $argument) as $value) {
                    $value = trim($value);

                    $slug = match ($name) {
                        'module', 'site_module' => $value,
                        'can', 'permission', 'role_or_permission' => Modules::moduleForPermission($value),
                        default => null,
                    };

                    if (is_string($slug) && $slug !== '' && ! in_array($slug, $slugs, true)) {
                        $slugs[] = $slug;
                    }
                }
            }

            if ($slugs === []) {
                continue;
            }

            $entry = [
                'name' => $route->getName(),
                'uri' => '/'.ltrim((string) $route->uri(), '/'),
                'methods' => implode('|', array_values(array_diff($route->methods(), ['HEAD']))),
            ];

            foreach ($slugs as $slug) {
                $map[$slug][] = $entry;
            }
        }

        return $map;
    }

    /**
     * Walk one declared sidebar item (and its children) looking for this module.
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $path
     * @param  list<array{panel: string, label: string, path: list<string>}>  $found
     */
    private function collectSidebarItems(array $item, string $slug, string $panel, array $path, array &$found): void
    {
        $label = isset($item['label']) ? (string) $item['label'] : '';
        $module = isset($item['module']) ? (string) $item['module'] : null;
        $route = isset($item['route']) ? (string) $item['route'] : null;

        if ($module === $slug && $label !== '' && $route !== null && Route::has($route)) {
            $found[] = ['panel' => $panel, 'label' => $label, 'path' => $path];
        }

        foreach ($item['children'] ?? [] as $child) {
            if (is_array($child)) {
                $this->collectSidebarItems($child, $slug, $panel, [...$path, $label], $found);
            }
        }
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
