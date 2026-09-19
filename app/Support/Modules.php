<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Activity;
use App\Models\Cms\BlogPostBlogTag;
use App\Models\Cms\BlogPostView;
use App\Models\Cms\CtaBlock;
use App\Models\Cms\JobOpening;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\MenuItem;
use App\Models\Cms\PortfolioItem;
use App\Models\Cms\PortfolioItemMedia;
use App\Models\Cms\PortfolioItemTechnology;
use App\Models\Cms\SeoMeta;
use App\Models\Cms\ServiceTechnology;
use App\Models\Cms\SitemapGeneration;
use App\Models\Cms\TeamMember;
use App\Models\Cms\WebsiteSectionItem;
use App\Models\Crm\ClientContact;
use App\Models\Crm\LeadActivity;
use App\Models\Crm\LeadConversion;
use App\Models\Crm\LeadFollowUp;
use App\Models\Crm\LeadImport;
use App\Models\Crm\LeadImportRow;
use App\Models\LoginHistory;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Module gate: answers "is this module turned on?" for the Gate, middleware, sidebar and Blade.
 *
 * Everything here is cached forever and flushed by the Module model on save/delete (and by
 * `Modules::flushCache()` / `php artisan optimize:clear`).
 *
 * Install safety: every read degrades gracefully. Before the `modules` table (or the cache store)
 * exists, lookups return "enabled" so that `artisan migrate`, `db:seed` and `package:discover`
 * never hard-fail while the Gate is already wired up.
 */
final class Modules
{
    /** Cache key prefix for everything this class stores. */
    public const CACHE_PREFIX = 'modules.';

    /** slug => is_enabled (bool). Shared with Module::enabled() per phase-01 §3. */
    public const MAP_KEY = self::CACHE_PREFIX.'enabled.map';

    /** slug => full module row (array). */
    public const ALL_KEY = self::CACHE_PREFIX.'all';

    /** permission name => module slug. */
    public const PERMISSION_MAP_KEY = self::CACHE_PREFIX.'permission_map';

    /**
     * The method a model may implement to name the module it belongs to, for policy-style
     * authorization (`$user->can('update', $project)`), where the ability carries no module.
     *
     * A later phase that introduces an interface for this only has to make the interface declare
     * this same method name — the resolver below already honours it.
     */
    public const SUBJECT_MODULE_METHOD = 'moduleSlug';

    /**
     * Model class => module slug, for the classes whose name does not pluralise into their slug
     * (and for the core models, so the common cases never have to be guessed).
     *
     * @var array<class-string, string>
     */
    private const MODEL_MODULES = [
        Activity::class => 'activity_log',
        LoginHistory::class => 'login_history',
        Module::class => 'modules',
        Permission::class => 'permissions',
        Role::class => 'roles',
        Setting::class => 'settings',
        User::class => 'users',

        // phase-03: models whose class name does not pluralise into their module slug. An instance
        // answers through moduleSlug(); a class string (`can('viewAny', CtaBlock::class)`) needs this map.
        MenuItem::class => 'menus',
        WebsiteSectionItem::class => 'website_sections',
        CtaBlock::class => 'website_cta_blocks',
        MediaAsset::class => 'website_media',
        SeoMeta::class => 'seo',
        SitemapGeneration::class => 'seo',

        // phase-04: models whose class name does not pluralise into their module slug.
        PortfolioItem::class => 'portfolio',
        PortfolioItemMedia::class => 'portfolio',
        PortfolioItemTechnology::class => 'portfolio',
        ServiceTechnology::class => 'services',
        TeamMember::class => 'team',
        BlogPostView::class => 'blog_posts',
        BlogPostBlogTag::class => 'blog_posts',
        JobOpening::class => 'jobs',

        // phase-05 §4.1: the lead sub-records belong to the `leads` module, contacts to `clients`.
        LeadActivity::class => 'leads',
        LeadFollowUp::class => 'leads',
        LeadConversion::class => 'leads',
        LeadImport::class => 'leads',
        LeadImportRow::class => 'leads',
        ClientContact::class => 'clients',
    ];

    /** @var array<string, bool>|null */
    private static ?array $map = null;

    /** @var array<string, string>|null */
    private static ?array $permissionMap = null;

    /**
     * class name => module slug (or null when the class belongs to no module), memoised for the
     * request. Pure string work over a code-derived map: no query, no cache store.
     *
     * @var array<class-string, string|null>
     */
    private static array $subjectMap = [];

