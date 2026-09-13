/**
 * The one chart runtime — partner to `resources/views/components/ui/chart.blade.php`.
 *
 * Every chart in this product goes through `<x-ui.chart>`, and every `<x-ui.chart>` is drawn by
 * this file. That is deliberate: one place decides what a chart looks like, so the fortieth chart
 * a later phase adds is consistent with the first without anybody having to remember how.
 *
 * What it guarantees
 * ------------------
 *   · **Theme aware.** Colours are read from the live CSS custom properties, so a brand colour
 *     changed in Settings repaints the charts with no rebuild. A `MutationObserver` on the `dark`
 *     class repaints on every path that can change the theme — the switcher, the OS following
 *     "system", and another tab — without reloading or rebuilding the chart.
 *   · **Tabular-nums tooltips.** Chart.js draws its own tooltip on the canvas, where
 *     `font-variant-numeric` does not exist, so this uses an external HTML tooltip: a real DOM
 *     element with the `tabular` class, which is how a column of figures stops jittering.
 *   · **No chartjunk.** No 3-D, no shadows, no bevels, no second y-axis, no gradient fills beyond
 *     a flat tint under a line. Horizontal gridlines only, a half-pixel hairline, axis ticks at
 *     the density the container can actually read. Animation is 220 ms on first draw and off for
 *     every update, and off entirely under `prefers-reduced-motion`.
 *   · **Degrades.** The Blade component always ships a real data table next to the canvas. With
 *     JavaScript off the canvas is hidden and the table is shown (see the component's `noscript`
 *     block); with JavaScript on the table stays in the accessibility tree, screen-reader-only.
 *
 * Lifecycle: charts initialise on DOM ready and, because the dashboard swaps widget bodies in as
 * HTML, again whenever a `[data-chart]` element is added to the document. Removed nodes have their
 * chart destroyed, so a dashboard the user refreshes fifty times does not leak fifty canvases.
 */

import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';

Chart.register(
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    DoughnutController,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
);

/*
|--------------------------------------------------------------------------
| Palette
|--------------------------------------------------------------------------
|
| Series colours are named with the same tokens every enum's color() returns, so a widget says
| `'color' => $status->color()` and the chart matches its badge. `brand` is read from the CSS
| variables the admin layout injects from the branding.brand_color setting; the rest are the
| Tailwind values the rest of the UI uses, as [r, g, b] triples.
|
*/

const FALLBACK = {
    brand: [99, 102, 241], // indigo-500 — the Phase 1 brand, used until --brand-500 exists
    emerald: [16, 185, 129],
    rose: [244, 63, 94],
    amber: [245, 158, 11],
    sky: [14, 165, 233],
    violet: [139, 92, 246],
    slate: [100, 116, 139],
    cyan: [6, 182, 212],
    teal: [20, 184, 166],
    indigo: [99, 102, 241],
};

/** Order a multi-series chart falls back to when a dataset names no colour. */
const SERIES_ORDER = ['brand', 'emerald', 'amber', 'violet', 'sky', 'rose', 'teal', 'slate'];

/** Read a `--brand-500`-style variable, which the layout writes as "R G B". */
function readVariable(name) {
    if (typeof window === 'undefined' || !window.getComputedStyle) {
        return null;
    }

    const raw = window.getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    if (!raw) {
        return null;
    }

    // "99 102 241" (the token contract) or "99, 102, 241".
    const parts = raw.split(/[\s,]+/).map((part) => Number.parseInt(part, 10));

    if (parts.length >= 3 && parts.slice(0, 3).every((part) => Number.isFinite(part))) {
        return parts.slice(0, 3);
    }

    // A hex value, in case somebody points the variable at one.
    const hex = raw.replace('#', '');

    if (/^[0-9a-f]{6}$/i.test(hex)) {
        return [hex.slice(0, 2), hex.slice(2, 4), hex.slice(4, 6)].map((pair) => Number.parseInt(pair, 16));
    }

    return null;
}

