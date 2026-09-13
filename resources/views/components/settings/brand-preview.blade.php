@props([
    'preview' => [],
])

{{--
    x-settings.brand-preview — the branding group's live preview (phase-02 §5: "changing
    `brand_color` repaints the shell via CSS variables, logo upload previews in a mock
    sidebar/topbar").

    How the repaint works
    ---------------------
    `window.settingsBrand.brand(hex)` derives the eleven brand stops from the one colour and writes
    them onto `:root` as the `--brand-50 … --brand-950` space-separated RGB triples the shell's
    token contract expects (`rgb(var(--brand-500) / <alpha-value>)` in tailwind.config.js). So the
    real sidebar, buttons, focus rings and badges around the form repaint as the picker moves — the
    preview is the product, not a picture of it.

    The mock below additionally paints itself from `rgb(var(--brand-600, 79 70 229))` directly, so
    it demonstrates the new colour even if a build has not yet switched the Tailwind scale over to
    the variables. The Phase 1 indigo is the fallback in every one of those declarations.

    Nothing here is persisted: the variables are inline styles on the document element, so a reload
    (or "Discard") shows the saved colour again. Saving is what makes it permanent.

    The ratios mirror Tailwind's own indigo scale relative to indigo-600 (the Phase 1 brand), which
    is why the default `#4f46e5` reproduces the shipped palette almost exactly.
--}}

@php
    $brand = (string) ($preview['brand'] ?? '#4f46e5');
    $accent = (string) ($preview['accent'] ?? '#0ea5e9');
    $company = (string) ($preview['company'] ?? 'My Office');
    $tagline = (string) ($preview['tagline'] ?? '');

    $initials = \Illuminate\Support\Str::of($company)
        ->explode(' ')
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');

    // "#4f46e5" => "79 70 229", the triple shape the --brand-* variables hold.
    $triple = static function (string $hex): ?string {
        if (! preg_match('/^#([0-9A-Fa-f]{6})$/', $hex, $matches)) {
            return null;
        }

        $int = (int) hexdec($matches[1]);

        return (($int >> 16) & 255).' '.(($int >> 8) & 255).' '.($int & 255);
    };

    // Surfaces the mock paints from the live variables. The fallback is the saved brand colour
    // (brand_color *is* the 600 stop), so the first paint is right even before any build lands.
    $brand600 = 'rgb(var(--brand-600, '.($triple($brand) ?? '79 70 229').'))';
    $brand500 = 'rgb(var(--brand-500, 99 102 241))';
    $brand50 = 'rgb(var(--brand-50, 238 242 255))';
    $brand700 = 'rgb(var(--brand-700, 67 56 202))';

    $logoLight = $preview['logo_light'] ?? null;
    $logoDark = $preview['logo_dark'] ?? $logoLight;
@endphp

<x-ui.card
    title="Live preview"
    subtitle="Unsaved — the shell around this card is repainted as you choose."
    icon="sparkles"
