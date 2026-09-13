<?php

declare(strict_types=1);

namespace App\Dashboard\Contracts;

use App\Support\DateRange;

/**
 * One card on the admin dashboard (phase-02 §3).
 *
 * This is the extension point the remaining phases plug into: a phase adds a card by dropping a
 * class implementing this interface into `app/Dashboard/Widgets/` (any depth). Nothing else
 * changes — not the controller, not the dashboard view, not `DashboardRegistry` itself.
 *
 * The nine methods below are the whole contract. `App\Dashboard\Widget` is an abstract base that
 * fills in sensible defaults for most of them plus a handful of **optional** presentation hooks
 * (`subtitle()`, `sort()`, `deferred()`, `href()`, `skeleton()`, `minHeight()`, `emptyMessage()`);
 * those hooks are read through `method_exists()` by `WidgetDescriptor`, so a class that implements
 * this interface directly — without extending the base — still renders correctly.
 *
 * Rules a widget must respect:
 *
 *  1. `key()` is globally unique and permanent. The registry throws
 *     `DuplicateWidgetKeyException` when two classes claim the same key, so a later phase can
 *     never silently replace another phase's card (F-8.3). One key = one owning phase.
 *  2. `permission()` and `module()` are the *only* visibility controls. The registry filters on
 *     them **before** `data()` is ever called, so a viewer without the permission never triggers
 *     the query.
 *  3. `data()` must be bounded: a fixed number of queries and a fixed number of rows, whatever
 *     the size of the tables. No query inside a loop, no unbounded `get()`.
 *  4. `data()` returns real figures only. A widget with nothing to report returns an empty
 *     shape and lets its view render an empty state; it never invents a number.
 */
interface DashboardWidget
{
    /**
     * Stable, globally unique, permanent identifier — snake_case.
     *
     * It is the JSON endpoint's parameter, the key stored in `users.preferences` for the
     * per-user order and hidden list, and the DOM id of the card. Renaming it resets every
     * user's layout, so it never changes once shipped.
     */
    public function key(): string;

    /** Card heading. */
    public function title(): string;

    /** An `x-ui.icon` name. */
    public function icon(): string;

    /**
     * The permission a viewer must hold, or null when the widget is open to anyone who can
     * reach the dashboard at all.
     */
    public function permission(): ?string;

    /**
     * The module slug this widget belongs to, or null when it is not module-gated. A disabled
     * module hides the card for everyone, Super Admin included.
     */
    public function module(): ?string;

    /** Width in the 12-column grid (1–12; clamped by the descriptor). */
    public function span(): int;

    /**
     * Section slug the card is filed under on the dashboard — see `App\Dashboard\WidgetGroup`.
     * Unknown slugs are accepted and rendered with a humanised label, so a later phase can open
     * a new section without editing anything shared.
     */
    public function group(): string;

    /**
     * The figures, for the selected range.
     *
     * @return array<string, mixed> handed to the view as `$data`, and published as the `data`
     *                              member of the widget's JSON response
     */
    public function data(DateRange $range): array;

    /** Blade view rendering the card body, e.g. `admin.dashboard.widgets.users-by-status`. */
    public function view(): string;
}
