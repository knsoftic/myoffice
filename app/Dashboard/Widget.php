<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Dashboard\Contracts\DashboardWidget;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * Convenience base for a dashboard widget.
 *
 * Extending this is optional — `DashboardRegistry` only ever asks for
 * `App\Dashboard\Contracts\DashboardWidget` — but it fills in every method a card usually does
 * not care about, and it publishes the optional presentation hooks the descriptor reads:
 *
 *   subtitle()      a line under the title (often the range label)
 *   sort()          position inside the widget's group, before the user's own arrangement
 *   deferred()      true = do not query on page load; the browser fetches the JSON and an
 *                   x-ui.skeleton holds the space until it arrives
 *   href()          "view all" destination (already guarded by Route::has)
 *   skeleton()      which x-ui.skeleton variant matches this card's real body
 *   minHeight()     px height reserved for the body, so a deferred card does not jump
 *   emptyMessage()  what the card says when there is genuinely nothing to report
 *   padded()        false when the body is a list or table that should run edge to edge
 *
 * A subclass supplies `key()`, `title()`, `data()` and usually `permission()`; everything else is
 * inferred. `view()` defaults to `admin.dashboard.widgets.<kebab key>` so the file name and the
 * key can never drift apart.
 */
abstract class Widget implements DashboardWidget
{
    /*
    |--------------------------------------------------------------------------
    | Identity — the parts every widget overrides
    |--------------------------------------------------------------------------
    */

    public function icon(): string
    {
        return 'squares-2x2';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function module(): ?string
    {
        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Layout
    |--------------------------------------------------------------------------
    */

    public function span(): int
    {
        return 4;
    }

    public function group(): string
    {
        return WidgetGroup::OVERVIEW;
    }

    /**
     * `dashboard_widget_key` -> `admin.dashboard.widgets.dashboard-widget-key`.
     */
    public function view(): string
    {
        return 'admin.dashboard.widgets.'.str_replace('_', '-', $this->key());
    }

    /*
    |--------------------------------------------------------------------------
    | Optional presentation hooks (read through method_exists by WidgetDescriptor)
    |--------------------------------------------------------------------------
    */

    public function subtitle(): ?string
    {
        return null;
    }

    public function sort(): int
    {
        return 100;
    }

    /**
     * Deferred widgets are the expensive ones — a filesystem walk, an `information_schema`
     * lookup. They are skipped on the initial render (so the dashboard's query budget stays
     * flat) and fetched by the browser afterwards, behind a skeleton.
     */
    public function deferred(): bool
    {
        return false;
    }

    public function href(): ?string
    {
        return null;
    }

    /** One of the x-ui.skeleton variants: text | row | stat | card. */
    public function skeleton(): string
    {
        return 'text';
    }

    /** Reserved body height in pixels — keeps a deferred card from resizing on arrival. */
    public function minHeight(): ?int
    {
        return null;
    }

    public function emptyMessage(): ?string
    {
        return null;
    }

    /**
     * False when the body is a list or a table that should reach the card's edges, exactly as
     * `x-ui.card`'s own `:padded` prop means it.
     */
    public function padded(): bool
    {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers for subclasses
    |--------------------------------------------------------------------------
    */

    /**
     * A route URL, but only when that route exists — later phases register some of the screens
     * these cards link to, so a widget must survive their absence.
     */
    protected function routeUrl(string $name, mixed $parameters = []): ?string
    {
        if (! Route::has($name)) {
            return null;
        }

        try {
            return route($name, $parameters);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A "view all" link with a query string, e.g. the login history filtered to failures.
     *
     * @param  array<string, mixed>  $query
     */
    protected function routeUrlWithQuery(string $name, array $query): ?string
    {
        $url = $this->routeUrl($name);

        if ($url === null) {
            return null;
        }

        $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');

        return $query === [] ? $url : $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    /**
     * Derive a default key from the class name: `UsersByStatusWidget` -> `users_by_status`.
     *
     * Subclasses normally declare `key()` literally — a key is permanent and should be readable
     * in the class, not computed — but this keeps a hand-written widget working if they do not.
     */
    public function key(): string
    {
        return Str::snake(Str::replaceLast('Widget', '', class_basename(static::class)));
    }
}
