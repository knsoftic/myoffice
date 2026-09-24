<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Cms\PublicFormRateLimits;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Every named rate limiter in the application (phase-24-25 §6.3.1).
 *
 * **One file, because a limit is a policy and a policy split across seventeen route files is a
 * policy nobody can read.** Each limiter is named, each name is applied as `throttle:<name>` route
 * middleware, and the route-guard manifest records which routes carry which.
 *
 * **`login` and `login_max_attempts` are not the same control, and this is where they are
 * reconciled** (§5.3, asserted by SEC-26). `security.login_throttle_per_minute` is a *rate*: it
 * slows a guessing run down so an attacker gets five tries a minute rather than five hundred.
 * `security.login_max_attempts` with `lockout_minutes` is a *lockout*: after that many failures the
 * account stops answering at all, and Breeze's `LoginRequest` owns it. Both key on email + IP, so
 * they count the same events, and neither replaces the other — a lockout with no rate limit lets an
 * attacker burn the allowance instantly, and a rate limit with no lockout lets them keep going for
 * ever at five a minute.
 *
 * **Every limiter keys on something the client cannot choose.** A user id where there is one, the
 * request IP where there is not. An IP is imperfect — an office behind one NAT shares a counter —
 * which is why the per-IP limits are the generous ones and the per-user limits are the tight ones.
 * A forwarded-IP header is believed only when `security.trusted_proxies` names the proxy that sent
 * it; otherwise a client could give itself a fresh counter per request by inventing an address.
 *
 * **Every 429 is styled and says how long to wait.** The site layout for public routes, the panel
 * layout for authenticated ones. None of them echoes the key: telling somebody their limit is keyed
 * on their email address confirms the address exists.
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * Limiters that key on the authenticated user, with their settings key and fallback.
     *
     * @var array<string, array{setting: ?string, perMinute: int, floor: int, ceiling: int}>
     */
    private const USER_LIMITS = [
        // Every authenticated write. High enough that no real person reaches it; low enough that a
        // script does.
        'global-writes' => ['setting' => 'security.global_write_throttle_per_minute', 'perMinute' => 120, 'floor' => 30, 'ceiling' => 6000],
        // An export reads far more rows than a screen, so it is limited apart from ordinary writes.
        'export' => ['setting' => 'security.export_throttle_per_minute', 'perMinute' => 10, 'floor' => 1, 'ceiling' => 120],
        'print' => ['setting' => 'security.print_throttle_per_minute', 'perMinute' => 20, 'floor' => 1, 'ceiling' => 120],
        // Confirming a password is a guessing surface of its own: the account is already signed in,
        // so the sign-in lockout does not apply to it.
        'password-confirm' => ['setting' => null, 'perMinute' => 5, 'floor' => 5, 'ceiling' => 5],
        // An SMTP probe is a way to test credentials against somebody else's mail server.
        'mail-test' => ['setting' => null, 'perMinute' => 3, 'floor' => 3, 'ceiling' => 3],
        'integrity-run' => ['setting' => null, 'perMinute' => 3, 'floor' => 3, 'ceiling' => 3],
    ];

    public function boot(): void
    {
        $this->registerAuthLimiters();
        $this->registerUserLimiters();
        $this->registerOperationsLimiters();
        $this->registerPublicLimiters();
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    private function registerAuthLimiters(): void
    {
        // See the class note: a rate, keyed exactly as Breeze's lockout is, so the two count the
        // same events rather than two different views of them.
        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute($this->loginPerMinute())
                ->by($this->emailAndIp($request))
                ->response($this->responder());
        });

        // Two windows: a burst limit and an hourly one. A reset link is an email somebody else
        // receives, so an unlimited endpoint is a way to flood a stranger's inbox.
        RateLimiter::for('password-reset', function (Request $request): array {
            $key = $this->emailAndIp($request);

            return [
                Limit::perMinute(3)->by('pwreset:min:'.$key)->response($this->responder()),
                Limit::perHour(10)->by('pwreset:hour:'.$key)->response($this->responder()),
            ];
        });

        // Laravel's default is 6/minute. Tightened, and keyed on the user rather than the IP,
        // because the request is authenticated and the id is the honest key.
        RateLimiter::for('verification', function (Request $request): Limit {
            return Limit::perMinute(3)
                ->by($this->userKey($request))
                ->response($this->responder());
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Authenticated work
    |--------------------------------------------------------------------------
    */

    private function registerUserLimiters(): void
    {
        foreach (self::USER_LIMITS as $name => $definition) {
            RateLimiter::for($name, function (Request $request) use ($name, $definition): Limit {
                $perMinute = $definition['setting'] === null
                    ? $definition['perMinute']
                    : $this->setting($definition['setting'], $definition['perMinute'], $definition['floor'], $definition['ceiling']);

                return Limit::perMinute($perMinute)
                    ->by($name.':'.$this->userKey($request))
                    ->response($this->responder());
            });
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Operations — the expensive and the irreversible
    |--------------------------------------------------------------------------
    */

    private function registerOperationsLimiters(): void
    {
        // A manual backup reads the whole database. Two an hour is generous for a human and
        // useless as a way to exhaust a disk.
        RateLimiter::for('backup-run', function (Request $request): Limit {
            return Limit::perHour(2)->by('backup-run:'.$this->userKey($request))->response($this->responder());
        });

        // One an hour, and the restore flow demands a typed phrase and a reason on top. This limit
        // is not about load: it is about the second attempt somebody makes in a panic.
        RateLimiter::for('backup-restore', function (Request $request): Limit {
            return Limit::perHour(1)->by('backup-restore:'.$this->userKey($request))->response($this->responder());
        });

        // A payout request is a claim on money. Three an hour leaves no room for a double-click to
        // become a double payment and no room for a script to file hundreds.
        RateLimiter::for('payout-request', function (Request $request): Limit {
            return Limit::perHour(3)
                ->by('payout-request:'.$this->collaboratorKey($request))
                ->response($this->responder());
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Public and unauthenticated
    |--------------------------------------------------------------------------
    */

    private function registerPublicLimiters(): void
    {
        // phase-04 §6.9 already owns `public-contact` and `public-apply` with exactly §6.3.1's
        // limits. Registered from their own class so there is one definition, not two that drift.
        PublicFormRateLimits::register();

        // Certificate verification is deliberately public — an employer checking a certificate has
        // no account — so the only key available is the IP, and 30/minute is far above what a
        // person does and far below what an enumeration run needs.
        RateLimiter::for('public-verify', function (Request $request): Limit {
            return Limit::perMinute(30)->by('verify:'.$this->ip($request))->response($this->responder());
        });

        // /health answers an unauthenticated monitor. The token gates it; this stops the endpoint
        // being a free way to make the server do work.
        RateLimiter::for('health', function (Request $request): Limit {
            return Limit::perMinute(30)->by('health:'.$this->ip($request))->response($this->responder());
        });

        // A CSP report endpoint is unauthenticated by definition — the browser posts to it. A
        // misconfigured policy on a busy page can produce thousands of reports a minute.
        RateLimiter::for('csp-report', function (Request $request): Limit {
            return Limit::perMinute(60)->by('csp:'.$this->ip($request))->response($this->responder());
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Keys
    |--------------------------------------------------------------------------
    */

    /**
     * The authenticated user's id, or the IP when there is no user.
     *
     * A limiter applied to a route that turns out to be reachable by a guest must still count
     * something; falling through to the IP is what stops one unauthenticated caller sharing a
     * bucket with every other.
     */
    private function userKey(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? 'ip:'.$this->ip($request) : 'user:'.$id;
    }

    /**
     * The collaborator behind the request, falling back to the user and then the IP.
     */
    private function collaboratorKey(Request $request): string
    {
        $user = $request->user();

        if ($user !== null && method_exists($user, 'collaborator')) {
            try {
                $collaborator = $user->collaborator;

                if ($collaborator !== null) {
                    return 'collaborator:'.$collaborator->getKey();
                }
            } catch (Throwable) {
                // A relation that is not loadable here is not a reason to skip the limit.
            }
        }

        return $this->userKey($request);
    }

    /**
     * Email + IP, the key Breeze's lockout uses, so the rate and the lockout count the same events.
     *
     * The address is hashed: a limiter key reaches the cache store and the logs, and an email
     * address is personal data that has no business in either.
     */
    private function emailAndIp(Request $request): string
    {
        $email = $request->input('email');
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';

        return ($email === '' ? 'anon' : sha1($email)).'|'.$this->ip($request);
    }

    /**
     * The request IP.
     *
     * Laravel already decides whether to believe a forwarded header, from the trusted-proxy
     * configuration — so a spoofed `X-Forwarded-For` cannot hand its sender a fresh counter
     * (DEP-11). `unknown` rather than an empty key when there is no address at all: an empty
     * string would put every such request in one bucket with every other.
     */
    private function ip(Request $request): string
    {
        $ip = $request->ip();

        return is_string($ip) && $ip !== '' ? $ip : 'unknown';
    }

    /*
    |--------------------------------------------------------------------------
    | Settings and the response
    |--------------------------------------------------------------------------
    */

    /**
     * A per-minute limit read from settings, clamped.
     *
     * The clamp is the guard rather than the form rule: a value written before the rule existed, or
     * by a raw SQL edit, must not be able to switch a limiter off by setting it to zero.
     */
    private function setting(string $key, int $fallback, int $floor, int $ceiling): int
    {
        try {
            $value = setting($key, $fallback);
        } catch (Throwable) {
            // Settings read from the database. A limiter must still work during a migration.
            return $fallback;
        }

        return is_numeric($value) ? max($floor, min($ceiling, (int) $value)) : $fallback;
    }

    private function loginPerMinute(): int
    {
        return $this->setting('security.login_throttle_per_minute', 5, 1, 30);
    }

    /**
     * The styled 429.
     *
     * Panel layout for a signed-in request, site layout for a public one, JSON for an API caller,
     * plain text if even the error views are missing — a limiter must never be the reason a
     * response cannot be produced. The key is never named.
     *
     * @return Closure(Request, array<string, mixed>): Response
     */
    private function responder(): Closure
    {
        return function (Request $request, array $headers): Response {
            $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
            $minutes = (int) ceil($seconds / 60);

            $message = sprintf(
                'Too many requests. Please try again in %d %s.',
                $minutes,
                $minutes === 1 ? 'minute' : 'minutes',
            );

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], Response::HTTP_TOO_MANY_REQUESTS, $headers);
            }

            $views = $request->user() !== null
                ? ['errors.429', 'site.errors.429']
                : ['site.errors.429', 'errors.429'];

            foreach ($views as $view) {
                try {
                    if (View::exists($view)) {
                        return response()->view($view, [
                            'message' => $message,
                            'retryAfterSeconds' => $seconds,
                            'retryAfterMinutes' => $minutes,
                        ], Response::HTTP_TOO_MANY_REQUESTS, $headers);
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            return response(
                $message,
                Response::HTTP_TOO_MANY_REQUESTS,
                $headers + ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        };
    }
}
