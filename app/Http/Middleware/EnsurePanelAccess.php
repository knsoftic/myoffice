<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\PanelType;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteFacade;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Alias: `panel` (phase-01 §6, §8).
 *
 *   ->middleware('panel:admin')            // deny: 403 (the default, phase-01 §10 "panel isolation")
 *   ->middleware('panel:admin,redirect')   // bounce the user to their own panel home with a toast
 *
 * A panel is reachable when one of the user's roles is attached to it
 * (`User::canAccessPanel()`, which reads `roles.panel` — no role names are hardcoded here).
 *
 * Behaviour chosen for Phase 1: **403 by default**. The contract's acceptance test requires a
 * Student hitting /admin, /collaborator, /teacher and /client to get 403, so the deny branch is
 * the default for every panel route. The friendlier "send them home with a toast" branch is
 * implemented and opt-in per route group via the second parameter (`panel:admin,redirect`); it
 * only fires when the user really does own another panel whose home route is registered, and
 * never for JSON requests. A user with no panel at all always gets 403.
 */
final class EnsurePanelAccess
{
    /** Cross-panel access is refused with 403. */
    public const MODE_DENY = 'deny';

    /** Cross-panel access bounces the user to their own panel home with a toast. */
    public const MODE_REDIRECT = 'redirect';

    public function handle(Request $request, Closure $next, string $panel, string $mode = self::MODE_DENY): Response
    {
        $target = PanelType::tryFrom(strtolower(trim($panel)));

        if ($target === null) {
            throw new InvalidArgumentException(
                sprintf('Unknown panel [%s] passed to the "panel" middleware.', $panel)
            );
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $this->unauthenticated($request);
        }

        if ($user->canAccessPanel($target)) {
            return $next($request);
        }

        $message = sprintf('You do not have access to the %s panel.', $target->label());
        $home = $this->homeRouteFor($user);

        if (strtolower(trim($mode)) === self::MODE_REDIRECT
            && $home !== null
            && ! $request->expectsJson()
        ) {
            return redirect()
                ->route($home)
                ->with('toast', ['type' => 'error', 'message' => $message]);
        }

        throw new HttpException(Response::HTTP_FORBIDDEN, $message);
    }

    /**
     * Defensive only — the `auth` middleware runs before this one on every panel route.
     */
    private function unauthenticated(Request $request): Response
    {
        if ($request->expectsJson() || ! RouteFacade::has('login')) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        }

        return redirect()->guest(route('login'));
    }

    /**
     * Home route of the user's own panel, or null when they have no panel (or its routes are
     * not registered yet).
     */
    private function homeRouteFor(User $user): ?string
    {
        try {
            if ($user->panels()->isEmpty()) {
                return null;
            }

            $route = $user->primaryPanel()->homeRoute();

            return RouteFacade::has($route) ? $route : null;
        } catch (Throwable) {
            return null;
        }
    }
}
