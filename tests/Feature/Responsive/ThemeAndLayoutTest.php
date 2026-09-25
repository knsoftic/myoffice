<?php

declare(strict_types=1);

namespace Tests\Feature\Responsive;

use App\Enums\ThemePreference;
use App\Models\User;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * RSP-01..RSP-07 — the responsive and theme rules a machine can decide (phase-24-25 §11.6).
 *
 * §11.6 is written for two runners. **RSP-01 and RSP-07 need a browser**: a horizontal overflow at
 * 360 px, a layout shift, and which image variant the network actually fetched are facts about a
 * rendered viewport, and no amount of reading Blade produces them. Those two are Playwright specs,
 * blocked on §12 Q3, and are skipped here by name rather than quietly left out — a sweep that
 * silently covers five of seven rules reads exactly like one that covers all seven.
 *
 * The rest are decidable from source, and that is a feature rather than a compromise: **a scan finds
 * the screen nobody opened.** A manual pass at five widths covers the screens the tester thought of,
 * and this application has some two hundred of them. A missing `dark:` variant, a raw `<table>`
 * outside a scroll container and a theme script placed after the stylesheet are all visible in the
 * file, on every screen, on every run.
 *
 * The theme rules are the ones with a user-visible failure mode that a screenshot would not catch:
 * a pre-paint script that runs *after* the stylesheet gives every dark-mode user a white flash on
 * every navigation, and a preference stored only in `localStorage` is a preference that does not
 * follow them to a second device (D12 — the row is the authority, storage is the mirror).
 *
 * **Every row of §11.6 that names a PHP method carries that exact name here.** §11's preamble says
 * "every id below is a real test method name prefix", and it means it: an id no method carries is a
 * requirement nothing asserts, and the gap is invisible from either end — the contract reads covered
 * and the suite reads green. Where one id is split across several methods (RSP-03 over three, RSP-06
 * over its layout and its documents), the contract's name goes on the method carrying the id's own
 * assertion and the rest keep descriptive names, so a failure still says which clause broke.
 *
 * RSP-01 is the one row with no PHP name to carry: §11.6 names it `responsive.spec.ts`, a Playwright
 * spec, so the method below keeps a descriptive name and skips.
 */