    /**
     * Is the module enabled?
     *
     * Core modules are always enabled. An unknown slug is treated as enabled so that a missing
     * row (fresh install, module not seeded yet) never silently locks the application down.
     */
    public static function enabled(string $slug): bool
    {
        if (self::isCore($slug)) {
            return true;
        }

        $map = self::map();

        // Cast: the payload under MAP_KEY is shared with Module::enabled() and may hold 0/1.
        return array_key_exists($slug, $map) ? (bool) $map[$slug] : true;
    }

    /**
     * Is the slug a registered module at all? Unlike enabled(), an unknown slug is false.
     */
    public static function exists(string $slug): bool
    {
        return PermissionRegistry::module($slug) !== null;
    }

    /**
     * slug => is_enabled.
     *
     * @return array<string, bool>
     */
    public static function map(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        try {
            /** @var array<string, bool> $map */
            $map = Cache::rememberForever(self::MAP_KEY, static function (): array {
                $map = [];

                foreach (DB::table('modules')->select('slug', 'is_enabled')->get() as $row) {
                    $map[(string) $row->slug] = (bool) $row->is_enabled;
                }

                return $map;
            });

            if (! is_array($map)) {
                return [];
            }

            $normalised = [];

            foreach ($map as $slug => $isEnabled) {
                $normalised[(string) $slug] = (bool) $isEnabled;
            }

            return self::$map = $normalised;
        } catch (Throwable) {
            // Table or cache store not available yet: do not memoise, do not cache.
            return [];
        }
    }

    /**
     * Every module row, keyed by slug and sorted by `sort_order`.
     *
     * The registry is the base (so a module that exists in code but has not been seeded yet is
     * still listed), the `modules` table overlays whatever an admin has edited, and `is_enabled`
     * always comes from map() — the one payload the Module model flushes on save.
     *
     * @return Collection<string, array{
     *     slug: string,
     *     name: string,
     *     description: string|null,
     *     icon: string|null,
     *     group: string,
     *     is_enabled: bool,
     *     is_core: bool,
     *     sort_order: int
     * }>
     */
    public static function all(): Collection
    {
        $rows = self::fromRegistry();
        $stored = self::stored();
        $map = self::map();

        foreach ($stored as $slug => $row) {
            $rows[$slug] = array_merge($rows[$slug] ?? [], $row, [
                // A row present in the database but absent from the registry keeps its own flag.
                'is_core' => isset($rows[$slug]) ? $rows[$slug]['is_core'] : $row['is_core'],
            ]);
        }

        foreach ($rows as $slug => $row) {
            $rows[$slug]['is_enabled'] = $row['is_core'] === true
                || (array_key_exists($slug, $map) ? (bool) $map[$slug] : ($row['is_enabled'] ?? true));
        }

        uasort(
            $rows,
            static fn (array $a, array $b): int => [$a['sort_order'], $a['name']] <=> [$b['sort_order'], $b['name']]
        );

        return new Collection($rows);
    }

