<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\Cms\MenuLocation;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One navigation container bound to one layout slot (phase-03 §2.5, §102).
 *
 * `UNIQUE uq_menus_location` means **one menu per slot** ([D-W3-4]) — which is why the footer has
 * three separate locations rather than one menu with columns, and why `scopeForLocation()` is an
 * exact lookup rather than a list.
 *
 * **Status-gated and live, not snapshot-published** (§2.15): a menu is navigation, not prose. The
 * header/footer section's `published_content` stores the *resolved tree* at publish time, so a
 * half-built menu never appears; editing a menu afterwards goes live on the next cache bump
 * ([D-W3-8]).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property MenuLocation $location
 * @property string|null $description
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Menu extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'menus';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'location',
        'description',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'location' => MenuLocation::class,
            'is_active' => 'boolean',
        ];
    }

    protected function activityModule(): ?string
    {
        return 'menus';
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The menu filling one layout slot, applying the enum's own fallback: only `mobile` falls back
     * (to `header`, §3), and a missing footer column is a deliberate empty column.
     *
     * `MenuService::tree()` is the caching read path — this is the uncached resolver it builds on.
     */
    public static function forSlot(MenuLocation $location): ?self
    {
        $menu = static::query()->active()->forLocation($location)->first();

        if ($menu instanceof self) {
            return $menu;
        }

        $fallback = $location->fallback();

        return $fallback === null ? null : static::query()->active()->forLocation($fallback)->first();
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * Every item, both levels, in tree order.
     *
     * @return HasMany<MenuItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'menu_id')
            ->orderBy('depth')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Top-level items only; load `children` on it to walk the two-level tree (INV-6).
     *
     * @return HasMany<MenuItem, $this>
     */
    public function rootItems(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'menu_id')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * The header/footer sections that point at this menu through `website_sections.menu_id` (INV-3).
     *
     * @return HasMany<WebsiteSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(WebsiteSection::class, 'menu_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<Menu>  $query
     * @return Builder<Menu>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * What the public layout may render (§9). A menu has no `status` column: active is the whole gate.
     *
     * @param  Builder<Menu>  $query
     * @return Builder<Menu>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->active();
    }

    /**
     * The one menu bound to a layout slot (`UNIQUE uq_menus_location`).
     *
     * @param  Builder<Menu>  $query
     * @return Builder<Menu>
     */
    public function scopeForLocation(Builder $query, MenuLocation|string $location): Builder
    {
        return $query->where(
            'location',
            $location instanceof MenuLocation ? $location->value : $location
        );
    }

    /**
     * @param  Builder<Menu>  $query
     * @return Builder<Menu>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('location')->orderBy('name')->orderBy('id');
    }

    /**
     * @param  Builder<Menu>  $query
     * @return Builder<Menu>
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
