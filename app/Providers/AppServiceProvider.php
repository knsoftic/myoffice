<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\ModuleStateChanged;
use App\Events\SettingsChanged;
use App\Models\Cms\CmsRevision;
use App\Models\Cms\CtaBlock;
use App\Models\Cms\Faq;
use App\Models\Cms\FaqCategory;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\Menu;
use App\Models\Cms\MenuItem;
use App\Models\Cms\Page;
use App\Models\Cms\SeoMeta;
use App\Models\Cms\SitemapGeneration;
use App\Models\Cms\WebsiteSection;
use App\Models\Cms\WebsiteSectionItem;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Policies\Cms\CmsRevisionPolicy;
use App\Policies\Cms\CtaBlockPolicy;
use App\Policies\Cms\FaqCategoryPolicy;
use App\Policies\Cms\FaqPolicy;
use App\Policies\Cms\MediaPolicy;
use App\Policies\Cms\MenuItemPolicy;
use App\Policies\Cms\MenuPolicy;
use App\Policies\Cms\PagePolicy;
use App\Policies\Cms\SeoMetaPolicy;
use App\Policies\Cms\SitemapGenerationPolicy;
use App\Policies\Cms\WebsiteSectionItemPolicy;
use App\Policies\Cms\WebsiteSectionPolicy;
use App\Policies\ModulePolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Services\Cms\CacheVersion;
use App\Services\Cms\PublicCache;
use App\Support\ConfigureFromSettings;
use App\Support\Modules;
use App\Support\SettingsRepository;
use App\Support\SiteSettings;
use Closure;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
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

        // phase-03. MediaPolicy does not follow the {Model}Policy naming convention, so explicit
        // registration is required; the rest are listed here for the same one-place reason.
        WebsiteSection::class => WebsiteSectionPolicy::class,
        WebsiteSectionItem::class => WebsiteSectionItemPolicy::class,
        Menu::class => MenuPolicy::class,
        MenuItem::class => MenuItemPolicy::class,
        Page::class => PagePolicy::class,
        CtaBlock::class => CtaBlockPolicy::class,
        Faq::class => FaqPolicy::class,
        FaqCategory::class => FaqCategoryPolicy::class,
        SeoMeta::class => SeoMetaPolicy::class,
        MediaAsset::class => MediaPolicy::class,
        CmsRevision::class => CmsRevisionPolicy::class,
        SitemapGeneration::class => SitemapGenerationPolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One settings payload per request, shared by the setting() helper (phase-01 §3).
        $this->app->singleton(SettingsRepository::class);

        // phase-03 (D22): one cache version stamp and one bump batch per request or queued job.
        $this->app->scoped(CacheVersion::class);

        // phase-03 §5.3: the public views' only settings reader (stateless, so a singleton).
        $this->app->singleton(SiteSettings::class);

        // StatisticsProvider is deliberately left unbound (a fresh instance per resolve): its memo is
        // per instance, and sharing one across a request would outlive a publish's cache-stamp bump.
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
        $this->registerPublicCacheInvalidation();
        $this->configureFromSettings();
    }

    /**
     * phase-03 §6.7 / INV-8: a saved setting the public pages embed (company, contact, SEO, website,
     * maintenance, ...) bumps the public cache version once per save, after commit. Event discovery is
     * off (EventListenerServiceProvider), so the listener is wired here explicitly.
     */
    private function registerPublicCacheInvalidation(): void
    {
        Event::listen(SettingsChanged::class, [PublicCache::class, 'settingsChanged']);

        // A module switch changes what the public pages may show (INV-12, D26): same bump, once per moved module.
        Event::listen(ModuleStateChanged::class, [PublicCache::class, 'moduleChanged']);
    }

    /**
     * Saved settings -> runtime configuration (phase-02 §3): mail transport and credentials (the
     * secret only once the mail manager is built), `app.locale`, and the derived brand palette.
     * Never `app.timezone`: storage is UTC and `localization.timezone` is display-only (D61).
     *
     * Registered last on purpose. It is the only thing in boot() that reads data, and nothing
     * above it may depend on it, so an install with no `settings` table — or with an unreadable
     * cache store — still boots with exactly the Phase 1 behaviour.
     *
     * `ConfigureFromSettings::apply()` already contains every step in its own try/catch and reads
     * through the cached settings payload rather than querying per key; this second net is here
     * because "the app must never fail to boot because of a setting" is worth stating twice.
     */
    private function configureFromSettings(): void
    {
        try {
            ConfigureFromSettings::apply();
        } catch (Throwable $exception) {
            report($exception);
        }
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
