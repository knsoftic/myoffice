<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Enums\PanelType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Shared plumbing for the `/account` area (phase-01 §7, §8).
 *
 * The account screens are the only views in the project that serve **every** panel, so they
 * cannot pick a layout statically: a staff user gets `layouts.admin`, everyone else gets
 * `layouts.panel`. Both shells expose the same sections, so the views themselves only need
 * `@extends($layout)`.
 *
 * The tab strip is built here too, from route names checked with `Route::has()`, so a tab appears
 * only once its screen is registered.
 */
abstract class AccountController extends Controller
{
    protected const TAB_PROFILE = 'profile';

    protected const TAB_PASSWORD = 'password';

    protected const TAB_SESSIONS = 'sessions';

    protected const TAB_HISTORY = 'login-history';

    /**
     * key => [label, route name, icon] for the tab strip, in display order.
     *
     * @var array<string, array{label: string, route: string, icon: string}>
     */
    private const TABS = [
        self::TAB_PROFILE => ['label' => 'Profile', 'route' => 'account.profile', 'icon' => 'user'],
        self::TAB_PASSWORD => ['label' => 'Password', 'route' => 'account.password', 'icon' => 'key'],
        self::TAB_SESSIONS => ['label' => 'Sessions', 'route' => 'account.sessions', 'icon' => 'desktop'],
        self::TAB_HISTORY => ['label' => 'Login history', 'route' => 'account.login-history', 'icon' => 'history'],
    ];

    /**
     * Render an account screen with the shell and tabs already resolved.
     *
     * @param  array<string, mixed>  $data
     */
    protected function render(string $view, string $tab, array $data = []): View
    {
        return view($view, array_merge([
            'layout' => $this->layout(),
            'accountTabs' => $this->tabs($tab),
        ], $data));
    }

    /**
     * Shell the current user belongs in.
     */
    protected function layout(): string
    {
        try {
            $user = request()->user();

            if ($user instanceof User && $user->primaryPanel() !== PanelType::Admin) {
                return 'layouts.panel';
            }
        } catch (Throwable) {
            // No roles resolvable: the staff shell is the safe default.
        }

        return 'layouts.admin';
    }

    /**
     * @return list<array{label: string, url: string, icon: string, active: bool}>
     */
    protected function tabs(string $current): array
    {
        $tabs = [];

        foreach (self::TABS as $key => $tab) {
            if (! Route::has($tab['route'])) {
                continue;
            }

            $tabs[] = [
                'label' => $tab['label'],
                'url' => route($tab['route']),
                'icon' => $tab['icon'],
                'active' => $key === $current,
            ];
        }

        return $tabs;
    }

    /**
     * The authenticated user, typed. The `auth` middleware guarantees there is one.
     */
    protected function currentUser(): User
    {
        $user = request()->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
