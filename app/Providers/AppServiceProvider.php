<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Policies\ModulePolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Support\Modules;
use App\Support\SettingsRepository;
use Closure;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Model => policy. Registered explicitly because `Role` extends a vendor model, so Laravel's
     * naming convention cannot discover it.
     *
     * @var array<class-string, class-string>
     */
    private const POLICIES = [
        User::class => UserPolicy::class,
        Role::class => RolePolicy::class,
        Module::class => ModulePolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One settings payload per request, shared by the setting() helper (phase-01 §3).
        $this->app->singleton(SettingsRepository::class);
    }

    /**
     * Bootstrap any application services.
     *
     * The Gate rules come first: registerGateRules() guarantees they are the *first* thing the
     * Gate consults, whatever else has already registered a before-callback by now.
     */
    public function boot(): void
    {
        $this->registerGateRules();
        $this->registerPolicies();
        $this->registerBladeDirectives();
    }

    /**
     * Authorization policies (phase-01 §6.3).
     */
    private function registerPolicies(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    /**
     * The global authorization pre-checks, in the order the contract fixes (phase-01 §6,
     * CLAUDE.md §4):
     *
     *   1. the ability belongs to a module that is disabled and not core → deny everyone,
     *      Super Admin included (D5: a disabled module is closed, its data untouched);
     *   2. the user holds the Super Admin role → allow;
     *   3. return null so spatie resolves the permission / the policy runs.
     *
     * The module is resolved two ways, and both have to work or rule 1 has a hole:
     *
     *   · from the ability name, for a dotted permission string (`projects.view_any`);
     *   · from the subject being authorized, for a policy-style ability (`$user->can('update',
     *     $project)` arrives here as the bare method name `update`). Without that second path a
     *     policy check on a disabled module's model would skip rule 1 entirely and a Super Admin
     *     would be allowed straight through by rule 2 — see Modules::moduleForAbility().
     *
     * Cost per check: in-memory lookups over payloads that are built once and memoised — the
     * permission => module map and the model class => module map (both derived from
     * PermissionRegistry, no query) and the slug => is_enabled map (one query, cached under
     * `modules.enabled.map` and flushed by the Module model on save).
     *
     * Registration order matters as much as the order inside the callback: spatie's
     * PermissionRegistrar adds its own `Gate::before` that returns TRUE as soon as the user holds
     * the permission, and Laravel's Gate stops at the first non-null before-result. A callback
     * that merely appends itself here (boot() runs after every package provider has booted) would
     * land behind spatie's and rule 1 would silently never fire for a permission holder — Super
     * Admin included. promoteToFirstBeforeCallback() is what keeps this one in front, so the
     * contract can have its rules in boot() and still have them decided first.
     */
    private function registerGateRules(): void
    {
        /** @var GateContract $gate */
        $gate = $this->app->make(GateContract::class);

        $rules = function (mixed $user, string $ability, array $arguments = []): ?bool {
            $module = Modules::moduleForAbility($ability, $arguments[0] ?? null);

            if ($module !== null && ! Modules::enabled($module)) {
                return false;
            }

            if ($user instanceof User && $user->isSuperAdmin()) {
                return true;
            }

            return null;
        };

        $gate->before($rules);

        $this->promoteToFirstBeforeCallback($gate, $rules);
    }

    /**
     * Move our before-callback to the front of the Gate's list, so the module denial is decided
     * ahead of spatie's permission resolution no matter which provider registered first.
     *
     * `Gate::before()` can only append, and the order is the whole point here (see
     * registerGateRules()), so the list itself is reordered. It is scoped to Laravel's own Gate
     * implementation and is a no-op for any other one; if the internals ever change shape, the
     * callback stays registered (just appended) and
     * tests/Feature/Modules/PortalModuleProtectionTest fails loudly instead of the rule quietly
     * going missing.
     */
    private function promoteToFirstBeforeCallback(GateContract $gate, Closure $rules): void
    {
        if (! $gate instanceof AccessGate) {
            return;
        }

        $promote = function () use ($rules): void {
            $others = array_values(array_filter(
                $this->beforeCallbacks,
                static fn (mixed $registered): bool => $registered !== $rules,
            ));

            $this->beforeCallbacks = [$rules, ...$others];
        };

        try {
            $bound = Closure::bind($promote, $gate, AccessGate::class);

            if ($bound !== null) {
                $bound();
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * `@module('projects') … @endmodule` — render a block only while the module is enabled
     * (also gives `@unlessmodule` / `@elsemodule`).
     */
    private function registerBladeDirectives(): void
    {
        Blade::if('module', fn (string $slug): bool => Modules::enabled($slug));
    }
}
