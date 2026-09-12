<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Ability;
use App\Enums\ModuleGroup;
use App\Models\Casts\AbilityCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * spatie permission + the matrix columns from phase-01 §1.2.
 *
 * A permission is always `{module}.{ability}` and is generated from
 * App\Support\PermissionRegistry — never typed by hand (decision D4).
 *
 * Bound through `config('permission.models.permission')`.
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property string $module
 * @property Ability|string $ability
 * @property string|null $group
 * @property string|null $label
 * @property string|null $description
 * @property int $sort_order
 */
class Permission extends SpatiePermission
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'guard_name',
        'module',
        'ability',
        'group',
        'label',
        'description',
        'sort_order',
    ];

    /**
     * `ability` is cast to an App\Enums\Ability case through AbilityCast rather than the bare
     * enum: the portal permission prefixes (§4) declare free-form abilities that are not
     * Ability cases, and Laravel's enum cast would throw a ValueError when reading them.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ability' => AbilityCast::class,
            'sort_order' => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * The module this permission belongs to, matched on `modules.slug`.
     *
     * Named `moduleModel()` because `module` is the string column itself.
     *
     * @return BelongsTo<Module, $this>
     */
    public function moduleModel(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module', 'slug');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * What to show in the UI: the seeded label, falling back to the permission name.
     */
    public function displayName(): string
    {
        $label = trim((string) $this->label);

        return $label !== '' ? $label : (string) $this->name;
    }

    /**
     * The ability as an enum case, or null when the stored value is not a known ability
     * (the portal prefixes declare free-form abilities such as `student_fee_status`).
     */
    public function abilityCase(): ?Ability
    {
        if ($this->ability instanceof Ability) {
            return $this->ability;
        }

        $raw = $this->getRawOriginal('ability');

        return is_string($raw) ? Ability::tryFrom($raw) : null;
    }

    /**
     * The module group as an enum case, when the stored group maps to one.
     */
    public function groupCase(): ?ModuleGroup
    {
        $group = $this->group;

        return is_string($group) ? ModuleGroup::tryFrom($group) : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<Permission>  $query
     * @param  Module|string|array<int, string>  $module
     * @return Builder<Permission>
     */
    public function scopeForModule(Builder $query, Module|string|array $module): Builder
    {
        if ($module instanceof Module) {
            return $query->where('module', $module->slug);
        }

        if (is_array($module)) {
            return $query->whereIn('module', $module);
        }

        return $query->where('module', $module);
    }

    /**
     * @param  Builder<Permission>  $query
     * @return Builder<Permission>
     */
    public function scopeForGroup(Builder $query, ModuleGroup|string $group): Builder
    {
        return $query->where('group', $group instanceof ModuleGroup ? $group->value : $group);
    }

    /**
     * @param  Builder<Permission>  $query
     * @return Builder<Permission>
     */
    public function scopeForAbility(Builder $query, Ability|string $ability): Builder
    {
        return $query->where('ability', $ability instanceof Ability ? $ability->value : $ability);
    }

    /**
     * The order the permission matrix is rendered in.
     *
     * @param  Builder<Permission>  $query
     * @return Builder<Permission>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @param  Builder<Permission>  $query
     * @return Builder<Permission>
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
                ->orWhere('label', 'like', $like)
                ->orWhere('module', 'like', $like);
        });
    }
}