/** A token name -> [r, g, b]. Brand tokens come from the live theme. */
function triple(token) {
    const name = String(token || 'brand').toLowerCase();

    if (name === 'brand' || name.startsWith('brand-')) {
        const step = name === 'brand' ? '500' : name.split('-')[1];

        return readVariable(`--brand-${step}`) || FALLBACK.brand;
    }

    return FALLBACK[name] || FALLBACK.brand;
}

function rgb([r, g, b], alpha = 1) {
    return alpha >= 1 ? `rgb(${r} ${g} ${b})` : `rgb(${r} ${g} ${b} / ${alpha})`;
}

/*
|--------------------------------------------------------------------------
| Theme
|--------------------------------------------------------------------------
*/

function isDark() {
    return document.documentElement.classList.contains('dark');
}

function prefersReducedMotion() {
    return Boolean(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
}

/** The two or three greys a chart needs, for the theme currently painted. */
function theme() {
    const dark = isDark();

    return {
        dark,
        text: dark ? 'rgb(148 163 184)' : 'rgb(100 116 139)', // slate-400 / slate-500
        strongText: dark ? 'rgb(226 232 240)' : 'rgb(30 41 59)', // slate-200 / slate-800
        grid: dark ? 'rgb(30 41 59 / 0.9)' : 'rgb(226 232 240 / 0.9)', // slate-800 / slate-200
        surface: dark ? 'rgb(15 23 42)' : 'rgb(255 255 255)', // slate-900 / white
        border: dark ? 'rgb(51 65 85)' : 'rgb(203 213 225)',
    };
}

/*
|--------------------------------------------------------------------------
| External tooltip — an HTML element, so figures can be tabular-nums
|--------------------------------------------------------------------------
*/

const TOOLTIP_ID = 'ui-chart-tooltip';

function tooltipElement() {
    let element = document.getElementById(TOOLTIP_ID);

    if (element) {
        return element;
    }

    element = document.createElement('div');
    element.id = TOOLTIP_ID;
    element.setAttribute('role', 'status');
    element.setAttribute('aria-live', 'polite');
    element.className = 'tabular pointer-events-none fixed z-toast hidden min-w-[9rem] rounded-lg px-3 py-2 text-xs shadow-dropdown ring-1';
    document.body.appendChild(element);

    return element;
}

function renderTooltip(context, config) {
    const element = tooltipElement();
    const model = context.tooltip;
    const tones = theme();

    if (!model || model.opacity === 0) {
        element.classList.add('hidden');

        return;
    }

    element.style.background = tones.surface;
    element.style.color = tones.strongText;
    element.style.setProperty('--tw-ring-color', tones.border);

    const title = (model.title || []).join(' ');

    const rows = (model.dataPoints || []).map((point) => {
        const swatch = rgb(triple(config.series?.[point.datasetIndex]?.color), 1);
        const label = config.series?.[point.datasetIndex]?.label || point.dataset.label || '';
        const value = formatValue(point.parsed.y ?? point.parsed, config);

        return `<div class="mt-1 flex items-center justify-between gap-3 first:mt-0">
            <span class="flex min-w-0 items-center gap-1.5">
                <span style="background:${swatch}" class="h-2 w-2 shrink-0 rounded-full"></span>
                <span class="truncate opacity-80">${escapeHtml(label)}</span>
            </span>
            <span class="font-semibold tabular-nums">${escapeHtml(value)}</span>
        </div>`;
    });

    element.innerHTML =
        (title ? `<div class="mb-1.5 font-semibold">${escapeHtml(title)}</div>` : '') + rows.join('');

    // Position against the viewport: `fixed` keeps the tooltip correct inside a scrolling card.
    const box = context.chart.canvas.getBoundingClientRect();
    const width = element.offsetWidth || 150;
    const left = Math.min(
        Math.max(8, box.left + model.caretX - width / 2),
        window.innerWidth - width - 8,
    );

    element.style.left = `${left}px`;
    element.style.top = `${Math.max(8, box.top + model.caretY - element.offsetHeight - 12)}px`;
    element.classList.remove('hidden');
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[character]);
}

