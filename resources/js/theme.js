/**
 * Light / Dark / System theme.
 *
 * Two pieces cooperate:
 *   1. an inline <script> in the <head> (resources/views/layouts/partials/theme-script.blade.php)
 *      applies the stored preference BEFORE first paint so there is never a flash of the
 *      wrong theme;
 *   2. this module owns runtime switching, following the OS while the preference is
 *      "system", and mirroring the choice to the server.
 *
 * Both use the same localStorage key — keep THEME_KEY and the inline script in sync.
 */

export const THEME_KEY = 'theme';

export const THEMES = ['light', 'dark', 'system'];

const MEDIA_QUERY = '(prefers-color-scheme: dark)';

/** Preference currently stored on this device. */
export function readTheme() {
    try {
        const stored = window.localStorage.getItem(THEME_KEY);

        return THEMES.includes(stored) ? stored : 'system';
    } catch (e) {
        return 'system';
    }
}

function writeTheme(preference) {
    try {
        window.localStorage.setItem(THEME_KEY, preference);
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
 * Mirror the preference onto the user row. The endpoint is printed into the page by the
 * layout only when route('account.theme.update') exists, so this is a no-op until the
 * account controller ships.
 */
export function syncTheme(preference) {
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

            // Another tab changed the preference.
            window.addEventListener('storage', (event) => {
                if (event.key !== THEME_KEY) {
                    return;
                }

                this.preference = readTheme();
                this.resolved = applyTheme(this.preference);
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

export default { THEME_KEY, THEMES, readTheme, resolveTheme, applyTheme, setTheme, syncTheme, registerTheme };
