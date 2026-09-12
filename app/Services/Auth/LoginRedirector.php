<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Where an authenticated user belongs (phase-01 §7: "after login, redirect to
 * primaryPanel()->homeRoute()").
 *
 * Every candidate is checked with `Route::has()` before it is used, so a panel whose route file
 * has not been written yet degrades to the next best target instead of throwing — and nothing in
 * the auth area hard-codes a panel.
 */
final class LoginRedirector
{
    /**
     * Fallbacks tried when the user's own panel has no registered home route yet.
     *
     * @var list<string>
     */
    private const FALLBACK_ROUTES = [
        'dashboard',
    ];

    /**
     * Change-password screen, best match first (the panel-prefixed name wins when it exists).
     *
     * @var list<string>
     */
    private const PASSWORD_ROUTES = [
        'account.password',
        'account.password.edit',
    ];

    /**
     * Where to send the user right now: the forced change-password screen when one is pending,
     * their panel home otherwise.
     */
    public function intendedUrl(User $user): string
    {
        if ($user->mustChangePassword()) {
            return $this->passwordChangeUrl($user) ?? $this->homeUrl($user);
        }

        return $this->homeUrl($user);
    }

    /**
     * The user's panel dashboard — `primaryPanel()->homeRoute()` when that route exists.
     */
    public function homeUrl(User $user): string
    {
        $candidates = [];

        try {
            $candidates[] = $user->primaryPanel()->homeRoute();
        } catch (Throwable) {
            // No roles yet / permission tables unavailable: fall through to the generic routes.
        }

        foreach (self::FALLBACK_ROUTES as $fallback) {
            $candidates[] = $fallback;
        }

        return $this->firstUrl($candidates) ?? '/';
    }

    /**
     * URL of the change-password screen, or null while none is registered.
     */
    public function passwordChangeUrl(?User $user = null): ?string
    {
        $candidates = [];

        if ($user instanceof User) {
            try {
                $prefix = $user->primaryPanel()->routePrefix();
                $candidates[] = $prefix.'.account.password';
                $candidates[] = $prefix.'.account.password.edit';
            } catch (Throwable) {
                // Fall through to the panel-less names below.
            }
        }

        foreach (self::PASSWORD_ROUTES as $name) {
            $candidates[] = $name;
        }

        return $this->firstUrl($candidates);
    }

    /**
     * First route name in the list that actually exists, resolved to a relative URL.
     *
     * @param  list<string>  $names
     */
    private function firstUrl(array $names): ?string
    {
        foreach (array_unique($names) as $name) {
            if (! Route::has($name)) {
                continue;
            }

            try {
                return route($name, absolute: false);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
