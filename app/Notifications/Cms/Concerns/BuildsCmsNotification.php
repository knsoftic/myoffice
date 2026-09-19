<?php

declare(strict_types=1);

namespace App\Notifications\Cms\Concerns;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What the four phase-04 notifications share (§10.2).
 *
 *   · Channels: `database` + `mail` for a user; `mail` only for an address routed with
 *     `Notification::route('mail', …)` (the `website.*_notify_emails` lists). The `database` channel is
 *     used only once the `notifications` table exists — Phase 22 owns that table and the notification
 *     centre, and until it ships a notification must still mail rather than fail the listener.
 *   · Deep links go through the named admin route when it is registered, and fall back to the literal
 *     path otherwise, so a notification never throws `RouteNotFoundException`.
 */
trait BuildsCmsNotification
{
    private static ?bool $databaseChannelAvailable = null;

    /**
     * @param  list<string>  $channels  what this notification wants for a user
     * @return list<string>
     */
    protected function channelsFor(object $notifiable, array $channels): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return in_array('mail', $channels, true) ? ['mail'] : [];
        }

        return array_values(array_filter(
            $channels,
            static fn (string $channel): bool => $channel !== 'database' || self::databaseChannelAvailable(),
        ));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function link(string $routeName, array $parameters, string $fallbackPath): string
    {
        try {
            if (Route::has($routeName)) {
                return route($routeName, $parameters);
            }
        } catch (Throwable) {
            // fall through to the literal path
        }

        return url($fallbackPath);
    }

    protected function excerpt(?string $text, int $length = 200): string
    {
        $text = trim((string) preg_replace('~\s+~u', ' ', strip_tags((string) $text)));

        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length)).'…' : $text;
    }

    private static function databaseChannelAvailable(): bool
    {
        if (self::$databaseChannelAvailable === null) {
            try {
                self::$databaseChannelAvailable = Schema::hasTable('notifications');
            } catch (Throwable) {
                self::$databaseChannelAvailable = false;
            }
        }

        return self::$databaseChannelAvailable;
    }
}