>
    <div class="overflow-hidden rounded-xl ring-1 ring-slate-200 dark:ring-slate-700">
        <div class="flex min-h-[13rem]">
            {{-- Mock sidebar ------------------------------------------------------------ --}}
            <div class="w-36 shrink-0 bg-slate-900 p-3 sm:w-44">
                <div class="flex h-8 items-center gap-2">
                    <img
                        id="settings-preview-logo-dark"
                        @if ($logoDark) src="{{ $logoDark }}" @endif
                        alt=""
                        class="h-7 max-w-full object-contain {{ $logoDark ? '' : 'hidden' }}"
                    />

                    <span
                        id="settings-preview-logo-dark-fallback"
                        class="flex items-center gap-2 {{ $logoDark ? 'hidden' : '' }}"
                    >
                        <span
                            class="inline-flex h-7 w-7 items-center justify-center rounded-lg text-2xs font-bold text-white"
                            style="background-color: {{ $brand600 }}"
                        >{{ $initials }}</span>
                        <span class="truncate text-2xs font-semibold text-white">{{ $company }}</span>
                    </span>
                </div>

                <div class="mt-4 space-y-1">
                    <div
                        class="flex items-center gap-2 rounded-md px-2 py-1.5 text-2xs font-semibold text-white"
                        style="background-color: {{ $brand600 }}"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-white/80"></span>
                        Dashboard
                    </div>

                    @foreach (['Users', 'Projects', 'Settings'] as $item)
                        <div class="flex items-center gap-2 rounded-md px-2 py-1.5 text-2xs font-medium text-slate-400">
                            <span class="h-1.5 w-1.5 rounded-full bg-slate-600"></span>
                            {{ $item }}
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Mock content ------------------------------------------------------------ --}}
            <div class="min-w-0 flex-1 bg-white dark:bg-slate-900">
                <div class="flex h-11 items-center justify-between gap-3 border-b border-slate-200 px-3 dark:border-slate-800">
                    <div class="flex min-w-0 items-center gap-2">
                        <img
                            id="settings-preview-logo-light"
                            @if ($logoLight) src="{{ $logoLight }}" @endif
                            alt=""
                            class="h-6 max-w-[8rem] object-contain {{ $logoLight ? '' : 'hidden' }}"
                        />

                        <span
                            id="settings-preview-logo-light-fallback"
                            class="truncate text-xs font-semibold text-slate-800 dark:text-slate-100 {{ $logoLight ? 'hidden' : '' }}"
                        >{{ $company }}</span>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <span class="hidden h-5 w-24 rounded-full bg-slate-100 sm:block dark:bg-slate-800"></span>
                        <span
                            class="inline-flex h-6 w-6 items-center justify-center rounded-full text-2xs font-bold text-white"
                            style="background-color: {{ $brand500 }}"
                        >{{ mb_substr($initials, 0, 1) }}</span>
                    </div>
                </div>

                <div class="space-y-3 p-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            class="inline-flex items-center rounded-lg px-2.5 py-1.5 text-2xs font-semibold text-white shadow-sm"
                            style="background-color: {{ $brand600 }}"
                        >Primary action</span>

                        <span
                            class="inline-flex items-center rounded-full px-2 py-0.5 text-2xs font-medium"
                            style="background-color: {{ $brand50 }}; color: {{ $brand700 }}"
                        >Badge</span>

                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-2xs font-medium text-white"
                            style="background-color: var(--settings-accent, {{ $accent }})"
                        >Accent</span>
                    </div>

                    <div class="space-y-1.5">
                        <div class="h-2 w-full rounded-full bg-slate-100 dark:bg-slate-800"></div>
                        <div class="h-2 w-4/5 rounded-full bg-slate-100 dark:bg-slate-800"></div>
                        <div class="h-2 w-2/3 rounded-full bg-slate-100 dark:bg-slate-800"></div>
                    </div>

                    @if (filled($tagline))
                        <p class="truncate text-2xs italic text-slate-400 dark:text-slate-500">“{{ $tagline }}”</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <x-slot:footer>
        <span class="flex items-center gap-1.5">
            <x-ui.icon name="information-circle" class="h-3.5 w-3.5 shrink-0 opacity-70" />
            The colours are applied to this page only until you save. Favicons update after the next reload.
        </span>
    </x-slot:footer>
</x-ui.card>

@push('scripts')
    <script>
        /*
         |  Branding live preview (phase-02 §5).
         |
         |  Registered as a classic inline script, so it is defined before Alpine starts and the
         |  colour field's x-effect can call it on its very first evaluation.
         */
        window.settingsBrand = (function () {
            const root = document.documentElement;

            // stop => how far to mix the chosen colour toward white (+) or black (-).
            // Derived from Tailwind's indigo scale relative to indigo-600, so #4f46e5 reproduces
            // the Phase 1 palette.
            const stops = {
                50: 0.93, 100: 0.86, 200: 0.73, 300: 0.55, 400: 0.33, 500: 0.15,
                600: 0, 700: -0.15, 800: -0.3, 900: -0.39, 950: -0.64,
            };

            const logos = {
                logo_light: 'settings-preview-logo-light',
                logo_dark: 'settings-preview-logo-dark',
            };

            const clamp = (value) => Math.max(0, Math.min(255, Math.round(value)));

            function parse(hex) {
                const match = /^#?([0-9a-f]{6})$/i.exec(String(hex || '').trim());

                if (!match) {
                    return null;
                }

                const int = parseInt(match[1], 16);

                return [(int >> 16) & 255, (int >> 8) & 255, int & 255];
            }

            function mix([r, g, b], amount) {
                if (amount >= 0) {
                    return [r + (255 - r) * amount, g + (255 - g) * amount, b + (255 - b) * amount];
                }

                const keep = 1 + amount;

                return [r * keep, g * keep, b * keep];
            }

            return {
                /** Repaint the shell from one brand colour. */
                brand(hex) {
                    const rgb = parse(hex);

                    if (!rgb) {
                        return;
                    }

                    Object.keys(stops).forEach((stop) => {
                        const [r, g, b] = mix(rgb, stops[stop]);

                        root.style.setProperty(`--brand-${stop}`, `${clamp(r)} ${clamp(g)} ${clamp(b)}`);
                    });
                },

                /** The accent colour is previewed in the mock; the shell has no accent token. */
                accent(hex) {
                    if (parse(hex)) {
                        root.style.setProperty('--settings-accent', hex);
                    }
                },

                /** Paint a just-chosen logo into the mock sidebar / topbar. */
                logo(key, file) {
                    const id = logos[key];

                    if (!id || !file || !String(file.type || '').startsWith('image/')) {
                        return;
                    }

                    const image = document.getElementById(id);

                    if (!image) {
                        return;
                    }

                    const reader = new FileReader();

                    reader.onload = () => {
                        image.src = reader.result;
                        image.classList.remove('hidden');
                        document.getElementById(`${id}-fallback`)?.classList.add('hidden');
                    };

                    reader.readAsDataURL(file);
                },
            };
        })();
    </script>
@endpush
