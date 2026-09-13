<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Dashboard\Layout;
use App\Dashboard\WidgetDescriptor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\UpdateDashboardLayoutRequest;
use App\Models\User;
use App\Support\DashboardRegistry;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Throwable;

/**
 * The admin dashboard (phase-02 §4) — three actions over `DashboardRegistry`.
 *
 *   GET  /admin                        index   the grid
 *   GET  /admin/dashboard/widget/{key} widget  one card's JSON (throttled, re-authorised)
 *   PUT  /admin/dashboard/layout       layout  save this user's order and hidden list
 *
 * **It knows nothing about any particular widget.** There is no `if ($key === 'users_by_status')`
 * anywhere in this file and there never may be: a later phase adds a card by dropping a class into
 * `app/Dashboard/Widgets/`, and this controller picks it up because the registry does. The only
 * decisions made here are which range to use, which cards to render eagerly, and how to answer.
 *
 * ---------------------------------------------------------------------------------------------
 * Why most cards render server-side
 * ---------------------------------------------------------------------------------------------
 *
 * Rendering every card from the browser would mean ten requests per page view, ten permission
 * re-checks, and a dashboard that says nothing with JavaScript off. So the cheap cards are
 * rendered inline on the initial request — their figures are in the HTML, the page works without
 * JavaScript, and the range selector is an ordinary link — while the two genuinely expensive ones
 * (`deferred()`: a filesystem walk and an `information_schema` aggregate) are skipped and fetched
 * afterwards behind an `x-ui.skeleton`. `?eager=1` renders those inline too, which is what the
 * `<noscript>` fallback links to.
 *
 * Measured on the seeded database: **9 widget queries for the initial render** (10 once
 * `login_histories` has rows, the extra being one eager load), **13–14 with `?eager=1`**, and at
 * most 4 for a single widget's JSON. Constant in every case — no figure here grows with the number
 * of rows, and nothing queries inside a loop.
 */
final class DashboardController extends Controller
{
    /** Widget JSON fetches allowed per user per minute, on top of any route throttle. */
    private const WIDGET_RATE_LIMIT = 120;

    /**
     * Supports both `Route::get('/', DashboardController::class)` and
     * `[DashboardController::class, 'index']` — the routes file is owned elsewhere.
     */
    public function __invoke(Request $request): View
    {
        return $this->index($request);
    }

    /*
    |--------------------------------------------------------------------------
    | GET /admin
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): View
    {
        $user = $this->user($request);
        $range = $this->range($request, $user);

        $layout = Layout::fromUser($user);
        $available = DashboardRegistry::for($user);

        // Arrange once; the two halves are a partition of the same ordered collection.
        $arranged = $layout->arrange($available);
        $visible = $arranged->reject(fn ($descriptor): bool => $layout->isHidden($descriptor->key));
        $hidden = $arranged->filter(fn ($descriptor): bool => $layout->isHidden($descriptor->key));

        // `?eager=1` renders the deferred cards inline as well: the <noscript> escape hatch, and
        // the switch an acceptance test flips to assert on every figure in one response.
        $eager = $request->boolean('eager');

        $sections = $this->sections($arranged, $layout, $range, $eager);

        return view('admin.dashboard', [
            'range' => $range,
            'rangePresets' => DateRange::presets(),
            'layout' => $layout,
            'sections' => $sections,
            'visible' => $visible,
            'hidden' => $hidden,
            'available' => $arranged,
            // The arrangement a "reset to default" goes back to: group, then widget sort, then title.
            'defaultOrder' => Layout::empty()->arrange($available)->keys()->values()->all(),
            'eager' => $eager,
            'widgetUrlTemplate' => $this->widgetUrlTemplate(),
            'layoutUrl' => $this->namedUrl('admin.dashboard.layout'),
            'dashboardUrl' => $this->namedUrl('admin.dashboard') ?? url()->current(),
            'timezone' => $range->timezone(),
            'today' => CarbonImmutable::now($range->timezone()),
            'canCustomise' => $user !== null && $available->isNotEmpty(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | GET /admin/dashboard/widget/{key}
    |--------------------------------------------------------------------------
    */

    /**
     * One card's JSON: its data, and the same Blade body the server-rendered cards use.
     *
     * Three things are deliberate here.
     *
     *  1. **The key is never trusted.** `DashboardRegistry::findFor()` re-runs the module and
     *     permission check for this viewer, so a user who edits the URL to a card their role
     *     excludes gets a 404 — not a 403, because a 403 would confirm the card exists (§8 row 5
     *     of the resolutions: 404 on an ownership failure, everywhere).
     *  2. **The body is rendered server-side** and shipped as `html`, so the markup for a widget
     *     lives in exactly one place — its Blade view — instead of being duplicated in
     *     JavaScript. `data` travels alongside it for anything that wants the raw figures.
     *  3. **It is rate-limited even without route middleware.** The contract puts `throttle` on
     *     the route and `routes/admin.php` carries it, so the counter below normally does nothing
     *     and deliberately costs nothing — it only engages when the route it is reached through
     *     has no throttle at all. Running both would double the cache round-trips on every card,
     *     which on the database cache driver means real queries.
     */
    public function widget(Request $request, string $key): JsonResponse
    {
        $user = $this->user($request);

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->routeIsThrottled($request)) {
            $limiterKey = 'dashboard-widget:'.$user->getAuthIdentifier();

            if (RateLimiter::tooManyAttempts($limiterKey, self::WIDGET_RATE_LIMIT)) {
                return response()->json([
                    'message' => 'Too many dashboard refreshes. Try again shortly.',
                    'retry_after' => RateLimiter::availableIn($limiterKey),
                ], 429);
            }

            RateLimiter::hit($limiterKey);
        }

        $descriptor = DashboardRegistry::findFor($user, $key);

