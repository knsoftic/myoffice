<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Live delivery of the bell — whether it is on, and what the browser needs to connect.
 *
 * **Realtime is an addition to the bell, never a dependency of it.** Every notification is still
 * written by the queue worker to `notifications` exactly as before; when a row lands, it is also
 * pushed over a WebSocket to its one recipient (`PushNotificationToBrowser`). With broadcasting off —
 * `BROADCAST_CONNECTION` left at `log` or `null`, or no app key — nothing is pushed, the browser opens
 * no socket, and the bell falls back to polling `notifications.bell`. A Reverb server that is down
 * costs a pop-up, not a notification.
 *
 * **One private channel per user, and no role channels.** Who is told what is already decided per
 * person by `NotificationService` (the registry's audience, the permission, the preferences); a
 * channel per role would broadcast past all three. The channel name is Laravel's own default for a
 * notifiable user, authorised in `routes/channels.php`.
 */
final class Realtime
{
    /** The broadcasters a browser can subscribe to. `log` and `null` are "off". */
    private const LIVE = ['reverb', 'pusher'];

    public static function enabled(): bool
    {
        $default = (string) config('broadcasting.default', 'null');

        return in_array($default, self::LIVE, true)
            && filled(config('broadcasting.connections.'.$default.'.key'));
    }

    /** `App.Models.User.{id}` — Laravel's default channel for a notifiable user. */
    public static function userChannel(int $userId): string
    {
        return 'App.Models.User.'.$userId;
    }

    /**
     * What the browser connects with, or null when realtime is off.
     *
     * Only public values: the app KEY identifies the app to the socket server, the way a Pusher key
     * does, and is meant to be in the page. The secret never leaves the server.
     *
     * @return array<string, mixed>|null
     */
    public static function clientConfig(): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        $default = (string) config('broadcasting.default');
        $connection = (array) config('broadcasting.connections.'.$default);

        if ($default === 'pusher') {
            return [
                'broadcaster' => 'pusher',
                'key' => (string) $connection['key'],
                'cluster' => (string) data_get($connection, 'options.cluster', 'mt1'),
                'forceTLS' => true,
            ];
        }

        $client = (array) ($connection['client'] ?? []);
        $scheme = (string) ($client['scheme'] ?? data_get($connection, 'options.scheme', 'https'));
        $host = (string) ($client['host'] ?? data_get($connection, 'options.host', ''));

        return [
            'broadcaster' => 'reverb',
            'key' => (string) $connection['key'],
            'wsHost' => $host !== '' ? $host : (string) parse_url((string) config('app.url'), PHP_URL_HOST),
            'wsPort' => (int) ($client['port'] ?? data_get($connection, 'options.port', 443)),
            'forceTLS' => $scheme === 'https',
        ];
    }

    /**
     * The socket origin for the CSP's `connect-src`.
     *
     * `'self'` does not reliably cover a WebSocket — older Safari does not match `wss:` against it —
     * and locally Reverb listens on its own port, which `'self'` never covers. Named explicitly.
     *
     * @return list<string>
     */
    public static function cspSources(): array
    {
        $client = self::clientConfig();

        if ($client === null) {
            return [];
        }

        if ($client['broadcaster'] === 'pusher') {
            return ['wss://ws-'.$client['cluster'].'.pusher.com'];
        }

        $host = (string) $client['wsHost'];

        if ($host === '' || preg_match('/[\s;,\'"]/', $host) === 1) {
            return [];
        }

        return [($client['forceTLS'] ? 'wss://' : 'ws://').$host.':'.(int) $client['wsPort']];
    }
}
