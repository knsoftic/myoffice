<?php

use App\Http\Middleware\CachePublicResponse;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePanelAccess;
use App\Http\Middleware\EnsurePublicSiteAvailable;
use App\Http\Middleware\EnsureSiteModuleEnabled;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResolvePreviewMode;
use App\Support\Cms\PublicOrigin;
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
| routes/site-pages.php (the phase-03 public `/{slug}` catch-all) is loaded last of all, so no
| panel, auth or later-phase public route can ever be shadowed by a CMS page.
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

            // phase-03 §7.6: the /{slug} catch-all is registered after every other route file.
            $pages = __DIR__.'/../routes/site-pages.php';

            if (realpath($pages) !== false) {
                Route::middleware('web')->group($pages);
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

        /*
        | Review round 2 (phase-03 §6.5, §6.7): the `Host` header is client-chosen, and absolute URLs built
        | from it would otherwise reach pages and feeds that are cached for every visitor. Outside `local`
        | and the test runner, only the application URL's host (and its subdomains) and the host of
        | `seo.canonical_base_url` are accepted; anything else is a 400 before routing. The public site
        | never builds a cached absolute URL from the request either (PublicOrigin), so this is the second
        | layer, not the only one.
        */
        $middleware->trustHosts(at: static fn (): array => PublicOrigin::trustedHostPatterns());

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'module' => EnsureModuleEnabled::class,
            'panel' => EnsurePanelAccess::class,
            // phase-02 §6 "Maintenance": applied to PUBLIC routes only, never to a panel — see the
            // class docblock for why the gate is attached rather than path-sniffed.
            'public_site' => EnsurePublicSiteAvailable::class,
            // phase-03 §6.10 names the same gate `site`, and phases 14-23 use that name on their public
            // routes. One class, two aliases: MaintenanceModeTest recognises the gate by class, not alias.
            'site' => EnsurePublicSiteAvailable::class,
            // phase-03 INV-15 / D26: a content module gates ITS OWN public routes with a plain 404.
            'site_module' => EnsureSiteModuleEnabled::class,
            // phase-03 §6.7 full-page cache and §6.12 preview flag (CachePublicResponse skips a preview).
            'site.cache' => CachePublicResponse::class,
            'site.preview' => ResolvePreviewMode::class,
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
