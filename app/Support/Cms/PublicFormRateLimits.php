<?php

declare(strict_types=1);

namespace App\Support\Cms;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The two named limiters of the public forms (phase-04 §6.9), applied as `throttle:public-contact` and
 * `throttle:public-apply` route middleware.
 *
 *   | limiter          | limits (all must pass)                                                           |
 *   |------------------|----------------------------------------------------------------------------------|
 *   | `public-contact` | 5 / minute per IP · `website.contact_rate_per_hour` (default 20) / hour per IP ·  |
 *   |                  | 3 / hour per submitted email                                                     |
 *   | `public-apply`   | 3 / hour per IP · 2 / day per submitted email                                    |
 *
 * A hit answers 429 **inside the site layout** ("Too many submissions, please try again in X minutes")
 * through the `site.errors.429` view when it exists, then Laravel's `errors.429`, then plain text — never
 * a raw framework page. Each limit has its own key, so the per-minute and per-hour counters never share
 * a bucket. The submitted email is hashed into the key; it is never stored by the limiter in clear.
 *
 * Registered once at boot: `PublicFormRateLimits::register()` from the application's service provider.
 */
final class PublicFormRateLimits
{
    public const CONTACT = 'public-contact';

    public const APPLY = 'public-apply';

    public static function register(): void
    {
        RateLimiter::for(self::CONTACT, static function (Request $request): array {
            $ip = (string) $request->ip();
            $limits = [
                Limit::perMinute(5)->by('contact:ip:minute:'.$ip),
                Limit::perHour(self::contactPerHour())->by('contact:ip:hour:'.$ip),
            ];

            $email = self::email($request);

            if ($email !== null) {
                $limits[] = Limit::perHour(3)->by('contact:email:hour:'.$email);
            }

            return array_map(static fn (Limit $limit): Limit => $limit->response(self::responder()), $limits);
        });

        RateLimiter::for(self::APPLY, static function (Request $request): array {
            $limits = [Limit::perHour(3)->by('apply:ip:hour:'.(string) $request->ip())];

            $email = self::email($request);

            if ($email !== null) {
                $limits[] = Limit::perDay(2)->by('apply:email:day:'.$email);
            }

            return array_map(static fn (Limit $limit): Limit => $limit->response(self::responder()), $limits);
        });
    }

    /**
     * The 429 answer, rendered in the public layout when the view exists.
     *
     * @return \Closure(Request, array<string, mixed>): Response
     */
    public static function responder(): \Closure
    {
        return static function (Request $request, array $headers): Response {
            $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
            $minutes = (int) ceil($seconds / 60);
            $message = sprintf(
                'Too many submissions, please try again in %d %s.',
                $minutes,
                $minutes === 1 ? 'minute' : 'minutes',
            );

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], Response::HTTP_TOO_MANY_REQUESTS, $headers);
            }

            foreach (['site.errors.429', 'errors.429'] as $view) {
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

            return response($message, Response::HTTP_TOO_MANY_REQUESTS, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
        };
    }

    private static function contactPerHour(): int
    {
        try {
            $value = setting('website.contact_rate_per_hour', 20);
        } catch (Throwable) {
            $value = 20;
        }

        return is_numeric($value) ? max(1, min(200, (int) $value)) : 20;
    }

    /**
     * sha1 of the lower-cased submitted address, or null when none was posted.
     */
    private static function email(Request $request): ?string
    {
        $email = $request->input('email');

        if (! is_string($email)) {
            return null;
        }

        $email = Str::lower(trim($email));

        return $email === '' ? null : sha1($email);
    }
}
