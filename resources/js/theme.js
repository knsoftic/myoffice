/**
 * Light / Dark / System theme.
 *
 * Two pieces cooperate:
 *   1. an inline <script> in the <head> (resources/views/layouts/partials/theme-script.blade.php)
 *      resolves the preference and paints it BEFORE first paint, so there is never a flash of the
 *      wrong theme. It publishes what it resolved on <html>: `data-theme` (the preference) and
 *      `data-theme-scope` ("account" when signed in, "guest" otherwise);
 *   2. this module owns runtime switching, following the OS while the preference is "system",
 *      and mirroring the choice to the server.
 *
 * Precedence (decided server-side, in the inline script):
 *   signed in  -> the account's own theme (users.theme);
 *   signed out -> a choice made on a sign-in / reset page (GUEST_THEME_KEY), else the
 *                 `appearance.default_theme` setting.
 *
 * Storage keys, kept in sync with the inline script:
 *   THEME_KEY        the signed-in account's choice, mirrored from the switcher (D12);
 *   GUEST_THEME_KEY  a choice made while signed out.
 * Neither is ever written with a fallback value — only an explicit switcher click stores anything —
 * so a browser that never chose keeps following the administrator's default as it changes.
 */

export const THEME_KEY = 'theme';

export const GUEST_THEME_KEY = 'theme-guest';

export const THEMES = ['light', 'dark', 'system'];

const MEDIA_QUERY = '(prefers-color-scheme: dark)';

/** "account" when the page belongs to a signed-in user, "guest" otherwise. */
export function themeScope() {
    return document.documentElement.dataset.themeScope === 'account' ? 'account' : 'guest';
}

/** The storage key the current scope reads and writes. */
function storageKey() {
    return themeScope() === 'account' ? THEME_KEY : GUEST_THEME_KEY;
}

/**
 * The preference in force on this page: what the inline head script resolved (the account's
 * theme, a stored guest choice, or the administrator's default).
 */
export function readTheme() {
    const painted = document.documentElement.dataset.theme;

    if (THEMES.includes(painted)) {
        return painted;
    }

    try {
        const stored = window.localStorage.getItem(storageKey());

        return THEMES.includes(stored) ? stored : 'system';
    } catch (e) {
        return 'system';
    }
}

function writeTheme(preference) {
    try {
        window.localStorage.setItem(storageKey(), preference);
    } catch (e) {
        /* private mode / storage disabled — the in-memory state still works */
    }
}

/** The concrete theme a preference resolves to right now. */
export function resolveTheme(preference = readTheme()) {
    if (preference === 'light' || preference === 'dark') {
        return preference;
    }

    return window.matchMedia && window.matchMedia(MEDIA_QUERY).matches ? 'dark' : 'light';
}

/** Paint a resolved theme onto the document. */
export function applyTheme(preference = readTheme()) {
    const resolved = resolveTheme(preference);
    const root = document.documentElement;

    root.classList.toggle('dark', resolved === 'dark');
    root.dataset.theme = preference;
    root.style.colorScheme = resolved;

    const meta = document.querySelector('meta[name="theme-color"]');

    if (meta) {
        meta.setAttribute('content', resolved === 'dark' ? '#020617' : '#f8fafc');
    }

    return resolved;
}

/**
 * Mirror the preference onto the user row. The endpoint is printed into the page only for a
 * signed-in user, so on the sign-in screen this is a no-op and the choice stays on the device.
 *
 * `keepalive` lets the request finish when the click is immediately followed by a navigation;
 * without it the row could keep the old theme and the next page would paint it.
 */
export function syncTheme(preference) {
    if (themeScope() !== 'account') {
        return Promise.resolve(false);
    }

    const endpoint = document
        .querySelector('meta[name="theme-endpoint"]')
        ?.getAttribute('content');

    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    if (!endpoint || !token) {
        return Promise.resolve(false);
    }

    return fetch(endpoint, {
        method: 'PUT',
        credentials: 'same-origin',
        keepalive: true,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ theme: preference }),
    })
        .then((response) => response.ok)
        .catch(() => false);
}

/** Change the theme: store it, paint it, tell the server, announce it. */
export function setTheme(preference) {
    const next = THEMES.includes(preference) ? preference : 'system';

    writeTheme(next);
    const resolved = applyTheme(next);
    syncTheme(next);

    window.dispatchEvent(
        new CustomEvent('theme-changed', { detail: { preference: next, resolved } }),
    );

    return resolved;
}

/**
 * Register the Alpine store the theme switcher binds to and start following the OS.
 */
export function registerTheme(Alpine) {
    Alpine.store('theme', {
        preference: readTheme(),
        resolved: resolveTheme(),

        init() {
            this.resolved = applyTheme(this.preference);

            if (window.matchMedia) {
                const query = window.matchMedia(MEDIA_QUERY);
                const follow = () => {
                    if (this.preference === 'system') {
                        this.resolved = applyTheme('system');
                    }
                };

                // Safari < 14 only has addListener.
                if (query.addEventListener) {
                    query.addEventListener('change', follow);
                } else if (query.addListener) {
                    query.addListener(follow);
                }
            }

            // Another tab in the same scope changed the preference.
            window.addEventListener('storage', (event) => {
                if (event.key !== storageKey() || !THEMES.includes(event.newValue)) {
                    return;
                }

                this.preference = event.newValue;
                this.resolved = applyTheme(this.preference);

                window.dispatchEvent(
                    new CustomEvent('theme-changed', {
                        detail: { preference: this.preference, resolved: this.resolved },
                    }),
                );
            });
        },

        set(preference) {
            this.preference = THEMES.includes(preference) ? preference : 'system';
            this.resolved = setTheme(this.preference);
        },

        is(preference) {
            return this.preference === preference;
        },

        get isDark() {
            return this.resolved === 'dark';
        },
    });
}

export default {
    THEME_KEY,
    GUEST_THEME_KEY,
    THEMES,
    themeScope,
    readTheme,
    resolveTheme,
    applyTheme,
    setTheme,
    syncTheme,
    registerTheme,
};