        if ($descriptor === null) {
            return response()->json(['message' => 'Widget not found.'], 404);
        }

        $range = $this->range($request, $user);

        try {
            $data = $descriptor->data($range);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'key' => $descriptor->key,
                'message' => 'This widget could not be loaded.',
            ], 500);
        }

        return response()->json([
            'key' => $descriptor->key,
            'widget' => $descriptor->toArray(),
            'range' => $range->toArray(),
            'data' => $data,
            'html' => $this->renderBody($descriptor, $data, $range),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PUT /admin/dashboard/layout
    |--------------------------------------------------------------------------
    */

    /**
     * Save the signed-in user's arrangement.
     *
     * Written to `$request->user()` and nowhere else, through `User::setPreferences()` — one save,
     * one UPDATE, both dot paths at once — so two users can never share a layout and no request
     * can nominate whose layout it is editing. The Form Request has already dropped any key this
     * viewer may not see.
     *
     * Answers JSON to the customise panel and a redirect + toast to a plain form post, so the
     * feature degrades to a normal submit when JavaScript is off.
     */
    public function layout(UpdateDashboardLayoutRequest $request): JsonResponse|RedirectResponse
    {
        $user = $this->user($request);

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $layout = $request->layout();

        $user->setPreferences($layout->toPreferences());

        if ($request->expectsJson()) {
            return response()->json([
                'saved' => true,
                'layout' => $layout->toArray(),
                'message' => 'Dashboard layout saved.',
            ]);
        }

        session()->flash('toast', ['type' => 'success', 'message' => 'Dashboard layout saved.']);

        return redirect()->to($this->namedUrl('admin.dashboard') ?? url()->previous());
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Group the viewer's cards into sections, running `data()` only for the ones rendered inline.
     *
     * The user's own order wins inside a section; the sections themselves follow
     * `WidgetGroup::sort()`. Three kinds of card come out of here:
     *
     *  · **inline** — `data()` ran, the figures are in the HTML;
     *  · **deferred** — expensive, or hidden by this user: no query ran, the card ships its
     *    skeleton and the browser fetches the body if and when it is needed. Switching a hidden
     *    card back on therefore costs one request and no page reload, and a hidden card costs the
     *    server nothing at all;
     *  · **failed** — `data()` threw. Reported and rendered as a retryable card, because one
     *    broken later-phase widget must never take the whole dashboard down.
     *
     * @param  Collection<string, WidgetDescriptor>  $arranged  every card this viewer may see
     * @return list<array{group: string, label: string, sort: int, widgets: list<array<string, mixed>>}>
     */
    private function sections(Collection $arranged, Layout $layout, DateRange $range, bool $eager): array
    {
        $sections = [];

        foreach ($arranged as $descriptor) {
            $isHidden = $layout->isHidden($descriptor->key);
            $defer = $isHidden || ($descriptor->deferred && ! $eager);

            $card = [
                'descriptor' => $descriptor,
                'deferred' => $defer,
                'hidden' => $isHidden,
                'data' => null,
                'failed' => false,
            ];

            if (! $defer) {
                try {
                    $card['data'] = $descriptor->data($range);
                } catch (Throwable $exception) {
                    report($exception);
                    $card['failed'] = true;
                }
            }

            $sections[$descriptor->group] ??= [
                'group' => $descriptor->group,
                'label' => $descriptor->groupLabel(),
                'sort' => $descriptor->groupSort,
                'widgets' => [],
            ];

            $sections[$descriptor->group]['widgets'][] = $card;
        }

        usort($sections, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_values($sections);
    }

    /**
     * Render one card's body view. Used by both the inline grid and the JSON endpoint, so the
     * markup for a widget exists exactly once.
     *
     * @param  array<string, mixed>  $data
     */
    private function renderBody(WidgetDescriptor $descriptor, array $data, DateRange $range): string
    {
        return view('admin.dashboard.partials.body', [
            'widget' => $descriptor,
            'data' => $data,
            'range' => $range,
        ])->render();
    }

    /**
     * The range every widget on the page is handed.
     *
     * Read from the query string (`?range=month`, or `?range=custom&from=…&to=…`) in the viewer's
     * own timezone, so two people in different timezones looking at "today" each see their own
     * day. `DateRange::make()` falls back to the default preset on anything unreadable, so a
     * hand-edited URL cannot 500 the dashboard.
     */
    private function range(Request $request, ?User $user): DateRange
    {
        $timezone = $user?->effectiveTimezone();

        // A hostile `?range[]=x` is an array: read only strings, so it falls back to the default
        // preset instead of raising "Array to string conversion".
        $scalar = static fn (mixed $value): ?string => is_string($value) ? $value : null;

        return DateRange::make(
            $scalar($request->query('range')),
            $scalar($request->query('from')),
            $scalar($request->query('to')),
            $timezone,
        );
    }

    /**
     * Does the route this request arrived on already carry `throttle` middleware?
     *
     * When it does — which is the contracted arrangement — the controller's own limiter stands
     * down rather than paying for a second counter.
     */
    private function routeIsThrottled(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'throttle')) {
                return true;
            }
        }

        return false;
    }

    private function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The widget JSON URL with a `__key__` placeholder for the browser to substitute.
     *
     * Null until the routes agent registers `admin.dashboard.widget`, which is what lets the grid
     * fall back to rendering everything inline rather than breaking.
     */
    private function widgetUrlTemplate(): ?string
    {
        $url = $this->namedUrl('admin.dashboard.widget', ['key' => '__key__']);

        return $url === null ? null : str_replace('%5F%5Fkey%5F%5F', '__key__', $url);
    }

    /**
     * A route URL, but only when that route exists.
     */
    private function namedUrl(string $name, mixed $parameters = []): ?string
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
}
