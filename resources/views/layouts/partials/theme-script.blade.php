{{--
    Pre-paint theme + sidebar state.

    This MUST stay inline in <head>, before any stylesheet renders, and must not be deferred:
    it is the only thing standing between a reload and a white flash on a dark screen.

    It mirrors resources/js/theme.js (keys "theme" and "theme-guest") and resources/js/sidebar.js
    (key "sidebar-rail"). Keep the three in sync.

    Theme, in order of precedence:
      1. Signed in — the account's own `users.theme` (Light / Dark / System), on every device. The
         switcher writes the row and mirrors the choice to localStorage "theme" (D12); the row is
         what paints, so a second person signing in on a shared browser never inherits the first
         person's theme.
      2. Signed out (sign-in, password reset) — a choice made with the switcher on one of those
         pages, kept in localStorage "theme-guest".
      3. Otherwise `appearance.default_theme`. It is never written back to storage, so changing
         the setting repaints every browser that has not made a choice of its own on the next load.

    Sidebar rail: a stored "sidebar-rail" choice ("1" / "0") wins; with none,
    `appearance.sidebar_collapsed_by_default`. The resolved state is published on
    `data-sidebar-rail` for the sidebar partial, which hands it to the Alpine store — the store's
    own init only knows about localStorage and would otherwise expand a rail the setting collapsed.
--}}

@php
    $themeValues = array_map(
        static fn (\App\Enums\ThemePreference $case): string => $case->value,
        \App\Enums\ThemePreference::cases(),
    );

    $themeValueOf = static function (mixed $value) use ($themeValues): ?string {
        if ($value instanceof \App\Enums\ThemePreference) {
            return $value->value;
        }

        return is_string($value) && in_array($value, $themeValues, true) ? $value : null;
    };

    $themeDefault = $themeValueOf(setting('appearance.default_theme', \App\Enums\ThemePreference::System->value))
        ?? \App\Enums\ThemePreference::System->value;

    $themeAccount = null;

    if ($themeUser = auth()->user()) {
        $themeAccount = $themeValueOf($themeUser->theme ?? null) ?? $themeDefault;
    }

    $sidebarRailDefault = (bool) setting('appearance.sidebar_collapsed_by_default', false);
@endphp

<script nonce="{{ csp_nonce() }}">
    (function () {
        var root = document.documentElement;
        var themes = @json($themeValues);
        var account = @json($themeAccount);
        var preference = @json($themeDefault);

        // --- theme -----------------------------------------------------------------
        if (account !== null) {
            preference = account;
        } else {
            try {
                var chosen = window.localStorage.getItem('theme-guest');

                if (themes.indexOf(chosen) !== -1) {
                    preference = chosen;
                }
            } catch (e) {
                /* storage unavailable — the default applies */
            }
        }

        var dark = preference === 'dark'
            || (preference === 'system'
                && window.matchMedia
                && window.matchMedia('(prefers-color-scheme: dark)').matches);

        root.classList.toggle('dark', dark);
        root.dataset.theme = preference;
        root.dataset.themeScope = account !== null ? 'account' : 'guest';
        root.style.colorScheme = dark ? 'dark' : 'light';

        // --- sidebar rail ----------------------------------------------------------
        var rail = @json($sidebarRailDefault);

        try {
            var storedRail = window.localStorage.getItem('sidebar-rail');

            if (storedRail === '1') {
                rail = true;
            } else if (storedRail === '0') {
                rail = false;
            }
        } catch (e) {
            /* storage unavailable — the default applies */
        }

        root.classList.toggle('is-rail', rail);
        root.dataset.sidebarRail = rail ? '1' : '0';
    })();
</script>
