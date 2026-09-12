<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModuleGroup;
use App\Models\Concerns\LogsActivityWithContext;
use App\Support\Modules;
use App\Support\PermissionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    | Relations
    |--------------------------------------------------------------------------
    */

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
