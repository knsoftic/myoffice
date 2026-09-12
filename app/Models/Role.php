<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PanelType;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * spatie role + the project columns from phase-01 §1.2: which panel the role belongs to,
 * how powerful it is (`level`, lower = more powerful) and whether it is protected.
 *
 * Bound through `config('permission.models.role')`.
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property string|null $label
 * @property string|null $description
 * @property PanelType $panel
 * @property int $level
 * @property bool $is_system
 * @property bool $is_default
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Role extends SpatieRole
{
    use Blameable;
    use LogsActivityWithContext;

    /**
     * spatie guards only the primary key; an explicit whitelist is safer and still covers
     * everything `Role::create()` / `findOrCreate()` need.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'guard_name',
        'label',
        'description',
        'panel',
        'level',
        'is_system',
        'is_default',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'panel' => PanelType::class,
            'level' => 'integer',
            'is_system' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    protected function activityModule(): ?string
    {
        return 'roles';
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    /**
     * System roles cannot be renamed or deleted (phase-01 §5, §10).
     */
    public function isProtected(): bool
    {
        return (bool) $this->is_system;
    }

    /**
     * Role handed to new accounts of this panel when none is chosen.
     */
    public function isDefault(): bool
    {
        return (bool) $this->is_default;
    }

    /**
     * The all-powerful role the Gate short-circuits for.
     */
    public function isSuperAdmin(): bool
    {
        return $this->name === User::SUPER_ADMIN_ROLE;
    }

    /**
     * What to show in the UI: the human label, falling back to the technical name.
     */
    public function displayName(): string
    {
        $label = trim((string) $this->label);

        return $label !== '' ? $label : (string) $this->name;
    }

    /**
     * The panel this role belongs to, never null.
     */
    public function panelType(): PanelType
    {
        return $this->panel instanceof PanelType ? $this->panel : PanelType::staffPanel();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeForPanel(Builder $query, PanelType|string $panel): Builder
    {
        return $query->where('panel', $panel instanceof PanelType ? $panel->value : $panel);
    }

    /**
     * Most powerful first, then alphabetically — the order the roles index uses.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('level')->orderBy('name');
    }

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeSystem(Builder $query, bool $isSystem = true): Builder
    {
        return $query->where('is_system', $isSystem);
    }

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
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
                ->orWhere('description', 'like', $like);
        });
    }
}