    /**
     * The `modules` table, cached. Empty when the table (or the cache store) is not there yet.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function stored(): array
    {
        try {
            /** @var array<string, array<string, mixed>> $rows */
            $rows = Cache::rememberForever(self::ALL_KEY, static function (): array {
                $rows = [];

                foreach (DB::table('modules')->get() as $record) {
                    $rows[(string) $record->slug] = [
                        'slug' => (string) $record->slug,
                        'name' => (string) $record->name,
                        'description' => $record->description === null ? null : (string) $record->description,
                        'icon' => $record->icon === null ? null : (string) $record->icon,
                        'group' => (string) $record->group,
                        'is_enabled' => (bool) $record->is_enabled,
                        'is_core' => (bool) $record->is_core,
                        'sort_order' => (int) $record->sort_order,
                    ];
                }

                return $rows;
            });

            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The registered modules as a plain slug => name map.
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        return self::all()->map(static fn (array $row): string => $row['name'])->all();
    }

    /**
     * permission name => module slug, built from the registry (the source of truth) and cached.
     *
     * Used by `Gate::before` to deny every ability that belongs to a disabled, non-core module.
     *
     * @return array<string, string>
     */
    public static function permissionModuleMap(): array
    {
        if (self::$permissionMap !== null) {
            return self::$permissionMap;
        }

        $build = static function (): array {
            $map = [];

            foreach (PermissionRegistry::permissions() as $permission) {
                $map[$permission['name']] = $permission['module'];
            }

            return $map;
        };

        try {
            /** @var array<string, string> $map */
            $map = Cache::rememberForever(self::PERMISSION_MAP_KEY, $build);

            if (! is_array($map) || $map === []) {
                return self::$permissionMap = $build();
            }

            return self::$permissionMap = $map;
        } catch (Throwable) {
            return self::$permissionMap = $build();
        }
    }

    /**
     * The module slug an ability belongs to, or null when the ability is not module-scoped.
     */
    public static function moduleForPermission(string $permission): ?string
    {
        return self::permissionModuleMap()[$permission] ?? null;
    }

    /**
     * The module an authorization check belongs to, from either half of the check.
     *
     * `Gate::before` sees two shapes of ability and has to gate both (phase-01 §6.1 — a disabled
     * module is closed for everyone):
     *
     *   · a permission name — `projects.view_any` — which names its module itself;
     *   · a policy ability — `update`, `viewAny`, `toggle` — which does not. There the module
     *     comes from the subject: `$user->can('update', $project)` hands the model (or the class
     *     name, for class-level checks such as `viewAny`) to the callback as the first argument.
     *
     * The dotted name wins when it resolves, so this can never change the meaning of an existing
     * permission check; the subject is only consulted for abilities the permission map does not
     * know.
     */
    public static function moduleForAbility(string $ability, mixed $subject = null): ?string
    {
        return self::moduleForPermission($ability) ?? self::moduleForSubject($subject);
    }

    /**
     * The module that owns the thing being authorized, or null when it owns none.
     *
     * Resolution order — first hit wins:
     *   1. the subject names its own module via `moduleSlug()` (self::SUBJECT_MODULE_METHOD);
     *   2. the explicit class => slug map, walking up the parent classes (so a subclass of a
     *      mapped model resolves too);
     *   3. the project convention (CLAUDE.md §3: a module slug is the snake_case plural of its
     *      model) — accepted only when it names a module the registry actually declares, so a
     *      guess can never invent a module or gate something the registry does not own.
     *
     * Anything else — a null subject, a plain string (spatie passes a guard name that way), an
     * unregistered model — resolves to null and leaves the check ungated by the module rule.
     */
    public static function moduleForSubject(mixed $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        if (is_object($subject) && method_exists($subject, self::SUBJECT_MODULE_METHOD)) {
            try {
                /** @var mixed $declared */
                $declared = $subject->{self::SUBJECT_MODULE_METHOD}();
            } catch (Throwable) {
                $declared = null;
            }

            if (is_string($declared) && $declared !== '' && self::exists($declared)) {
                return $declared;
            }
        }

        $class = match (true) {
            is_object($subject) => $subject::class,
            is_string($subject) => $subject,
            default => null,
        };

        if ($class === null || $class === '' || ! class_exists($class)) {
            return null;
        }

        if (array_key_exists($class, self::$subjectMap)) {
            return self::$subjectMap[$class];
        }

        return self::$subjectMap[$class] = self::resolveClassModule($class);
    }

    /**
     * The explicit map, then the naming convention.
     *
     * @param  class-string  $class
     */
    private static function resolveClassModule(string $class): ?string
    {
        foreach ([$class, ...array_values(class_parents($class) ?: [])] as $candidate) {
            if (isset(self::MODEL_MODULES[$candidate])) {
                return self::MODEL_MODULES[$candidate];
            }
        }

        $slug = Str::snake(Str::pluralStudly(class_basename($class)));

        return self::exists($slug) ? $slug : null;
    }

    /**
     * Core modules can never be disabled. The registry decides, not the database row.
     */
    public static function isCore(string $slug): bool
    {
        return in_array($slug, PermissionRegistry::coreSlugs(), true);
    }

    /**
     * Forget every cached module payload.
     */
    public static function flushCache(): void
    {
        self::$map = null;
        self::$permissionMap = null;
        self::$subjectMap = [];

        foreach ([self::MAP_KEY, self::ALL_KEY, self::PERMISSION_MAP_KEY] as $key) {
            try {
                Cache::forget($key);
            } catch (Throwable) {
                // nothing cached yet / store unavailable
            }
        }
    }

    /**
     * Registry fallback shaped like a `modules` table row.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fromRegistry(): array
    {
        $rows = [];

        foreach (PermissionRegistry::modules() as $slug => $definition) {
            $rows[$slug] = [
                'slug' => $slug,
                'name' => $definition['name'],
                'description' => null,
                'icon' => $definition['icon'],
                'group' => $definition['group']->value,
                'is_enabled' => true,
                'is_core' => $definition['is_core'],
                'sort_order' => $definition['sort'],
            ];
        }

        return $rows;
    }
}
