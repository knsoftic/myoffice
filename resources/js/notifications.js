/**
 * The topbar bell: unread badge, a dropdown of the newest rows, mark-as-read, and live pop-ups.
 *
 * Everything it shows comes from the bell's existing routes (`<panel>.notifications.*`): the JSON
 * `bell` endpoint for the list and the count, `read` / `read-all` for marking, `go` to follow a link
 * (which marks the row read on the way). Nothing here decides who is told what — that happened on the
 * server when the row was written.
 *
 * Live, when the page carries realtime settings: a WebSocket on the user's private channel delivers
 * each new row the moment the queue worker stores it (see resources/js/realtime.js). Without them, or
 * while the socket is down, the bell polls instead — every `pollSeconds`, only while the tab is
 * visible — so it is never worse than it was before realtime existed.
 */

const LEVEL_TO_TOAST = { info: 'info', success: 'success', warning: 'warning', critical: 'error' };

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function request(url, method = 'GET') {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(method === 'GET' ? {} : { 'X-CSRF-TOKEN': csrfToken() }),
        },
    });

    if (!response.ok) {
        const error = new Error(`HTTP ${response.status}`);
        error.status = response.status;
        throw error;
    }

    return response.json();
}

function uiBell(config = {}) {
    return {
        open: false,
        loaded: false,
        loading: false,
        failed: false,
        unread: 0,
        rows: [],
        hasMore: false,
        live: false,
        stopped: false,
        pageSize: Number(config.pageSize || 10),

        init() {
            this.refresh();

            if (config.realtime) {
                import('./realtime')
                    .then(({ subscribe }) => subscribe(
                        config.realtime,
                        config.userId,
                        (payload) => this.received(payload),
                        (live) => { this.live = live; },
                    ))
                    .catch(() => { this.live = false; });
            }

            const seconds = Math.max(15, Number(config.pollSeconds || 60));

            this.timer = setInterval(() => {
                if (!this.live && !this.stopped && document.visibilityState === 'visible') {
                    this.refresh();
                }
            }, seconds * 1000);

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible' && !this.live && !this.stopped) {
                    this.refresh();
                }
            });
        },

        destroy() {
            clearInterval(this.timer);
        },

        get badge() {
            return this.unread > 99 ? '99+' : String(this.unread);
        },

        get label() {
            return this.unread === 0 ? 'Notifications' : `Notifications, ${this.unread} unread`;
        },

        async refresh() {
            if (this.loading) {
                return;
            }

            this.loading = true;

            try {
                const data = await request(config.urls.bell);

                this.unread = Number(data.unread || 0);
                this.rows = Array.isArray(data.rows) ? data.rows : [];
                this.hasMore = Boolean(data.has_more);
                this.failed = false;
                this.loaded = true;
            } catch (error) {
                this.failed = true;

                // Signed out, or the session expired: stop asking until the next page load.
                if ([401, 403, 419].includes(error.status)) {
                    this.stopped = true;
                }
            } finally {
                this.loading = false;
            }
        },

        toggle() {
            this.open = !this.open;

            if (this.open && !this.live) {
                this.refresh();
            }
        },

        close() {
            this.open = false;
        },

        /** A row pushed over the socket. */
        received(payload) {
            const row = payload?.notification;

            if (!row || !row.id) {
                return;
            }

            if (!this.rows.some((existing) => existing.id === row.id)) {
                this.rows.unshift(row);

                if (this.rows.length > this.pageSize) {
                    this.rows.length = this.pageSize;
                    this.hasMore = true;
                }
            }

            this.unread = Number(payload.unread ?? this.unread + 1);
            this.loaded = true;

            window.toasts?.push({
                type: LEVEL_TO_TOAST[row.level] || 'info',
                title: row.title || 'New notification',
                message: row.body || '',
                timeout: 7000,
            });
        },

        async markRead(row) {
            if (row.read) {
                return;
            }

            row.read = true;
            this.unread = Math.max(0, this.unread - 1);

            try {
                const data = await request(config.urls.read.replace('__ID__', row.id), 'POST');
                this.unread = Number(data.unread ?? this.unread);
            } catch {
                row.read = false;
                this.unread += 1;
            }
        },

        async markAllRead() {
            const before = this.rows.map((row) => row.read);

            this.rows.forEach((row) => { row.read = true; });
            const previous = this.unread;
            this.unread = 0;

            try {
                await request(config.urls.readAll, 'POST');
            } catch {
                this.rows.forEach((row, index) => { row.read = before[index]; });
                this.unread = previous;
            }
        },

        goUrl(row) {
            return config.urls.go.replace('__ID__', row.id);
        },

        /** "3 min ago" — the stamp is the database's UTC wall clock. */
        ago(at) {
            const stamp = Date.parse(String(at).replace(' ', 'T') + (String(at).includes('Z') ? '' : 'Z'));

            if (Number.isNaN(stamp)) {
                return '';
            }

            const seconds = Math.round((stamp - Date.now()) / 1000);
            const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
            const steps = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];

            for (const [unit, size] of steps) {
                if (Math.abs(seconds) >= size) {
                    return format.format(Math.round(seconds / size), unit);
                }
            }

            return 'just now';
        },

        dot(level) {
            return {
                critical: 'bg-rose-500',
                warning: 'bg-amber-500',
                success: 'bg-emerald-500',
            }[level] || 'bg-brand-500';
        },
    };
}

export function registerNotificationBell(Alpine) {
    Alpine.data('uiBell', uiBell);
}
