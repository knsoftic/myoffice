{{--
    Runtime brand palette (phase-02 §5).

    The whole shell is painted from eleven CSS variables — `--brand-50` … `--brand-950` — which
    `tailwind.config.js` resolves as `rgb(var(--brand-NNN) / <alpha-value>)`. This partial is the
    **injection point only**: it prints the palette that `App\Support\ConfigureFromSettings` has
    already derived from the `branding.brand_color` setting, so changing the brand colour in the
    Settings UI repaints every button, badge, active nav item, focus ring and gradient on the next
    request with **no rebuild**.

    One ramp, one set of names
    --------------------------
    The derivation deliberately does NOT live here. `ConfigureFromSettings` (phase-02 §3) owns it
    and publishes it three ways — `brandPalette($hex)`, `brandPaletteCss($hex)` and the prepared
    `config('branding.palette')` payload it sets during boot — precisely so the layout does not
    grow a second ramp of its own. This file reads the prepared payload, and falls back to the
    same class's pure `brandPalette()` when the payload is not in the config yet (a console
    process, or a request that rendered before the provider ran). If the ramp itself needs to
    change, it changes in that one class and this file needs no edit.

    Install safety
    --------------
    Everything is inside one try/catch, and every value is re-validated as three 0-255 channels
    before it is printed: a missing `settings` table, an unavailable cache store, a half-migrated
    database or a malformed colour all end the same way — nothing is emitted and the complete
    indigo fallback on bare `:root` in `resources/css/app.css` stands. A page can never lose its
    colour because of this file, and nothing that reaches a `<style>` block here is unvalidated.

    Why `html:root`
    ---------------
    `app.css` defines the fallback on bare `:root` (specificity 0,1,0). `html:root` is 0,1,1, so
    this block wins wherever it is loaded from and is not hostage to whether the stylesheet
    happens to come before or after it. Inline styles still beat it, which is exactly what the
    branding screen's live preview needs (`documentElement.style.setProperty('--brand-600', …)`).

    Included from `layouts/partials/head`, so the admin shell, the portal shell and the guest
    (sign-in) shell all get it from one place.
--}}

@php
    $brandScale = [];

    try {
        $brandPalette = config(\App\Support\ConfigureFromSettings::PALETTE_KEY);

        // Not in the config yet (console, or a request rendered before the provider booted):
        // derive through the same class rather than inventing a second ramp here.
        if (! is_array($brandPalette) || $brandPalette === []) {
            $brandColor = setting('branding.brand_color');

            $brandPalette = \App\Support\ConfigureFromSettings::brandPalette(
                is_string($brandColor) ? $brandColor : null,
            );
        }

        foreach ($brandPalette as $brandStep => $brandChannels) {
            // "79 70 229" and nothing else ever reaches the stylesheet.
            if (preg_match('/^(?:\d{1,3}) (?:\d{1,3}) (?:\d{1,3})$/', (string) $brandChannels) !== 1) {
                continue;
            }

            if (preg_match('/^(?:50|\d{3})$/', (string) $brandStep) !== 1) {
                continue;
            }

            $brandScale[(string) $brandStep] = (string) $brandChannels;
        }
    } catch (\Throwable) {
        // No settings table, no cache store, no database — the app.css fallback stands.
        $brandScale = [];
    }
@endphp

@if ($brandScale !== [])
    <style id="brand-theme">html:root{@foreach ($brandScale as $brandStep => $brandChannels)--brand-{{ $brandStep }}:{{ $brandChannels }};@endforeach}</style>
@endif
