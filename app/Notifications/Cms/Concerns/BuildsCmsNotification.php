<?php

declare(strict_types=1);

namespace App\Notifications\Cms\Concerns;

use App\Notifications\Channels\RichDatabaseChannel;
use App\Support\NotificationRegistry;
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

        $kept = array_values(array_filter(
            $channels,
            static fn (string $channel): bool => $channel !== 'database' || self::databaseChannelAvailable(),
        ));

        // Phase 22's `notifications` table is NOT NULL on `event_key`, `module` and `level`, which
        // the stock database channel does not write — so every notification in this system goes
        // through the richer one. Callers still say 'database'; only the delivery changes.
        return array_map(
            static fn (string $channel): string => $channel === 'database' ? RichDatabaseChannel::class : $channel,
            $kept,
        );
    }

    /**
     * The bell columns for a notification written before the registry existed.
     *
     * **These classes predate Phase 22 and keep their own `toArray()`** (§10.3: "a phase that
     * already ships a notification class keeps it"). Each one already returns a `kind`, a `module`
     * and a `url`, which are exactly `event_key`, `module` and `url` under different names — so the
     * mapping lives here once rather than being pasted into fourteen classes.
     *
     * `level` is looked up in the registry when the key is registered and falls back to `info`,
     * because a row that claimed a level the registry disagreed with would colour the bell one way
     * and the preference screen another.
     *
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    public function databaseColumns($notifiable): array
    {
        $data = method_exists($this, 'toArray') ? (array) $this->toArray($notifiable) : [];

        // `kind` is the convention; `type` is what one older class called it. Both are read here
        // rather than renamed in the class, because `data` is already written that way in every row
        // those classes have produced, and a rename would orphan them.
        $key = trim((string) ($data['kind'] ?? $data['event_key'] ?? $data['type'] ?? ''));
        $url = $data['url'] ?? null;

        return [
            'event_key' => mb_substr($key === '' ? 'system.notice' : $key, 0, 64),
            'module' => isset($data['module']) ? mb_substr((string) $data['module'], 0, 64) : null,
            'level' => NotificationRegistry::event($key)?->level->value ?? 'info',
            'url' => is_string($url) && $url !== '' ? mb_substr($url, 0, 500) : null,
        ];
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
