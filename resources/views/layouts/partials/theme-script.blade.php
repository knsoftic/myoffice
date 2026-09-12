{{--
    Pre-paint theme + sidebar state.

    This MUST stay inline in <head>, before any stylesheet renders, and must not be deferred:
    it is the only thing standing between a reload and a white flash on a dark screen.

    It mirrors resources/js/theme.js (key: "theme") and resources/js/sidebar.js
    (key: "sidebar-rail"). Keep the three in sync.

    When localStorage is empty we fall back to the preference stored on the user row, so a
    new device honours the account setting on the very first paint.
--}}

@php
    $themeFromUser = 'system';

    if ($themeUser = auth()->user()) {
        $stored = $themeUser->theme ?? null;

        $themeFromUser = $stored instanceof \App\Enums\ThemePreference
            ? $stored->value
            : (is_string($stored) && $stored !== '' ? $stored : 'system');
    }
@endphp

<script>
    (function () {
        var root = document.documentElement;

        // --- theme -----------------------------------------------------------------
        var preference = 'system';

        try {
            var stored = window.localStorage.getItem('theme');

            if (stored === 'light' || stored === 'dark' || stored === 'system') {
                preference = stored;
            } else {
                preference = @json($themeFromUser);
                window.localStorage.setItem('theme', preference);
            }
        } catch (e) {
            preference = @json($themeFromUser);
        }

        var dark = preference === 'dark'
            || (preference === 'system'
                && window.matchMedia
                && window.matchMedia('(prefers-color-scheme: dark)').matches);

        root.classList.toggle('dark', dark);
        root.dataset.theme = preference;
        root.style.colorScheme = dark ? 'dark' : 'light';

        // --- sidebar rail ----------------------------------------------------------
        try {
            if (window.localStorage.getItem('sidebar-rail') === '1') {
                root.classList.add('is-rail');
            }
        } catch (e) {
            /* storage unavailable — start expanded */
        }
    })();
</script>
