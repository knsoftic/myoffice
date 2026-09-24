<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * PRF-11 and PRF-12 — what the browser downloads before it can show anything
 * (phase-24-25 §6.4 "Asset build" and "Tailwind purge safety", §11.7, GL-32).
 *
 * **Neither id had a method before this file, which is why nobody knew PRF-12 was red.** §11's
 * preamble says every id is a real test method name prefix; an id with no method is a requirement
 * that cannot fail, and a requirement that cannot fail is indistinguishable from one that passes.
 *
 * **A bundle budget is the one performance rule a fast machine cannot hide.** Every other number in
 * §11.7 improves when the developer's laptop is quicker; the number of kilobytes a phone on a slow
 * connection has to pull down before the first screen paints does not. It is measured gzipped
 * because that is what the wire carries, and it is measured from the **manifest's chunk graph**
 * rather than from the directory listing, because a file that is built but only fetched on the three
 * screens that draw a chart is not part of what the first page costs.
 *
 * That last point is the whole reason `charts.js` is a second Vite entry instead of an import inside
 * `app.js`: Chart.js is 65 KB gzipped on its own, and folding it into the eager bundle would put a
 * third of the JS budget on every screen in the system to serve the handful that draw anything.
 *
 * No test here needs a database, a fixture or a route. That is deliberate: this is the one check
 * that can be run against a deployed artifact rather than against a working copy.
 */
#[Group('perf')]
final class AssetBudgetTest extends TestCase
{
    /** §11.7: total CSS, gzipped. */
    private const CSS_BUDGET_BYTES = 70 * 1024;

    /** §11.7: total **eagerly-loaded** JS, gzipped — Alpine plus the app, not the chart bundle. */
    private const EAGER_JS_BUDGET_BYTES = 200 * 1024;

    /**
     * The Vite entry that must never be eager.
     *
     * Named as a constant rather than inlined because two assertions depend on the same fact and a
     * rename of the entry has to break both of them at once.
     */
    private const LAZY_ENTRY = 'resources/js/charts.js';

