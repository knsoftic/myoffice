import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            /*
             * `charts.js` is a second entry point rather than an import inside `app.js` so that
             * Chart.js is only downloaded on the pages that actually draw a chart. The
             * `<x-ui.chart>` component pulls it in with `@vite` behind `@once`, which is why it
             * has to be listed here: a file that is not an input is not in the build manifest.
             */
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/charts.js'],
            refresh: true,
        }),
    ],
});
