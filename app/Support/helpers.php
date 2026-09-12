<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Global helpers
|--------------------------------------------------------------------------
|
| Autoloaded through composer's "autoload.files". Thin wrappers only: every line of logic
| lives in the App\Support classes these functions delegate to.
|
*/

use App\Models\User;
use App\Support\Money;
use App\Support\SettingsRepository;
use App\Support\Sidebar;

if (! function_exists('settings_repo')) {
    /**
     * The settings singleton.
     */
    function settings_repo(): SettingsRepository
    {
        return app(SettingsRepository::class);
    }
}

if (! function_exists('setting')) {
    /**
     * Read a setting by its dotted "group.key" name.
     *
     *   setting('company.name', 'My Office');
     */
    function setting(string $key, mixed $default = null): mixed
    {
        return settings_repo()->get($key, $default);
    }
}

if (! function_exists('money')) {
    /**
     * Format a money string for display using the currency settings.
     *
     *   money('1250.5')        // 'Rs 1,250.50'
     *   money('1250.5', false) // '1,250.50'
     *
     * A null amount (an empty decimal column) formats as zero so views never blow up.
     */
    function money(?string $amount, bool $withSymbol = true): string
    {
        return Money::format($amount ?? '0', $withSymbol);
    }
}

if (! function_exists('sidebar_items')) {
    /**
     * The navigation tree for a user (defaults to the authenticated user), already filtered
     * by module, route and permission.
     *
     * @return array<int, array<string, mixed>>
     */
    function sidebar_items(?User $user = null): array
    {
        if ($user === null) {
            $authenticated = auth()->user();
            $user = $authenticated instanceof User ? $authenticated : null;
        }

        return Sidebar::forUser($user);
    }
}