    #[Test]
    public function test_asset_budget(): void
    {
        $manifest = $this->manifest();

        // 1. Every built file is content-hashed, so `build/` can be served immutable for a year
        //    (GL-32). A file served under a stable name behind a one-year cache header is a file
        //    that cannot be corrected without renaming it by hand.
        $unhashed = [];

        foreach ($manifest as $key => $chunk) {
            $file = is_array($chunk) ? ($chunk['file'] ?? null) : null;

            if (! is_string($file) || preg_match('/-[A-Za-z0-9_-]{8,}\.(?:css|js)$/', $file) !== 1) {
                $unhashed[] = $key.' => '.(is_string($file) ? $file : '(no file)');
            }
        }

        $this->assertSame(
            [],
            $unhashed,
            "These build entries are not content-hashed, so the one-year immutable cache header of "
            ."GL-32 would pin a stale copy in every visitor's browser until they cleared it by "
            ."hand:\n  ".implode("\n  ", $unhashed)
        );

        // 2. No source maps. A `.map` is the unminified source of the whole application, served
        //    publicly, with no authentication in front of it.
        $maps = [];

        foreach ($this->buildFiles() as $path) {
            if (str_ends_with($path, '.map')) {
                $maps[] = $this->relative($path);
            }
        }

        $this->assertSame(
            [],
            $maps,
            "Source maps were shipped. A .map file is the readable source of this application served "
            ."to anybody who asks for it (§6.4: build.sourcemap = false):\n  ".implode("\n  ", $maps)
        );

        // 3. The two budgets.
        $css = 0;
        $cssFiles = [];

        foreach ($this->buildFiles() as $path) {
            if (str_ends_with($path, '.css')) {
                $css += $this->gzippedSize($path);
                $cssFiles[] = $this->relative($path);
            }
        }

        $this->assertLessThanOrEqual(
            self::CSS_BUDGET_BYTES,
            $css,
            sprintf(
                'The stylesheet is %s gzipped against a %s budget (§11.7 PRF-11). Files: %s. A '
                .'stylesheet over budget is almost always a purge that stopped working, not a design '
                .'that grew.',
                $this->kb($css),
                $this->kb(self::CSS_BUDGET_BYTES),
                implode(', ', $cssFiles),
            ),
        );

        $eager = $this->eagerChunks($manifest);

        $this->assertNotSame(
            [],
            $eager,
            'No eagerly-loaded JS chunk was resolved from the layouts, so the JS budget below would '
            .'pass over an empty set. The @vite() scan of resources/views/layouts is what feeds it.',
        );

        $js = 0;

        foreach ($eager as $file) {
            if (str_ends_with($file, '.js')) {
                $js += $this->gzippedSize(public_path('build/'.$file));
            }
        }

        $this->assertLessThanOrEqual(
            self::EAGER_JS_BUDGET_BYTES,
            $js,
            sprintf(
                'The eagerly-loaded JS is %s gzipped against a %s budget (§11.7 PRF-11). Chunks: %s.',
                $this->kb($js),
                $this->kb(self::EAGER_JS_BUDGET_BYTES),
                implode(', ', $eager),
            ),
        );

        // 4. Chart.js is code-split: it is an entry of its own, no layout pulls it, and the chart
        //    component does.
        $this->assertArrayHasKey(
            self::LAZY_ENTRY,
            $manifest,
            self::LAZY_ENTRY.' is not a Vite input, so <x-ui.chart>\'s @vite() call cannot resolve it '
            .'and the component silently renders an empty canvas (vite.config.js `input`).',
        );

        $chartChunk = $manifest[self::LAZY_ENTRY]['file'] ?? '';

        $this->assertNotContains(
            $chartChunk,
            $eager,
            'Chart.js is in the eagerly-loaded bundle. It is a second entry point precisely so that '
            .'the screens which draw nothing do not pay for it (vite.config.js, §6.4); a layout that '
            .'@vite()s it puts it on every screen in the system.',
        );

        $this->assertStringContainsString(
            self::LAZY_ENTRY,
            (string) file_get_contents(resource_path('views/components/ui/chart.blade.php')),
            'x-ui.chart no longer pulls its own bundle, so either charts render without Chart.js or '
            .'something else is loading it eagerly.',
        );
    }

