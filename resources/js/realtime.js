/**
 * The live bell's socket — loaded on demand, only on a page whose bell carries realtime settings.
 *
 * Kept out of app.js on purpose: Echo and pusher-js are ~70 KB, and the public site, the sign-in
 * screen and every installation that runs without a broadcaster never download them. The bell
 * imports this module with `import()`, so Vite ships it as its own chunk.
 *
 * One connection per page however many components ask: a second Echo would open a second socket to
 * the same channel and every notification would pop up twice.
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echo = null;

function connect(config) {
    if (echo !== null) {
        return echo;
    }

    window.Pusher = Pusher;

    const options = {
        broadcaster: config.broadcaster,
        key: config.key,
        authEndpoint: config.authEndpoint,
        forceTLS: Boolean(config.forceTLS),
        enabledTransports: ['ws', 'wss'],
    };

    if (config.broadcaster === 'pusher') {
        options.cluster = config.cluster;
    } else {
        options.wsHost = config.wsHost;
        options.wsPort = config.wsPort;
        options.wssPort = config.wsPort;
    }

    echo = new Echo(options);

    return echo;
}

/**
 * Subscribe to one user's private channel.
 *
 * `onNotification(payload)` gets `{ notification, unread }`; `onState(live)` is told whenever the
 * socket connects or drops, so the bell can poll while it is down and stop once it is back.
 */
export function subscribe(config, userId, onNotification, onState) {
    const client = connect(config);

    client.private(`App.Models.User.${userId}`).listen('.notification.created', onNotification);

    const connection = client.connector?.pusher?.connection;

    if (connection) {
        connection.bind('state_change', ({ current }) => onState(current === 'connected'));
        onState(connection.state === 'connected');
    }

    return client;
}
