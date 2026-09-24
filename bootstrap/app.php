<?php

use App\Http\Middleware\CachePublicResponse;
use App\Http\Middleware\CaptureReferral;
use App\Http\Middleware\EnsureAdmissionFormOpen;
use App\Http\Middleware\EnforceSessionLifetime;
use App\Http\Middleware\EnsureClientContext;
use App\Http\Middleware\EnsureInvoicePublicLinkEnabled;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePanelAccess;
use App\Http\Middleware\EnsurePublicSiteAvailable;
use App\Http\Middleware\EnsureSiteModuleEnabled;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\NoStoreForAuthenticated;
use App\Http\Middleware\ResolvePreviewMode;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Cms\PublicOrigin;
use App\Support\ConfigureFromSettings;
use App\Services\Collaborator\Exceptions\DirectLedgerWriteException;
use App\Services\Collaborator\Exceptions\ImmutableLedgerAttributeException;
use App\Services\Finance\Exceptions\DirectPaymentWriteException;
use App\Services\Finance\Exceptions\ImmutablePaymentAttributeException;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Log;
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
        | phase-24-25 6.3 - the three global middleware.
        |
        | Global rather than per route group, and that is the whole argument for them: a header set
        | on a group is a header some later phase registers a route outside. Twenty-five phases of
        | routes is more than anybody can audit by reading, so the default is "every response" and
        | the exceptions are the ones that have to justify themselves.
        |
        | Order matters. ForceHttps is first because a request that should not have been made over
        | plain HTTP should be refused before anything reads its body. SecurityHeaders and
        | NoStoreForAuthenticated both decorate the response on the way back out, so they sit
        | outside everything that produces one - including the error handler, which means a 500
        | page carries the same headers a 200 does.
        */
        $middleware->prepend([
            ForceHttps::class,
            SecurityHeaders::class,
            NoStoreForAuthenticated::class,
        ]);

        /*
        | phase-24-25 6.3 - the absolute session ceiling.
        |
        | Appended to `web`, immediately after AuthenticateSession, and **never** to `auth`.
        |
        | `auth` in this application is a middleware ALIAS, not a group: it resolves to
        | Illuminate\Auth\Middleware\Authenticate. `appendToGroup('auth', ...)` does not add to
        | that alias - it CREATES a group of that name, and a group takes precedence over an alias
        | of the same name when a route's middleware is resolved. Every route declaring
        | `->middleware(['auth', ...])` would then run this class instead of Authenticate, and
        | nothing would check whether anybody was signed in at all. It fails silently and
        | it fails open: the screens still render, for everyone.
        |
        | Appending to `web` costs a guest nothing - the middleware returns on its first line when
        | there is no authenticated user - and it runs after StartSession, which it needs.
        */
        $middleware->web(append: [EnforceSessionLifetime::class]);

        /*
        | Review round 2 (phase-03 §6.5, §6.7): the `Host` header is client-chosen, and absolute URLs built
        | from it would otherwise reach pages and feeds that are cached for every visitor. Outside `local`
        | and the test runner, only the application URL's host (and its subdomains) and the host of
        | `seo.canonical_base_url` are accepted; anything else is a 400 before routing. The public site
        | never builds a cached absolute URL from the request either (PublicOrigin), so this is the second
        | layer, not the only one.
        */
        $middleware->trustHosts(at: static fn (): array => PublicOrigin::trustedHostPatterns());

        /*
        | phase-24-25 5.3 / 6.3. `security.trusted_proxies`, resolved lazily so the database is
        | only asked once a request is being handled.
        |
        | **Empty is the default and it is the safe value.** With no trusted proxy Laravel ignores
        | `X-Forwarded-For` entirely, so a client cannot invent a client address - which would hand
        | it a fresh rate-limit counter on every request and write a chosen IP into
        | `login_histories`. Behind a load balancer the header must be believed, and then and only
        | then this is set (DEP-11 asserts both directions).
        */
        $middleware->trustProxies(
            at: ConfigureFromSettings::trustedProxies(),
        );

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            // phase-24-25 6.3. Available by name for a route that needs the ceiling without the
            // rest of the `auth` stack; the stack itself carries it already.
            'session.lifetime' => EnforceSessionLifetime::class,
            'module' => EnsureModuleEnabled::class,
            'panel' => EnsurePanelAccess::class,
            // phase-05 §6.9 / D31: every /client route. 403 with an explanatory page when the portal is off,
            // the login resolves to no client, or the client's status forbids it - re-evaluated every request,
            // so revoking access takes effect immediately.
            'client.context' => EnsureClientContext::class,
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
            // phase-08-09 §6.4 / §7.6. Attached to the PUBLIC stacks in routes/web.php and
            // routes/site-pages.php and never to a panel group: a signed-in member of staff following a
            // partner's link is not a referral, and recording one would file a visit under their own
            // user id for the resolver to argue with later. It is a no-op without the query parameter.
            // phase-13 §7.8: the public invoice link is a setting, and switching it off must actually
            // close the door. 404, never 403 — the same answer a rotated token and a draft get.
            'invoice_link' => EnsureInvoicePublicLinkEnabled::class,
            // phase-14-17 §7.10: the public admission form's own gate. Closed answers 200 with a
            // noindex rather than 404 — the page exists, admissions are simply shut today — and a
            // staff user holding `admissions.create` passes through to preview it.
            'admission.open' => EnsureAdmissionFormOpen::class,
            'capture_referral' => CaptureReferral::class,
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

        /*
        | phase-24-25 6.3 - the programmer errors that must never look like user errors.
        |
        | Each of these is thrown by a model hook that exists to stop a specific mistake: writing a
        | ledger row outside the service that owns it, editing an amount that has already been paid
        | against. Reaching one means code somewhere took a shortcut the spine forbids - so it is a
        | 500 and a loud log line, never a validation message and never a 403. A 403 would read as
        | "you lack a permission", and no permission exists that would have allowed it.
        |
        | They are reported at ERROR with the route attached, because the route is the one thing the
        | stack trace does not make obvious and the first thing whoever reads the log needs.
        */
        $exceptions->report(static function (Throwable $exception): bool {
            $programmerErrors = [
                DirectLedgerWriteException::class,
                ImmutableLedgerAttributeException::class,
                DirectPaymentWriteException::class,
                ImmutablePaymentAttributeException::class,
            ];

            foreach ($programmerErrors as $class) {
                if ($exception instanceof $class) {
                    Log::error('Immutability guard tripped: '.$class, [
                        'route' => Route::currentRouteName() ?? request()->path(),
                        'message' => $exception->getMessage(),
                    ]);

                    // Keep the framework's own reporting as well: this line is a summary, not a
                    // replacement for the trace.
                    return true;
                }
            }

            /*
            | phase-24-25 6.4. A lazy-load violation is an N+1 that got past review. Model::preventLazyLoading
            | throws it in local and testing (where it should stop a pull request) and only reports it in
            | production (where it must never 500 a paying client) - so here it is logged with the route and
            | the relation, which is exactly what `perf:budget` needs to find the screen that caused it.
            */
            if ($exception instanceof LazyLoadingViolationException) {
                Log::warning('Lazy loading violation', [
                    'route' => Route::currentRouteName() ?? request()->path(),
                    'model' => $exception->model,
                    'relation' => $exception->relation,
                ]);

                return false;
            }

            return true;
        });
    })->create();