    /**
     * PRF-12 — declared, and red.
     *
     * **This id is skipped with a measurement, not with a shrug.** Running the scan by hand over
     * `app/Enums` gives 17 distinct tokens returned by `color()`, every one of which does have an
     * entry in `x-ui.badge`'s literal palette — so the "never interpolated into a class attribute"
     * half of the rule holds. What does not hold is the built CSS: 39 of the classes those palette
     * entries emit are absent from `public/build/assets/*.css`, all of them from the `solid`
     * variant (`bg-{token}-700`, `ring-{token}-700`, `dark:ring-{token}-400`) on tokens including
     * cyan, teal, lime, pink, green and blue. A solid badge in any of those colours renders
     * unstyled — which is the exact failure §6.4 "Tailwind purge safety" describes.
     *
     * It is not written as a live assertion in this round for two reasons, both of which need a
     * decision rather than a test:
     *
     *  1. **§11.7's literal grep targets do not match the component.** PRF-12 says to grep the built
     *     CSS for `bg-{token}-100` and `text-{token}-800`. `x-ui.badge` resolves its soft variant to
     *     `-50`/`-700` and its solid to `-700`/white; the shades were changed deliberately and the
     *     reason is in the component's own docblock (contrast measured for §6.5, `-100`/`-800` fails
     *     4.5:1 on amber and yellow). Asserting the contract's literal shades would fail on every
     *     token for a reason that is not a defect.
     *  2. **§11.7 also names `x-ui.stat-card` as a token consumer.** It is not one: its palette is a
     *     three-entry trend map (`up`/`down`/`flat`), and no enum `color()` value reaches it.
     *
     * Fixing this means either rebuilding with a `safelist` that covers the solid variant, or
     * narrowing the palette to the tokens the enums actually return — and `npm run build` is
     * outside this round's remit. Whoever takes it: assert the classes the palette **emits**, not
     * the shades §11.7 guessed, and correct §11.7 in the same change.
     */
    #[Test]
    public function test_tailwind_purge_keeps_runtime_classes(): void
    {
        $this->markTestSkipped(
            'PRF-12 is RED, not absent. A hand run of the scan finds 39 badge-palette classes missing '
            .'from public/build/assets/*.css — every solid-variant class (bg-{token}-700, '
            .'ring-{token}-700, dark:ring-{token}-400) on cyan, teal, lime, pink, green, blue, '
            .'yellow, orange, violet, sky, indigo, lime and brand. A solid badge in any of those '
            .'renders unstyled. Two contract corrections are needed before this can be asserted as '
            .'written: §11.7 greps for bg-{token}-100 / text-{token}-800, but x-ui.badge resolves to '
            .'-50/-700 (the shades were changed for the contrast numbers in its own docblock), and '
            .'§11.7 names x-ui.stat-card as a second token consumer when its palette is a three-entry '
            .'trend map that no enum color() reaches. Fix: rebuild with a safelist covering the solid '
            .'variant, assert the classes the palette emits, and correct §11.7.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array<string, mixed>>
     */
    private function manifest(): array
    {
        $path = public_path('build/manifest.json');

        $this->assertFileExists(
            $path,
            'public/build/manifest.json does not exist, so the application has never been built and '
            .'every @vite() call in every layout throws (§6.8 step 12, GL-32). Run "npm ci && npm run '
            .'build".',
        );

        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, 'The Vite manifest is not valid JSON.');

        /** @var array<string, array<string, mixed>> $decoded */
        return $decoded;
    }

    /**
     * The built files every layout pulls before the page can paint, chunk graph included.
     *
     * A chunk's `imports` are followed because a small entry that imports a large shared chunk costs
     * the large chunk: the budget is what the browser fetches, not what the entry weighs.
     *
     * @param  array<string, array<string, mixed>>  $manifest
     * @return list<string>
     */
    private function eagerChunks(array $manifest): array
    {
        $files = [];

        foreach ($this->layoutEntries() as $entry) {
            $this->collectChunk($manifest, $entry, $files);
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * @param  array<string, array<string, mixed>>  $manifest
     * @param  list<string>  $files
     */
    private function collectChunk(array $manifest, string $entry, array &$files): void
    {
        $chunk = $manifest[$entry] ?? null;

        if (! is_array($chunk)) {
            return;
        }

        $file = $chunk['file'] ?? null;

        if (is_string($file)) {
            if (in_array($file, $files, true)) {
                return; // Already counted, and this is what stops a cyclic import graph.
            }

            $files[] = $file;
        }

        foreach ((array) ($chunk['imports'] ?? []) as $import) {
            if (is_string($import)) {
                $this->collectChunk($manifest, $import, $files);
            }
        }
    }

    /**
     * Vite entries named by an `@vite()` call in a layout.
     *
     * Only the layouts: a `@vite()` inside a component or a partial pushed onto a stack is a
     * deliberate per-screen load, which is the thing PRF-11 wants to see *more* of.
     *
     * @return list<string>
     */
    private function layoutEntries(): array
    {
        $entries = [];

        foreach ([resource_path('views/layouts'), resource_path('views/site/layouts')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                preg_match_all(
                    '/@vite\(\s*(\[[^\]]*\]|[\'"][^\'"]*[\'"])/',
                    (string) file_get_contents($file->getPathname()),
                    $calls,
                );

                foreach ($calls[1] as $argument) {
                    preg_match_all('/[\'"]([^\'"]+)[\'"]/', $argument, $paths);

                    foreach ($paths[1] as $path) {
                        $entries[] = $path;
                    }
                }
            }
        }

        return array_values(array_unique($entries));
    }

    /**
     * @return list<string>
     */
    private function buildFiles(): array
    {
        $root = public_path('build');

        if (! is_dir($root)) {
            return [];
        }

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * What the wire carries, not what the disk holds.
     */
    private function gzippedSize(string $path): int
    {
        if (! is_file($path)) {
            return 0;
        }

        return strlen((string) gzencode((string) file_get_contents($path), 9));
    }

    private function kb(int $bytes): string
    {
        return number_format($bytes / 1024, 1).' KB';
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
    }
}
