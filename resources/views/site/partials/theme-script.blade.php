{{--
    Public-site pre-paint theme (phase-03 §8.14: Light / Dark / System exactly as in the panels).

    MUST stay inline in <head>, before the stylesheet: it is the only thing between a reload and a
    white flash on a dark screen. It mirrors resources/js/theme.js (localStorage key "theme").

    Initial preference, first match wins:
      1. the visitor's own choice on this device (localStorage);
      2. a signed-in user's account preference — safe to print, because an authenticated response is
         never stored in the public page cache;
      3. `appearance.default_theme`, the site-wide default.

    A concrete light/dark default is written back to localStorage because theme.js initialises its
    store from there; "system" is not written, so a later change of the site default still reaches a
    visitor who never chose.
--}}

@php
    // Never rescued (INV-10): a key that is not public must fail the render, not quietly print its default.
    $siteSetting = static fn (string $key, mixed $default = null): mixed => site_setting($key, $default);

    $themeDefault = $siteSetting('appearance.default_theme', 'system');
    $themeDefault = $themeDefault instanceof \BackedEnum ? $themeDefault->value : (string) $themeDefault;

    $themeUser = auth()->user()?->theme ?? null;
    $themeUser = $themeUser instanceof \BackedEnum ? $themeUser->value : (is_string($themeUser) ? $themeUser : null);

    $themeInitial = in_array($themeUser, ['light', 'dark'], true)
        ? $themeUser
        : (in_array($themeDefault, ['light', 'dark', 'system'], true) ? $themeDefault : 'system');
@endphp

<script>
    (function () {
        var root = document.documentElement;
        var valid = ['light', 'dark', 'system'];
        var preference = null;

        try {
            preference = window.localStorage.getItem('theme');
        } catch (e) {
            preference = null;
        }

        if (valid.indexOf(preference) === -1) {
            preference = @json($themeInitial);

            if (preference !== 'system') {
                try {
                    window.localStorage.setItem('theme', preference);
                } catch (e) {
                    /* storage unavailable: the in-memory preference still paints */
                }
            }
        }

        var dark = preference === 'dark'
            || (preference === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);

        root.classList.toggle('dark', dark);
        root.dataset.theme = preference;
        root.style.colorScheme = dark ? 'dark' : 'light';
    })();
</script>
