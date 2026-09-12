<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\RecordFailedLogin;
use App\Listeners\RecordLogout;
use App\Listeners\RecordSuccessfulLogin;
use App\Models\User;
use App\Services\Auth\LoginHistoryRecorder;
use App\Services\Auth\LoginRedirector;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as FoundationEventServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Event wiring for the application, plus the one guest-redirect rule the auth area owns
 * (phase-01 §7).
 *
 * Laravel 12 has no `app/Providers/EventServiceProvider.php` and no `$listen` array, so the
 * listeners are declared here — one map, registered through `bootstrap/providers.php`.
 *
 * **Framework auto-discovery is switched off on purpose.** `Application::configure()` calls
 * `withEvents()`, which makes the framework scan `app/Listeners` and register every public
 * `handle*()` / `__invoke()` method it finds. Combined with the explicit map below that wires each
 * listener **twice**, and a listener that writes an audit row would then write two. Discovery also
 * boots after this provider, so there is no way to detect and skip it from here.
 *
 * The consequence is a rule worth knowing: **every listener in `app/Listeners` must be listed in
 * the map below**, or it will never fire.
 */
final class EventListenerServiceProvider extends ServiceProvider
{
    /**
     * Event class => listener classes, in the order they run.
     *
     * @var array<class-string, list<class-string>>
     */
    private const LISTENERS = [
        Login::class => [RecordSuccessfulLogin::class],
        Failed::class => [RecordFailedLogin::class],
        Logout::class => [RecordLogout::class],
    ];

    public function register(): void
    {
        /*
         * Must happen in register(): the framework's event provider reads this flag in its own
         * boot(), which runs after every provider registered in bootstrap/providers.php.
         */
        FoundationEventServiceProvider::disableEventDiscovery();

        /*
         * One recorder per request. It remembers the history row written for this sign-in so
         * AuthenticatedSessionController can re-stamp the regenerated session id onto it — with a
         * fresh instance per resolution that link would be lost.
         */
        $this->app->singleton(LoginHistoryRecorder::class);
    }

    public function boot(): void
    {
        foreach (self::LISTENERS as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }

        $this->registerGuestRedirect();
    }

    /**
     * Where an already-signed-in visitor goes when they open /login (the `guest` middleware).
     *
     * Without this the framework sends them to the public holding page, whose only button links
     * back to /login — a small loop anyone who presses Back after signing in falls into. Same
     * destination as after a sign-in: `primaryPanel()->homeRoute()` via LoginRedirector.
     *
     * It lives in this provider because the auth area owns no other one (AppServiceProvider is
     * outside this part of the codebase) and because the rule it sets belongs with the sign-in
     * flow it mirrors.
     */
    private function registerGuestRedirect(): void
    {
        RedirectIfAuthenticated::redirectUsing(function (Request $request): string {
            $user = $request->user();

            return $user instanceof User
                ? $this->app->make(LoginRedirector::class)->intendedUrl($user)
                : '/';
        });
    }
}
