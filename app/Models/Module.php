<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModuleGroup;
use App\Models\Concerns\LogsActivityWithContext;
use App\Support\Modules;
use App\Support\PermissionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A switchable feature area (phase-01 §1.3, decision D5).
 *
 * Disabling a module denies every one of its permissions to everyone — Super Admin included —
 * hides its sidebar entries and 403s its routes, while leaving the data untouched. Core
 * modules can never be disabled.
 *
 * Reads go through App\Support\Modules (one cached map); this model is the write side and is
 * responsible for flushing that cache.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string|null $icon
 * @property ModuleGroup $group
 * @property bool $is_enabled
 * @property bool $is_core
 * @property int $sort_order
 * @property array<string, mixed>|null $settings
 * @property list<string>|null $depends_on module slugs this module needs (phase-02 §1)
 * @property Carbon|null $disabled_at
 * @property int|null $disabled_by
 * @property string|null $disable_reason
 */
class Module extends Model
{
    use LogsActivityWithContext;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'description',
        'icon',
        'group',
        'is_enabled',
        'is_core',
        'sort_order',
        'settings',
        'depends_on',
        'disabled_at',
        'disabled_by',
        'disable_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'group' => ModuleGroup::class,
            'is_enabled' => 'boolean',
            'is_core' => 'boolean',
            'sort_order' => 'integer',
            'settings' => 'array',
            'depends_on' => 'array',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * Any change to a module invalidates the gate map the whole application reads.
     */
    protected static function booted(): void
    {
        static::saved(static function (): void {
            Modules::flushCache();
        });

        static::deleted(static function (): void {
            Modules::flushCache();
        });
    }

    protected function activityModule(): ?string
    {
        return 'modules';
    }

    /*
    |--------------------------------------------------------------------------
    | Gate
    |--------------------------------------------------------------------------
    */

    /**
     * Is the module with this slug turned on? (cached — see App\Support\Modules)
     *
     * Note this is the static lookup helper, not the `enabled` query scope below; Eloquent
     * resolves `Module::query()->enabled()` to the scope and `Module::enabled('projects')`
     * to this method.
     */
    public static function enabled(string $slug): bool
    {
        return Modules::enabled($slug);
    }

    /**
     * Core modules are structural (dashboard, users, roles, settings, …) and are never
     * disableable. The registry decides, not the stored row.
     */
    public function isCore(): bool
    {
        return (bool) $this->is_core || Modules::isCore((string) $this->slug);
    }

    /**
     * Effective state: core modules are always on.
     */
    public function isEnabled(): bool
    {
        return $this->isCore() || (bool) $this->is_enabled;
    }

    /**
     * May an administrator flip this module off?
     */
    public function canBeDisabled(): bool
    {
        return ! $this->isCore();
    }

    /**
     * The registry definition backing this row, when there is one.
     *
     * @return array<string, mixed>|null
     */
    public function definition(): ?array
    {
        return PermissionRegistry::module((string) $this->slug);
    }

    /**
     * Read one value out of the `settings` json column.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        $settings = $this->settings;

        if (! is_array($settings)) {
            return $default;
        }

        return data_get($settings, $key, $default);
    }

    /*
    |--------------------------------------------------------------------------
    | Dependencies (phase-02 §1, §3)
    |--------------------------------------------------------------------------
    |
    | `depends_on` stores plain module slugs, not foreign keys: a dependency may name a module
    | that is declared in PermissionRegistry but has not been seeded yet, and the registry — not
    | the table — decides what a slug means. Every helper below therefore resolves its slugs
    | through the registry and simply ignores anything the registry does not declare.
    |
    | dependencies() and dependents() are plain helpers, NOT Eloquent relations: call them as
    | methods ($module->dependents()), never as properties.
    */

    /**
     * The dependency slugs this module declares, resolved through the registry: trimmed, unique,
     * self-reference removed, and limited to slugs PermissionRegistry actually declares.
     *
     * @return list<string>
     */
    public function dependencySlugs(): array
    {
        return array_values(array_filter(
            $this->declaredDependencySlugs(),
            static fn (string $slug): bool => Modules::exists($slug),
        ));
    }

    /**
     * Declared slugs the registry does not know — a typo or a module removed from the registry.
     * Surfaced so the modules screen can show a broken chip instead of silently dropping it.
     *
     * @return list<string>
     */
    public function unknownDependencySlugs(): array
    {
        return array_values(array_filter(
            $this->declaredDependencySlugs(),
            static fn (string $slug): bool => ! Modules::exists($slug),
        ));
    }