/*
|--------------------------------------------------------------------------
| Values
|--------------------------------------------------------------------------
*/

function formatValue(value, config) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '—';
    }

    const number = Number(value);
    const decimals = Number.isInteger(number) ? 0 : (config.decimals ?? 1);

    const formatted = number.toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });

    return `${config.valuePrefix || ''}${formatted}${config.valueSuffix || ''}`;
}

/*
|--------------------------------------------------------------------------
| Building a chart
|--------------------------------------------------------------------------
*/

function datasets(config) {
    const type = config.type || 'line';

    return (config.series || []).map((series, index) => {
        const token = series.color || SERIES_ORDER[index % SERIES_ORDER.length];
        const colour = triple(token);

        if (type === 'doughnut') {
            return {
                label: series.label || '',
                data: series.data || [],
                backgroundColor: (series.colors || []).length
                    ? series.colors.map((item) => rgb(triple(item)))
                    : (series.data || []).map((_, slice) =>
                        rgb(triple(SERIES_ORDER[slice % SERIES_ORDER.length])),
                    ),
                borderColor: theme().surface,
                borderWidth: 2,
                hoverOffset: 4,
            };
        }

        if (type === 'bar') {
            return {
                label: series.label || '',
                data: series.data || [],
                backgroundColor: rgb(colour, 0.85),
                hoverBackgroundColor: rgb(colour, 1),
                borderRadius: 4,
                borderSkipped: false,
                maxBarThickness: 28,
            };
        }

        return {
            label: series.label || '',
            data: series.data || [],
            borderColor: rgb(colour),
            backgroundColor: series.fill === false ? 'transparent' : rgb(colour, isDark() ? 0.16 : 0.1),
            fill: series.fill === false ? false : 'origin',
            borderWidth: 2,
            tension: 0.32,
            pointRadius: 0,
            pointHoverRadius: 4,
            pointHoverBorderWidth: 2,
            pointHoverBackgroundColor: rgb(colour),
            pointHoverBorderColor: theme().surface,
        };
    });
}

function options(config) {
    const tones = theme();
    const type = config.type || 'line';
    const multi = (config.series || []).length > 1;
    const showLegend = config.legend === null || config.legend === undefined ? multi : Boolean(config.legend);

    const base = {
        responsive: true,
        maintainAspectRatio: false,
        animation: prefersReducedMotion() ? false : { duration: 220 },
        // One tooltip for the whole x position: comparing two series is the point of the chart.
        interaction: { mode: type === 'doughnut' ? 'nearest' : 'index', intersect: false },
        layout: { padding: { top: 4, right: 2, bottom: 0, left: 2 } },
        plugins: {
            legend: showLegend
                ? {
                    display: true,
                    position: 'bottom',
                    align: 'start',
                    labels: {
                        boxWidth: 8,
                        boxHeight: 8,
                        usePointStyle: true,
                        pointStyle: 'circle',
                        color: tones.text,
                        padding: 14,
                        font: { size: 11, family: 'Inter, ui-sans-serif, system-ui' },
                    },
                }
                : { display: false },
            tooltip: {
                enabled: false,
                external: (context) => renderTooltip(context, config),
            },
        },
    };

    if (type === 'doughnut') {
        return { ...base, cutout: '68%' };
    }

    return {
        ...base,
        scales: {
            x: {
                // Vertical gridlines are the commonest piece of chartjunk; the tick labels
                // already say where each point sits.
                grid: { display: false, drawBorder: false },
                border: { display: false },
                ticks: {
                    color: tones.text,
                    maxRotation: 0,
                    autoSkip: true,
                    maxTicksLimit: config.maxXTicks || 8,
                    font: { size: 11, family: 'Inter, ui-sans-serif, system-ui' },
                },
                stacked: Boolean(config.stacked),
            },
            y: {
                beginAtZero: true,
                grid: { color: tones.grid, lineWidth: 1, drawTicks: false },
                border: { display: false, dash: [0] },
                ticks: {
                    color: tones.text,
                    padding: 8,
                    maxTicksLimit: 5,
                    precision: 0,
                    font: { size: 11, family: 'Inter, ui-sans-serif, system-ui' },
                    callback: (value) => formatValue(value, config),
                },
                stacked: Boolean(config.stacked),
                title: config.yLabel
                    ? { display: true, text: config.yLabel, color: tones.text, font: { size: 11 } }
                    : { display: false },
            },
        },
    };
}