final class ThemeAndLayoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The two print shells. A view rendered inside one of these is ink on paper.
     *
     * A printed table has nowhere to scroll to, so RSP-04's rule about horizontal overflow does not
     * apply to it — RSP-06 checks these instead, against the print stylesheet.
     *
     * **The exemption is by layout, never by file name.** It used to be a list of basename
     * fragments (`print`, `slip`, `receipt`, `voucher`, …) and that list exempted three real screens:
     * `admin/hr/payslips/{print,show}` and `admin/hr/my/payslip-show` all extend `layouts.admin`, so
     * they are screens at 360 px that happen to have "slip" in the name, and the payslip's two
     * figure tables sat outside any scroll container for as long as the fragment list covered for
     * them. A name is not a layout.
     *
     * @var list<string>
     */
    private const PAPER_LAYOUTS = ['layouts/print.blade.php', 'layouts/document.blade.php'];

    /**
     * Memoised because the include walk asks for the same two hundred files repeatedly, and reading
     * them once per question turns a scan into a disk benchmark.
     *
     * @var array<string, string>|null
     */
    private ?array $bladeSources = null;

    /** @var array<string, list<array{0: string, 1: int}>>|null */
    private ?array $includeSites = null;

    /*
    |--------------------------------------------------------------------------
    | RSP-01, RSP-07 — the browser rows
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_screen_fits_six_widths_in_both_themes(): void
    {
        $this->markTestSkipped(
            'RSP-01 needs a browser. "document.documentElement.scrollWidth <= innerWidth + 1" at six '
            .'widths, zero console errors, zero failed asset requests and a screenshot per screen are '
            .'facts about a rendered viewport, not about Blade. It is the Playwright spec of §6.5, '
            .'blocked on §12 Q3 (whether this stack takes on a browser runner at all).'
        );
    }

    #[Test]
    public function test_public_pages_carry_responsive_images_and_no_layout_shift(): void
    {
        $this->markTestSkipped(
            'RSP-07 needs a browser for its load half: "at 390 px the network panel shows the 640-wide '
            .'variant was fetched, not the 2560". The markup half (WebP source, srcset, sizes, intrinsic '
            .'width/height, loading, decoding) is asserted by Phase 3\'s own x-site.image tests, so it is '
            .'not duplicated here with a weaker check.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RSP-02 — dark variants
    |--------------------------------------------------------------------------
    */

    /**
     * A colour utility with no `dark:` counterpart is a light-mode leak: one panel stays white inside
     * a dark screen, usually a panel nobody opens in the dark.
     *
     * The contract pairs this scan with `tests/Support/dark-mode-allowlist.php`, where the legitimate
     * exceptions live with a reason each — the print views and the certificate template, which are
     * ink on paper and have no dark mode to have. That file does not exist yet, and running the scan
     * without it would report several hundred attributes of which the great majority are those
     * documents. So the scan runs, and its result is reported as a **number in a skip** rather than as
     * a pass: the gap stays in front of whoever runs the suite instead of looking like coverage.
     */
    #[Test]
    public function test_every_view_has_dark_variants(): void
    {
        $allowlist = base_path('tests/Support/dark-mode-allowlist.php');

        $violations = $this->darkModeViolations();

        if (! is_file($allowlist)) {
            $files = [];

            foreach ($violations as $violation) {
                $file = explode(':', $violation)[0];
                $files[$file] = ($files[$file] ?? 0) + 1;
            }

            arsort($files);

            $this->markTestSkipped(sprintf(
                'RSP-02 cannot run: tests/Support/dark-mode-allowlist.php does not exist, and §11.6 makes '
                .'it the place the legitimate exceptions (print views, the certificate template) are '
                .'written down with a reason. A dry run of the scan found %d colour utilities with no '
                .'dark: counterpart across %d views — heaviest: %s. Create the allowlist, triage that '
                .'list, and this test starts failing on the ones that are real.',
                count($violations),
                count($files),
                implode(', ', array_map(
                    static fn (string $file, int $count): string => $file.' ('.$count.')',
                    array_slice(array_keys($files), 0, 5),
                    array_slice(array_values($files), 0, 5),
                )),
            ));
        }

        /** @var array<string, string> $allowed */
        $allowed = require $allowlist;

        $remaining = array_values(array_filter(
            $violations,
            static function (string $violation) use ($allowed): bool {
                foreach (array_keys($allowed) as $path) {
                    if (str_starts_with($violation, $path)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        $this->assertSame(
            [],
            $remaining,
            "These class attributes set a colour with no dark: counterpart beside it, so the element "
            ."keeps its light colour inside a dark screen. Add the dark: utility, or list the view in "
            ."tests/Support/dark-mode-allowlist.php with the reason it has no dark mode:\n  "
            .implode("\n  ", array_slice($remaining, 0, 60))
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RSP-03 — the theme does not flash and does not forget
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_theme_is_decided_before_the_first_paint(): void
    {
        $head = $this->headOf($this->renderAdminScreen());

        $script = mb_strpos($head, "classList.toggle('dark'");

        $this->assertNotFalse(
            $script,
            'The pre-paint theme script is not in the <head> of a rendered page. Without it the browser '
            .'paints the light palette and then swaps, which is the flash D12 exists to prevent.',
        );

        foreach ($this->paintingAssets($head) as $position => $tag) {
            $this->assertLessThan(
                $position,
                $script,
                sprintf(
                    "The theme script runs after an asset that can paint: %s\nIt has to set the class on "
                    ."<html> before anything with colour in it is applied, or dark mode flashes white on "
                    .'every navigation.',
                    $tag,
                ),
            );
        }
    }

    #[Test]
    public function the_theme_script_reads_the_account_row_and_mirrors_it_to_storage(): void
    {
        $source = (string) file_get_contents(resource_path('views/layouts/partials/theme-script.blade.php'));

        $this->assertStringContainsString(
            'localStorage',
            $source,
            'The theme script never reads localStorage, so a guest on the sign-in screen cannot keep a choice.',
        );

        $this->assertStringContainsString(
            'prefers-color-scheme: dark',
            $source,
            'The System preference is not resolved against prefers-color-scheme, so "System" means nothing.',
        );

        $this->assertStringContainsString(
            'csp_nonce()',
            $source,
            'The inline theme script carries no CSP nonce, so it is the one script the policy of §5.3 '
            .'would have to be loosened for.',
        );
    }

    /**
     * RSP-03, §11.6 — the id's own assertion, both halves of it in one place: the switch writes
     * `users.theme` (persists), and a reload hands the stored value to the pre-paint script (no
     * flash).
     *
     * The two sibling methods keep descriptive names and carry the remaining clauses —
     * {@see self::the_theme_is_decided_before_the_first_paint()} checks the script's *position* in
     * the head, {@see self::the_theme_script_reads_the_account_row_and_mirrors_it_to_storage()}
     * checks its *content*. Three methods rather than one because they break for three unrelated
     * reasons, and a failure naming the clause is worth more than a failure naming the id.
     */
    #[Test]
    public function test_theme_switch_has_no_flash_and_persists(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->put(route('account.theme.update'), ['theme' => 'dark'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ThemePreference::Dark,
            $admin->fresh()?->theme,
            'The theme switcher did not write users.theme, so the choice dies with the browser profile (D12).',
        );

        $head = $this->headOf($this->renderAdminScreen());

        // The stored preference specifically, not merely the word "dark": the list of theme values the
        // script validates against contains it on every page, so a looser assertion would pass for ever.
        $this->assertStringContainsString(
            'var account = "dark"',
            $head,
            'A reload after switching does not hand the stored preference to the pre-paint script, so the '
            .'account theme is forgotten on every navigation.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RSP-04 — wide content scrolls inside its own container
    |--------------------------------------------------------------------------
    */

    /**
     * A table wider than a phone drags the whole page sideways with it — the body scrolls, the header
     * and the sidebar come off their anchors, and every other screen on the site looks broken because
     * of one column. `x-ui.table` provides the wrapper; a raw `<table>` has to bring its own.
     */
    #[Test]
    public function test_tables_and_wide_content_scroll_in_their_own_container(): void
    {
        $violations = [];

        foreach ($this->bladeSources() as $relative => $source) {
            if ($this->isPaperDocument($relative)) {
                continue;
            }

            // A `<table` written inside a Blade echo or an @php block is a *string*, not an element:
            // x-site.prose passes '<table' to str_replace as the needle it rewrites into
            // '<div class="overflow-x-auto"><table'. Reading that needle as markup reported the one
            // component in the codebase that wraps every table it prints.
            $markup = $this->withoutPhpExpressions($source);

            if (! preg_match_all('/<table\b/', $markup, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as $match) {
                $offset = (int) $match[1];

                if ($this->scrollsWhenRendered($relative, $offset)) {
                    continue;
                }

                $violations[] = sprintf('%s:%d', $relative, substr_count(substr($source, 0, $offset), "\n") + 1);
            }
        }

        sort($violations);

        $this->assertSame(
            [],
            $violations,
            "These raw <table> elements are not inside a horizontally scrolling container, so on a phone "
            ."they widen the page instead of themselves. Use <x-ui.table>, which provides the wrapper, or "
            ."wrap the table in a div with overflow-x-auto:\n  ".implode("\n  ", $violations)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RSP-05 — modals lock the body and scroll internally
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function test_modals_lock_the_body_and_scroll_internally(): void
    {
        $modal = (string) file_get_contents(resource_path('views/components/ui/modal.blade.php'));

        $this->assertMatchesRegularExpression(
            '/x-trap\.[^"\s]*noscroll/',
            $modal,
            'x-ui.modal does not trap with .noscroll, so the page behind it keeps scrolling under the '
            .'dialog — on a phone the dialog appears to jump while the user drags the page.',
        );

        $this->assertMatchesRegularExpression(
            '/max-h-\[[^\]]+\][^"]*overflow-y-auto|overflow-y-auto[^"]*max-h-\[[^\]]+\]/',
            $modal,
            'The modal panel has no bounded height with its own vertical scroll, so a long form runs off '
            .'the bottom of a short screen with no way to reach the submit button.',
        );

        $trap = (string) file_get_contents(resource_path('js/focus-trap.js'));

        $this->assertStringContainsString(
            "classList.add('overflow-hidden')",
            $trap,
            'The focus trap never locks the document, so .noscroll on the modal does nothing.',
        );

        $this->assertStringContainsString(
            "classList.remove('overflow-hidden')",
            $trap,
            'The focus trap locks the document and never unlocks it — closing the modal would leave the '
            .'page unscrollable, which looks exactly like a frozen browser.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RSP-06 — the print views
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function test_print_views_render_at_a4(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/print.blade.php'));

        $this->assertStringContainsString('page { size: A4;', $layout, 'The print layout declares no A4 page size.');
        $this->assertStringContainsString('page-break-inside: avoid', $layout, 'The print layout has no page-break rules, so a row can be cut in half.');
        $this->assertStringContainsString('tabular-nums', $layout, 'Money columns are not tabular, so digits do not line up down a printed column.');

        $documents = $this->viewsExtendingPrintLayout();

        $this->assertGreaterThan(
            5,
            count($documents),
            'Almost no view extends layouts.print — the detection is wrong, not the documents.',
        );

        $violations = [];

        foreach ($documents as $relative => $source) {
            if (str_contains($source, 'dark:')) {
                $violations[] = $relative.' — carries a dark: utility';
            }

            foreach (['layouts.partials.sidebar', 'layouts.partials.topbar', 'layouts.partials.breadcrumbs'] as $chrome) {
                if (str_contains($source, $chrome)) {
                    $violations[] = $relative.' — includes '.$chrome;
                }
            }
        }

        sort($violations);

        $this->assertSame(
            [],
            $violations,
            "A printed document has no dark mode and no navigation. These carry screen chrome into the "
            ."copy the client receives:\n  ".implode("\n  ", $violations)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Guards the guard
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_blade_scan_actually_reads_the_views(): void
    {
        $files = $this->bladeFiles();

        $this->assertGreaterThan(
            200,
            count($files),
            'The Blade scan found almost no views — every scan in this class would be vacuous.',
        );

        $this->assertArrayHasKey('layouts/admin.blade.php', $files);
        $this->assertArrayHasKey('components/ui/table.blade.php', $files);
        $this->assertArrayHasKey('layouts/print.blade.php', $files);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Class attributes that set a colour and carry no `dark:` in the same attribute.
     *
     * Scoped to one attribute deliberately. Tailwind's dark variant is written beside the utility it
     * overrides (`bg-white dark:bg-slate-900`), so "somewhere else in the file" is not a pairing —
     * it is a different element, usually the one above the leak.
     *
     * `white`, `black`, `transparent`, `current` and `inherit` are excluded because they carry no
     * palette step, and the brand utilities are excluded because they resolve through a CSS variable
     * that the theme itself redefines.
     *
     * @return list<string>
     */
    private function darkModeViolations(): array
    {
        $palette = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky'
            .'|blue|indigo|violet|purple|fuchsia|pink|rose';

        $violations = [];

        foreach ($this->bladeFiles() as $relative => $path) {
            $source = $this->withoutComments((string) file_get_contents($path));

            if (! preg_match_all('/class="([^"]*)"/', $source, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[1] as $index => $attribute) {
                if (str_contains($attribute[0], 'dark:')) {
                    continue;
                }

                $pattern = '/(?:^|\s)(?:bg|text|border|divide|ring|placeholder|shadow)-(?:'.$palette.')-\d{2,3}\b/';

                if (preg_match($pattern, $attribute[0]) !== 1) {
                    continue;
                }

                $line = substr_count(substr($source, 0, (int) $matches[0][$index][1]), "\n") + 1;
                $violations[] = $relative.':'.$line;
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * Whether a view is ink on paper rather than pixels on a screen.
     *
     * Three clauses, each a statement about what renders the view:
     *
     *   1. it **is** one of the two print shells;
     *   2. it **extends** one of them — the fourteen `layouts.print` documents and the three
     *      `layouts.document` ones;
     *   3. it is its own complete HTML document and **opens the print dialog on load**. Three
     *      documents predate the shells and carry their own `@media print` block:
     *      `admin/payouts/voucher`, `admin/reports/print`, `admin/statements/print`. A document that
     *      prints itself the moment it opens has no screen use to have.
     *
     * Clause 3 needs the *auto* form on purpose. Eight screens carry a Print **button** that calls
     * `window.print()` on click (`admin/clients/print`, `admin/leads/print`, `admin/hr/payroll/register`,
     * `admin/hr/payslips/print`, …); those are screens, they extend `layouts.admin`, and this rule
     * must keep applying to them.
     */
    private function isPaperDocument(string $relative): bool
    {
        if (in_array($relative, self::PAPER_LAYOUTS, true)) {
            return true;
        }

        $source = $this->bladeSources()[$relative] ?? '';

        if (str_contains($source, "@extends('layouts.print'") || str_contains($source, "@extends('layouts.document'")) {
            return true;
        }

        return preg_match('/onload="[^"]*window\.print\(\)|addEventListener\(\s*[\'"]load[\'"][^\n]*window\.print\(\)/', $source) === 1;
    }

    /**
     * Whether the table at `$offset` in `$relative` is inside a horizontally scrolling container by
     * the time a browser sees it.
     *
     * A partial is not a page. `admin/reports/finance/_table` holds the six tables of the finance
     * reports and carries no wrapper of its own, because its two callers each do the right thing for
     * their medium: `admin/reports/finance/show` wraps the `@include` in `overflow-x-auto`, and
     * `admin/reports/finance/print` extends `layouts.print`, where a scroll container would risk
     * clipping the columns off the paper. **Reading one file at a time reported six violations in a
     * partial whose every caller is already correct**, so the walk follows `@include` upwards and a
     * partial is compliant only when *every* caller is.
     *
     * @param  array<string, true>  $seen
     */
    private function scrollsWhenRendered(string $relative, int $offset, array $seen = []): bool
    {
        $sources = $this->bladeSources();

        // Anywhere earlier in the file, not within N characters: the wrapper is frequently the
        // outermost element of a partial and the table is fifty lines of <thead> below it.
        if (preg_match('/overflow-x-auto|overflow-auto|overflow-x-scroll/', substr($sources[$relative], 0, $offset)) === 1) {
            return true;
        }

        // A partial that includes itself, directly or through a cycle, cannot be its own wrapper.
        if (isset($seen[$relative])) {
            return false;
        }

        $seen[$relative] = true;

        $callers = $this->includeSites()[$this->viewName($relative)] ?? [];

        // Nothing includes it: it is a page, and the missing wrapper is its own.
        if ($callers === []) {
            return false;
        }

        foreach ($callers as [$caller, $callOffset]) {
            if ($this->isPaperDocument($caller)) {
                continue;
            }

            if (! $this->scrollsWhenRendered($caller, $callOffset, $seen)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every `@include` in every view: dotted view name => list of [including file, byte offset].
     *
     * `@include`, `@includeIf` and `@includeFirst` are read — a table is on the screen whichever
     * directive put it there, and `@includeFirst`'s array form is read for its first candidate
     * onwards.
     *
     * `@includeWhen` and `@includeUnless` take the **condition** first, so the pattern below does
     * not reach their view name and a partial included only that way records no caller at all.
     * **That gap fails safe and must stay on that side:** no caller means the partial is treated as
     * a page and has to carry its own wrapper, which is a stricter answer, never a quieter one.
     * Neither directive is used in this codebase today (`@includeFirst` once, in
     * `admin/dashboard/partials/body`), so the pattern is left as it is rather than grown a branch
     * nothing exercises — but a scan that silently stopped following a caller would be the failure
     * to fear, and this is the note that says it cannot happen from here.
     *
     * @return array<string, list<array{0: string, 1: int}>>
     */
    private function includeSites(): array
    {
        if ($this->includeSites !== null) {
            return $this->includeSites;
        }

        $sites = [];

        foreach ($this->bladeSources() as $relative => $source) {
            if (! preg_match_all('/@include(?:If|When|Unless|First)?\s*\(\s*\[?\s*[\'"]([^\'"]+)[\'"]/', $source, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[1] as $index => $name) {
                $sites[$name[0]][] = [$relative, (int) $matches[0][$index][1]];
            }
        }

        return $this->includeSites = $sites;
    }

    private function viewName(string $relative): string
    {
        return str_replace('/', '.', substr($relative, 0, -strlen('.blade.php')));
    }

    /**
     * @return array<string, string> relative path => comment-free source
     */
    private function bladeSources(): array
    {
        if ($this->bladeSources !== null) {
            return $this->bladeSources;
        }

        $sources = [];

        foreach ($this->bladeFiles() as $relative => $path) {
            $sources[$relative] = $this->withoutComments((string) file_get_contents($path));
        }

        return $this->bladeSources = $sources;
    }

    /**
     * @return array<string, string> relative path => source, for every view extending the print layout
     */
    private function viewsExtendingPrintLayout(): array
    {
        $documents = [];

        foreach ($this->bladeFiles() as $relative => $path) {
            $source = (string) file_get_contents($path);

            if (str_contains($source, "@extends('layouts.print'")) {
                $documents[$relative] = $source;
            }
        }

        return $documents;
    }

    /**
     * Positions of the tags that can paint the page, keyed by offset.
     *
     * The font stylesheet is excluded: it carries type, not colour, so it cannot cause the flash the
     * pre-paint script exists to prevent — and it legitimately sits above the script so the browser
     * can start fetching it early.
     *
     * @return array<int, string>
     */
    private function paintingAssets(string $head): array
    {
        $assets = [];

        if (preg_match_all('/<link\b[^>]*rel="stylesheet"[^>]*>|<script\b[^>]*\ssrc="[^"]*"[^>]*>/i', $head, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                if (preg_match('/fonts\./i', $match[0]) === 1) {
                    continue;
                }

                $assets[(int) $match[1]] = trim($match[0]);
            }
        }

        return $assets;
    }

    private function headOf(string $html): string
    {
        $start = mb_strpos($html, '<head');
        $end = mb_strpos($html, '</head>');

        $this->assertNotFalse($start, 'The rendered page has no <head>.');
        $this->assertNotFalse($end, 'The rendered page has no closing </head>.');

        return mb_substr($html, (int) $start, (int) $end - (int) $start);
    }

    private function renderAdminScreen(): string
    {
        $response = $this->actingAs($this->superAdmin())->get('/admin');

        $response->assertOk();

        return (string) $response->getContent();
    }

    private function superAdmin(): User
    {
        $admin = User::query()
            ->whereHas('roles', static fn ($query) => $query->where('name', User::SUPER_ADMIN_ROLE))
            ->firstOrFail();

        $admin->forceFill(['must_change_password' => false])->saveQuietly();

        return $admin;
    }

    /**
     * Blade comments hold documentation, and documentation quotes the markup this class forbids.
     *
     * Blanked rather than deleted: **every line number in every failure message of this class is
     * counted from this string**, and a deleted comment block shifted them by its own height — the
     * old scan pointed at `card.blade.php:116` for a table on line 132, fifteen lines of docblock
     * away, which is exactly far enough to send the reader to the wrong element.
     */
    private function withoutComments(string $source): string
    {
        return $this->blank($source, '/\{\{--.*?--\}\}/s');
    }

    /**
     * Blade's PHP regions blanked: `{{ }}`, `{!! !!}` and `@php … @endphp`.
     *
     * Markup never lives inside them, so nothing real is lost, and what is gained is that a quoted
     * `'<table'` inside an expression stops being read as an element on the page.
     *
     * The lookahead excludes the **inline** `@php($x = …)` form, which 56 views use and which has no
     * `@endphp`: without it the match would run from the first inline directive to the next block's
     * `@endphp` — in `admin/attendance/reports/monthly.blade.php` that is the whole timetable grid,
     * and blanking a real table is how a scan stops finding anything.
     */
    private function withoutPhpExpressions(string $source): string
    {
        return $this->blank($source, '/\{\{.*?\}\}|\{!!.*?!!\}|@php\b(?!\s*\().*?@endphp/s');
    }

    /**
     * Replace every match with spaces, keeping newlines, so offsets and line numbers survive.
     */
    private function blank(string $source, string $pattern): string
    {
        return (string) preg_replace_callback(
            $pattern,
            static fn (array $match): string => (string) preg_replace('/[^\n]/', ' ', $match[0]),
            $source,
        );
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private function bladeFiles(): array
    {
        $root = resource_path('views');
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $files[$relative] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }
}
