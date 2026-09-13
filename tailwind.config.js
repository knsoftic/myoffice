import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
import forms from '@tailwindcss/forms';

/**
 * Design tokens for the whole product.
 *
 * Rebranding is not even a code change any more: the `brand` scale below resolves through the
 * `--brand-50 … --brand-950` CSS variables, so `branding.brand_color` repaints every button,
 * badge, active nav item, focus ring and gradient at runtime with no rebuild (phase-02 §5).
 *
 * The contract for those variables — do not break either half:
 *   · each one holds three space-separated 0-255 channels, "79 70 229", never a hex or rgb()
 *     string, because Tailwind wraps them as `rgb(var(--brand-500) / <alpha-value>)` so that
 *     `bg-brand-500/25` and `ring-brand-500/40` keep working;
 *   · `resources/css/app.css` defines the whole Phase 1 indigo scale on bare `:root` as the
 *     fallback, and `layouts/partials/brand-theme.blade.php` overrides it from the setting.
 *     A page that renders before the settings table exists still gets indigo, not black.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './app/**/*.php',
    ],

    /**
     * Custom classes declared in resources/css/app.css live in @layer utilities, which Tailwind
     * tree-shakes exactly like its own utilities: a class nothing references yet is dropped from
     * the build. These are part of the shell's public API for later phases, so pin them here —
     * otherwise `card-grid-2` silently renders as a one-column stack in the phase that first
     * uses it.
     */
    safelist: [
        'card-grid',
        'card-grid-2',
        'card-grid-3',
        'surface',
        'surface-muted',
        'hairline',
        'no-scrollbar',
        'tabular',
        'skeleton-sweep',
        'focus-not-sr-only',
        'is-rail',
        'is-open',
        'shell-sidebar',
        'shell-content',
        'rail-hide',
        'rail-center',
        'rail-trigger',
        'rail-tooltip',
        'nav-group',
    ],

    theme: {
        extend: {
            colors: {
                /*
                 * Runtime brand scale. The values live in CSS variables (indigo by default,
                 * derived from `branding.brand_color` when one is set) — see the file header.
                 * `<alpha-value>` is what keeps the `/opacity` modifiers working.
                 */
                brand: {
                    50: 'rgb(var(--brand-50) / <alpha-value>)',
                    100: 'rgb(var(--brand-100) / <alpha-value>)',
                    200: 'rgb(var(--brand-200) / <alpha-value>)',
                    300: 'rgb(var(--brand-300) / <alpha-value>)',
                    400: 'rgb(var(--brand-400) / <alpha-value>)',
                    500: 'rgb(var(--brand-500) / <alpha-value>)',
                    600: 'rgb(var(--brand-600) / <alpha-value>)',
                    700: 'rgb(var(--brand-700) / <alpha-value>)',
                    800: 'rgb(var(--brand-800) / <alpha-value>)',
                    900: 'rgb(var(--brand-900) / <alpha-value>)',
                    950: 'rgb(var(--brand-950) / <alpha-value>)',
                },
                // Semantic aliases so intent is readable in markup.
                success: colors.emerald,
                warning: colors.amber,
                danger: colors.rose,
                info: colors.sky,
            },

            fontFamily: {
                sans: [
                    'Inter',
                    'Figtree',
                    'ui-sans-serif',
                    'system-ui',
                    ...defaultTheme.fontFamily.sans,
                ],
                mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', ...defaultTheme.fontFamily.mono],
            },

            fontSize: {
                '2xs': ['0.6875rem', { lineHeight: '1rem' }],
            },

            letterSpacing: {
                tightest: '-0.03em',
            },

            borderRadius: {
                xl: '0.75rem',
                '2xl': '1rem',
            },

            spacing: {
                sidebar: '17rem', // 272px expanded sidebar
                rail: '4.75rem', // 76px icon rail
                topbar: '4rem', // h-16 sticky topbar
            },

            maxWidth: {
                'screen-2xl': '1536px',
            },

            boxShadow: {
                card: '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 3px 0 rgb(15 23 42 / 0.06)',
                'card-hover': '0 4px 12px -2px rgb(15 23 42 / 0.10), 0 2px 6px -2px rgb(15 23 42 / 0.06)',
                dropdown: '0 10px 30px -10px rgb(15 23 42 / 0.25), 0 4px 12px -4px rgb(15 23 42 / 0.12)',
                modal: '0 24px 60px -12px rgb(15 23 42 / 0.35)',
                rail: '1px 0 0 0 rgb(226 232 240 / 0.8)',
                'inner-top': 'inset 0 1px 0 0 rgb(255 255 255 / 0.06)',
            },

            transitionDuration: {
                150: '150ms',
            },

            transitionTimingFunction: {
                'out-soft': 'cubic-bezier(0.16, 1, 0.3, 1)',
            },

            zIndex: {
                drawer: '40',
                topbar: '30',
                dropdown: '50',
                modal: '60',
                toast: '70',
            },

            keyframes: {
                'fade-in': {
                    from: { opacity: '0' },
                    to: { opacity: '1' },
                },
                'fade-in-up': {
                    from: { opacity: '0', transform: 'translateY(6px)' },
                    to: { opacity: '1', transform: 'translateY(0)' },
                },
                'scale-in': {
                    from: { opacity: '0', transform: 'scale(0.97)' },
                    to: { opacity: '1', transform: 'scale(1)' },
                },
                'slide-in-right': {
                    from: { opacity: '0', transform: 'translateX(12px)' },
                    to: { opacity: '1', transform: 'translateX(0)' },
                },
                shimmer: {
                    '100%': { transform: 'translateX(100%)' },
                },
                'pulse-soft': {
                    '0%, 100%': { opacity: '1' },
                    '50%': { opacity: '0.55' },
                },
            },

            animation: {
                'fade-in': 'fade-in 150ms ease-out both',
                'fade-in-up': 'fade-in-up 200ms cubic-bezier(0.16, 1, 0.3, 1) both',
                'scale-in': 'scale-in 160ms cubic-bezier(0.16, 1, 0.3, 1) both',
                'slide-in-right': 'slide-in-right 200ms cubic-bezier(0.16, 1, 0.3, 1) both',
                shimmer: 'shimmer 1.6s infinite',
                'pulse-soft': 'pulse-soft 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
            },
        },
    },

    plugins: [forms],
};