/*
|--------------------------------------------------------------------------
| Registry of live charts
|--------------------------------------------------------------------------
*/

/** @type {Map<HTMLElement, {chart: import('chart.js').Chart, config: object}>} */
const live = new Map();

function readConfig(root) {
    const script = root.querySelector('[data-chart-config]');

    if (!script) {
        return null;
    }

    try {
        return JSON.parse(script.textContent || '{}');
    } catch (error) {
        return null;
    }
}

export function createChart(root) {
    if (!root || live.has(root)) {
        return live.get(root)?.chart || null;
    }

    const canvas = root.querySelector('canvas');
    const config = readConfig(root);

    if (!canvas || !config || !(config.series || []).length) {
        return null;
    }

    // An all-zero series is a legitimate answer ("nobody signed in") and still draws a flat line;
    // an empty series is a missing answer and the component renders its empty state instead.
    const chart = new Chart(canvas, {
        type: config.type || 'line',
        data: { labels: config.labels || [], datasets: datasets(config) },
        options: options(config),
    });

    live.set(root, { chart, config });
    root.dataset.chartReady = 'true';

    return chart;
}

export function destroyChart(root) {
    const entry = live.get(root);

    if (!entry) {
        return;
    }

    entry.chart.destroy();
    live.delete(root);
    delete root.dataset.chartReady;
}

/** Re-apply colours and scales for the theme now painted, without rebuilding the chart. */
export function repaintCharts() {
    live.forEach(({ chart, config }) => {
        chart.data.datasets = datasets(config);
        chart.options = options(config);
        chart.update('none');
    });

    const tooltip = document.getElementById(TOOLTIP_ID);

    tooltip?.classList.add('hidden');
}

/** Draw every chart in a subtree that is not drawn yet. */
export function initCharts(scope = document) {
    scope.querySelectorAll?.('[data-chart]').forEach((root) => createChart(root));
}

/*
|--------------------------------------------------------------------------
| Wiring
|--------------------------------------------------------------------------
*/

function watchTheme() {
    // Covers every path that can change the theme: the switcher, the OS while the preference is
    // "system", and another tab — all of them end in this class being toggled on <html>.
    new MutationObserver((records) => {
        if (records.some((record) => record.attributeName === 'class')) {
            repaintCharts();
        }
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

    // The brand colour can change without the theme doing so (a settings save re-renders the
    // page, but a live preview does not), so listen for the app's own event too.
    window.addEventListener('theme-changed', () => repaintCharts());
    window.addEventListener('brand-changed', () => repaintCharts());
}

function watchDom() {
    // The dashboard swaps widget bodies in as HTML, so charts arrive after first paint.
    new MutationObserver((records) => {
        records.forEach((record) => {
            record.addedNodes.forEach((node) => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }

                if (node.matches('[data-chart]')) {
                    createChart(node);
                }

                node.querySelectorAll?.('[data-chart]').forEach((root) => createChart(root));
            });

            record.removedNodes.forEach((node) => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }

                if (node.matches('[data-chart]')) {
                    destroyChart(node);
                }

                node.querySelectorAll?.('[data-chart]').forEach((root) => destroyChart(root));
            });
        });
    }).observe(document.body, { childList: true, subtree: true });
}

function boot() {
    initCharts();
    watchTheme();
    watchDom();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}

// So a page-level script (or a later phase's Alpine component) can redraw on demand.
window.uiCharts = { init: initCharts, create: createChart, destroy: destroyChart, repaint: repaintCharts };

export default window.uiCharts;