    /**
     * Does this module declare a dependency on that slug?
     */
    public function dependsOn(string $slug): bool
    {
        return in_array($slug, $this->dependencySlugs(), true);
    }

    /**
     * The module rows this module depends on, keyed by slug and in screen order.
     *
     * A declared, registry-known slug with no row yet is absent from this collection — read
     * dependencySlugs() when the slug itself is what matters.
     *
     * @return EloquentCollection<string, Module>
     */
    public function dependencies(): EloquentCollection
    {
        $slugs = $this->dependencySlugs();

        if ($slugs === []) {
            /** @var EloquentCollection<string, Module> $empty */
            $empty = new EloquentCollection;

            return $empty;
        }

        /** @var EloquentCollection<string, Module> $dependencies */
        $dependencies = static::query()
            ->whereIn('slug', $slugs)
            ->ordered()
            ->get()
            ->keyBy('slug');

        return $dependencies;
    }

    /**
     * The module rows that depend on this one, keyed by slug and in screen order.
     *
     * This is what blocks a disable: a module with enabled dependents may only be turned off
     * through an explicit cascade (phase-02 §3, ModuleService::toggle()).
     *
     * @return EloquentCollection<string, Module>
     */
    public function dependents(): EloquentCollection
    {
        $slug = trim((string) $this->slug);

        if ($slug === '') {
            /** @var EloquentCollection<string, Module> $empty */
            $empty = new EloquentCollection;

            return $empty;
        }

        /** @var EloquentCollection<string, Module> $dependents */
        $dependents = static::query()
            ->dependingOn($slug)
            ->ordered()
            ->get()
            // json_contains matches the raw document; re-check in PHP so the registry filter and
            // the self-reference rule apply here too.
            ->filter(static fn (Module $module): bool => $module->dependsOn($slug))
            ->keyBy('slug');

        return $dependents;
    }

    /**
     * Slugs of the modules that depend on this one.
     *
     * @return list<string>
     */
    public function dependentSlugs(): array
    {
        return array_values($this->dependents()->keys()->map(
            static fn (mixed $slug): string => (string) $slug
        )->all());
    }

    /**
     * Declared slugs exactly as stored, normalised to unique non-empty strings without the
     * module's own slug. Pure string work — no registry lookup, no query.
     *
     * @return list<string>
     */
    private function declaredDependencySlugs(): array
    {
        $declared = $this->depends_on;

        if (! is_array($declared)) {
            return [];
        }

        $own = trim((string) $this->slug);
        $slugs = [];

        foreach ($declared as $slug) {
            if (! is_string($slug)) {
                continue;
            }

            $slug = trim($slug);

            if ($slug === '' || $slug === $own || in_array($slug, $slugs, true)) {
                continue;
            }

            $slugs[] = $slug;
        }

        return $slugs;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * Who turned the module off (phase-02 §1). Null when it has never been disabled.
     *
     * @return BelongsTo<User, $this>
     */
    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }

    /**
     * Every permission declared for this module, matched on `permissions.module`.
     *
     * @return HasMany<Permission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'module', 'slug');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<Module>  $query
     * @return Builder<Module>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder->where('is_enabled', true)->orWhere('is_core', true);
        });
    }

    /**
     * @param  Builder<Module>  $query
     * @return Builder<Module>
     */
    public function scopeDisabled(Builder $query): Builder
    {
        return $query->where('is_enabled', false)->where('is_core', false);
    }

    /**
     * @param  Builder<Module>  $query
     * @return Builder<Module>
     */
    public function scopeForGroup(Builder $query, ModuleGroup|string $group): Builder
    {
        return $query->where('group', $group instanceof ModuleGroup ? $group->value : $group);
    }

    /**
     * Modules whose `depends_on` document names this slug.
     *
     * The JSON match is the index-free cheap filter; Module::dependents() re-checks every row in
     * PHP, so a stray document shape can never widen the result.
     *
     * @param  Builder<Module>  $query
     * @return Builder<Module>
     */
    public function scopeDependingOn(Builder $query, string $slug): Builder
    {
        $slug = trim($slug);

        if ($slug === '') {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereNotNull('depends_on')->whereJsonContains('depends_on', $slug);
    }

    /**
     * The order the modules screen and the sidebar use.
     *
     * @param  Builder<Module>  $query
     * @return Builder<Module>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @param  Builder<Module>  $query
     * @return Builder<Module>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $query->where(function (Builder $builder) use ($like): void {
            $builder->where('name', 'like', $like)
                ->orWhere('slug', 'like', $like)
                ->orWhere('description', 'like', $like);
        });
    }
}
