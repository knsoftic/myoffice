<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\MenuItemLinkType;
use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\MenuVisibility;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One navigation link, optionally the child of another (phase-03 §2.6, §102 verbatim).
 *
 * At most **two levels** deep, and that is a database fact rather than a form rule: CHECK
 * `chk_mi_depth` (`depth <= 1`) and CHECK `chk_mi_parent` enforce INV-6. `depth` is denormalised by
 * `MenuService`, never typed in.
 *
 * A link is **typed** (`link_type`) so an internal link is never a hardcoded string: the URL is
 * always computed by `MenuService::resolveUrl()`, which is why renaming a page slug fixes every menu
 * at once. `linkable_*` is the hook later phases use for course / service / blog-category links;
 * Phase 3 writes nothing there.
 *
 * @property int $id
 * @property int $menu_id
 * @property int|null $parent_id
 * @property string $label
 * @property MenuItemLinkType $link_type
 * @property int|null $page_id
 * @property string|null $route_name
 * @property array<string, mixed>|null $route_params
 * @property string|null $url
 * @property string|null $anchor
 * @property string|null $linkable_type
 * @property int|null $linkable_id
 * @property string|null $icon
 * @property bool $open_new_tab
 * @property bool $rel_nofollow
 * @property MenuVisibility $visibility
 * @property bool $is_enabled
 * @property int $sort_order
 * @property int $depth
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class MenuItem extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    /** INV-6: parent (0) plus child (1), and nothing deeper. */
    public const MAX_DEPTH = 1;

    protected $table = 'menu_items';

    /**
     * `linkable_type` / `linkable_id` stay writable for the phase that owns the target type; the
     * menu form never posts them (§2.6).
     *
     * @var list<string>
     */
    protected $fillable = [
        'menu_id',
        'parent_id',
        'label',
        'link_type',
        'page_id',
        'route_name',
        'route_params',
        'url',
        'anchor',
        'linkable_type',
        'linkable_id',
        'icon',
        'open_new_tab',
        'rel_nofollow',
        'visibility',
        'is_enabled',
        'sort_order',
        'depth',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'link_type' => MenuItemLinkType::class,
            'route_params' => 'array',
            'open_new_tab' => 'boolean',
            'rel_nofollow' => 'boolean',
            'visibility' => MenuVisibility::class,
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
            'depth' => 'integer',
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

    public function isTopLevel(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * A dropdown parent that is a label only (`link_type = none`).
     */
    public function isLabelOnly(): bool
    {
        return $this->link_type === MenuItemLinkType::None;
    }

    /**
     * §8's Login button is `guest`, a "My panel" link is `auth`.
     *
     * Applied **per request, after the cache** (§6.3) — the cached tree is audience-agnostic.
     */
    public function isVisibleTo(?User $user): bool
    {
        return $this->visibility instanceof MenuVisibility
            && $this->visibility->matches($user);
    }

    /**
     * `target="_blank"` always carries `rel="noopener noreferrer"` (§6.6); `rel_nofollow` adds
     * `nofollow` for sponsored or untrusted external links.
     */
    public function relAttribute(): ?string
    {
        $rel = [];

        if ($this->open_new_tab) {
            $rel[] = 'noopener';
            $rel[] = 'noreferrer';
        }

        if ($this->rel_nofollow) {
            $rel[] = 'nofollow';
        }

        return $rel === [] ? null : implode(' ', $rel);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<MenuItem, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    /**
     * The later-phase hook (course, service, blog category). Unused in Phase 3.
     *
     * @return MorphTo<Model, $this>
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<MenuItem>  $query
     * @return Builder<MenuItem>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * @param  Builder<MenuItem>  $query
     * @return Builder<MenuItem>
     */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * @param  Builder<MenuItem>  $query
     * @return Builder<MenuItem>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @param  Builder<MenuItem>  $query
     * @return Builder<MenuItem>
     */
    public function scopeForMenu(Builder $query, Menu|int $menu): Builder
    {
        return $query->where('menu_id', $menu instanceof Menu ? $menu->getKey() : $menu);
    }

    /**
     * Items of the menu bound to one layout slot.
     *
     * @param  Builder<MenuItem>  $query
     * @return Builder<MenuItem>
     */
    public function scopeForLocation(Builder $query, MenuLocation|string $location): Builder
    {
        return $query->whereHas('menu', function (Builder $menu) use ($location): void {
            $menu->active()->forLocation($location);
        });
    }

    /**
     * A `page` item whose target is draft, trashed or missing is **omitted, not rendered dead**
     * (§6.3, §12.1 R-3). Every other link type resolves without a database row.
     *
     * @param  Builder<MenuItem>  $query
     * @return Builder<MenuItem>
     */
    public function scopeTargetResolvable(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder->where('link_type', '!=', MenuItemLinkType::Page->value)
                ->orWhereHas('page', function (Builder $page): void {
                    // The Page model soft-deletes, so a trashed target is already excluded here.
                    $page->where('status', ContentStatus::Published->value);
                });
        });
    }

    /**
     * What the public navigation may contain: enabled, with a target that resolves. Visibility
     * (`all` / `guest` / `auth`) is deliberately **not** applied here — it is a per-request decision
     * taken after the cache (§6.3), by {@see self::isVisibleTo()}.
     *
     * @param  Builder<MenuItem>  $query
     * @return Builder<MenuItem>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->enabled()->targetResolvable();
    }

    /**
     * Items an audience may see. Admin screens and preview only — see {@see self::scopeVisible()}.
     *
     * @param  Builder<MenuItem>  $query
     * @return Builder<MenuItem>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        $allowed = [MenuVisibility::All->value];
        $allowed[] = $user === null ? MenuVisibility::Guest->value : MenuVisibility::Auth->value;

        return $query->whereIn('visibility', $allowed);
    }

    /**
     * The `cms:check-links` report (§10.4): a `page` link whose target is no longer published, or a
     * typed link with no target at all.
     *
     * @param  Builder<MenuItem>  $query
     * @return Builder<MenuItem>
     */
    public function scopeBroken(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder->where(function (Builder $page): void {
                $page->where('link_type', MenuItemLinkType::Page->value)
                    ->whereDoesntHave('page', function (Builder $target): void {
                        $target->where('status', ContentStatus::Published->value);
                    });
            })->orWhere(function (Builder $route): void {
                $route->where('link_type', MenuItemLinkType::Route->value)
                    ->where(function (Builder $empty): void {
                        $empty->whereNull('route_name')->orWhere('route_name', '');
                    });
            })->orWhere(function (Builder $url): void {
                $url->where('link_type', MenuItemLinkType::Url->value)
                    ->where(function (Builder $empty): void {
                        $empty->whereNull('url')->orWhere('url', '');
                    });
            })->orWhere(function (Builder $anchor): void {
                $anchor->where('link_type', MenuItemLinkType::SectionAnchor->value)
                    ->where(function (Builder $empty): void {
                        $empty->whereNull('anchor')->orWhere('anchor', '');
                    });
            });
        });
    }
}
