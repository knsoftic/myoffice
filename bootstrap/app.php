<?php

use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePanelAccess;
use App\Http\Middleware\EnsurePublicSiteAvailable;
use App\Http\Middleware\EnsureUserIsActive;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

/*
|--------------------------------------------------------------------------
| Panel route files (phase-01 §8)
|--------------------------------------------------------------------------
|
| Loaded through withRouting(then: ...) inside the `web` middleware group, after
| routes/web.php (which itself requires routes/auth.php). Each file declares its own
| prefix, route-name prefix and panel middleware — see the header comment in the file.
|
*/

$panels = ['admin', 'collaborator', 'student', 'teacher', 'client'];

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () use ($panels): void {
            foreach ($panels as $panel) {
                $file = __DIR__.'/../routes/'.$panel.'.php';

                if (realpath($file) !== false) {
                    Route::middleware('web')->group($file);
                }
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
        | phase-01 §7: "Change password: … other sessions invalidated (Auth::logoutOtherDevices)".
        |
        | AuthenticateSession is the middleware that actually enforces that promise. It stamps the
        | user's password hash into the session and compares it on every later request (and
        | compares the hash carried by a "remember me" cookie when the request is authenticated
        | that way), logging out any session or recaller cookie that predates the change.
        | Without it `Auth::logoutOtherDevices()` only rehashes the password and nothing revokes
        | the other sessions — so a stolen session cookie would outlive a password reset, which is
        | exactly what the reset flow claims it cannot do.
        |
        | Appended to the `web` group, so it runs after StartSession (it needs the session) and
        | before the route's own `auth`/`active` middleware. A guest request is a no-op: the
        | middleware returns immediately when there is no authenticated user. It re-stamps the
        | hash after the response, which is what lets the session that *performed* the change
        | survive it.
        */
        $middleware->web(append: [AuthenticateSession::class]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'module' => EnsureModuleEnabled::class,
            'panel' => EnsurePanelAccess::class,
            // phase-02 §6 "Maintenance": applied to PUBLIC routes only, never to a panel — see the
            // class docblock for why the gate is attached rather than path-sniffed.
            'public_site' => EnsurePublicSiteAvailable::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        | A failed validation flashes the request input back into the session, and Laravel's own
        | list only strips top-level `password` keys. The settings form nests every field under
        | `settings[...]`, so a mistyped from-address beside a freshly typed SMTP password wrote
        | that password into sessions.payload in clear text. Every secret the registry declares
        | (encrypted, or a password input) is stripped by its nested name — derived from the
        | registry, never listed by hand.
        */
        $exceptions->dontFlash(SettingsRegistry::secretInputNames());
    })->create();
