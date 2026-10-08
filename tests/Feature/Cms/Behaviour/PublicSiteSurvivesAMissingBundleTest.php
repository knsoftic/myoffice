<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * A public page that cannot get its JavaScript must still be a page.
 *
 * `site/partials/fx-script.blade.php` puts `.fx-on` on `<html>` in the head, and every entrance effect
 * in `app.css` is scoped to that class — `.fx-on [data-fx] { opacity: 0 }`. The reveal that undoes it
 * lives in `scroll-fx.js`, inside the module bundle.
 *
 * The gate's own docblock claimed that "if this file never loads, or throws on its first line, the page
 * is simply the page". **That was not true**, and the difference is the whole marketing site. The inline
 * script proves JavaScript is enabled *in the head*; it proves nothing about whether the bundle will
 * arrive. A 404, a blocked CDN, or a throw anywhere before `registerScrollFx()` left every `[data-fx]`
 * element at `opacity: 0` for ever.
 *
 * Measured, by renaming the built `app-*.js` and loading the home page in a browser: **30 of 30
 * `[data-fx]` elements invisible**, and the page rendered its header and nothing else — no heading, no
 * copy, no call to action. And it came within one file of being real: the deploy that prompted this work
 * shipped a `public/build` whose *stylesheet* 404'd (T69). Had the same broken build dropped the script
 * instead, knsoftic.com would have served an empty page. After the fix, the same test renders the
 * complete page and 0 of 30 elements are hidden.
 *
 * **Why this test reads source rather than driving a browser.** The failure only exists in a real
 * browser with a real failed request, which PHPUnit cannot stage; the project's browser specs are §12 Q3
 * work that does not exist yet. So this asserts the two halves of the contract are both present and
 * still refer to each other. It cannot prove the timing is right — but it fails the moment somebody
 * deletes one half, which is the realistic way this regresses: the failsafe looks like dead code to
 * anyone who does not know what it is for.
 */
final class PublicSiteSurvivesAMissingBundleTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    private const GATE = 'resources/views/site/partials/fx-script.blade.php';

    private const REVEAL = 'resources/js/scroll-fx.js';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    /**
     * The gate must set a deadline on the hidden state, and hand out the way to cancel it.
     */
    #[Test]
    public function the_effects_gate_expires_unless_the_bundle_claims_it(): void
    {
        $gate = File::get(base_path(self::GATE));

        $this->assertStringContainsString(
            "classList.add('fx-on')",
            $gate,
            'The gate no longer sets `.fx-on`, so this test is measuring something that moved.',
        );

        $this->assertStringContainsString(
            "classList.remove('fx-on')",
            $gate,
            'The gate hides the page without any way for that to expire. If the module bundle 404s or '
                .'throws, every [data-fx] element stays at opacity 0 and the public site is blank.',
        );

        $this->assertStringContainsString(
            'setTimeout',
            $gate,
            'The removal is not on a timer, so nothing un-hides the page when the bundle never arrives.',
        );

        $this->assertStringContainsString(
            '__fxReady',
            $gate,
            'The gate exposes no way for the bundle to cancel the failsafe, so the effects would be '
                .'switched off on every page even when JavaScript is working.',
        );
    }

    /**
     * And the bundle must actually claim it — before anything that could throw.
     */
    #[Test]
    public function the_reveal_module_cancels_the_failsafe_before_it_can_fail(): void
    {
        $reveal = File::get(base_path(self::REVEAL));

        $this->assertStringContainsString(
            '__fxReady',
            $reveal,
            'scroll-fx.js never cancels the gate\'s failsafe, so the entrance effects will be torn down '
                .'mid-page on every slow load.',
        );

        // Position matters as much as presence: a cancel placed after the observer setup would be
        // skipped by exactly the failures it exists to survive.
        $guard = strpos($reveal, "classList.contains('fx-on')");
        $claim = strpos($reveal, '__fxReady');
        $observer = strpos($reveal, 'IntersectionObserver(');

        $this->assertIsInt($guard);
        $this->assertIsInt($claim);
        $this->assertIsInt($observer);

        $this->assertGreaterThan(
            $guard,
            $claim,
            'The failsafe is cancelled before the module has checked the gate is even on.',
        );

        $this->assertLessThan(
            $observer,
            $claim,
            'The failsafe is cancelled after the observers are built. Anything that throws in between '
                .'leaves the page hidden with its only rescue already called off.',
        );
    }

    /**
     * The page's largest text must not be waiting on JavaScript to become visible.
     *
     * Largest Contentful Paint is when the largest element *becomes visible*, so an `[data-fx]` heading
     * starting at `opacity: 0` does not count until the bundle has downloaded, parsed, started Alpine
     * and fired an observer. `data-fx-lcp` keeps the rise and drops the fade, so the heading paints with
     * the HTML.
     */
    #[Test]
    public function every_public_h1_that_animates_paints_immediately(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views/site')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (! preg_match_all('/<h1\b[^>]*>/i', $file->getContents(), $matches)) {
                continue;
            }

            foreach ($matches[0] as $tag) {
                if (str_contains($tag, 'data-fx=') && ! str_contains($tag, 'data-fx-lcp')) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            "These public <h1> elements animate in without `data-fx-lcp`, so the page's largest text "
                ."starts invisible and Largest Contentful Paint waits for the JavaScript bundle:\n  - "
                .implode("\n  - ", array_unique($offenders)),
        );
    }

    /**
     * The paragraph under each page heading is an LCP candidate too.
     *
     * Exempting only the `<h1>` fixed desktop and missed mobile. On a 375px phone the hero subtitle
     * wraps to more lines than the heading and becomes the Largest Contentful Paint element — measured
     * on the live home page at FCP 176 ms, LCP 592 ms, the subtitle, still fading in behind the bundle.
     * A test that only looked at `<h1>` passed the whole time, which is why this one names the four
     * standfirsts explicitly rather than trusting a pattern to find them.
     */
    #[Test]
    public function the_standfirst_under_each_page_heading_paints_immediately(): void
    {
        $views = [
            'resources/views/site/sections/hero.blade.php',
            'resources/views/site/marketing/partials/page-hero.blade.php',
            'resources/views/site/courses/index.blade.php',
            'resources/views/site/courses/show.blade.php',
        ];

        $missing = [];

        foreach ($views as $view) {
            $source = File::get(base_path($view));

            // Anchor on the animated heading *tag*, not the first "<h1" in the file: every one of
            // these views opens with a docblock that mentions "<h1>" in prose, and anchoring there
            // made the first draft of this test pick up page-hero's eyebrow — a <p> that sits above
            // the real heading — and report the wrong element.
            $heading = preg_match('/<h1\b[^>]*\bdata-fx=/', $source, $h1, PREG_OFFSET_CAPTURE) === 1
                ? $h1[0][1]
                : false;

            // The first animated paragraph after that heading is its standfirst. `<p\b`, not `<p`,
            // so `<picture` and `<path` cannot match. Only the attributes before the first `>` are
            // captured; in a tag carrying `@class([...])` that stops at the first `=>`, which comes
            // after `data-fx-lcp` in every one of these views.
            $found = $heading !== false
                && preg_match('/<p\b[^>]*\bdata-fx=[^>]*/', $source, $match, 0, $heading) === 1;

            if (! $found) {
                $missing[] = $view.' (no animated paragraph after the <h1> — the view changed shape)';

                continue;
            }

            if (! str_contains($match[0], 'data-fx-lcp')) {
                $missing[] = $view;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "The paragraph under the page heading fades in without `data-fx-lcp`. On a phone it is "
                ."often the largest text on screen, so LCP waits for the JavaScript bundle:\n  - "
                .implode("\n  - ", $missing),
        );
    }

    /** The stylesheet half of `data-fx-lcp`: without this rule the attribute is decoration. */
    #[Test]
    public function the_lcp_exemption_exists_in_the_stylesheet(): void
    {
        $css = File::get(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\.fx-on\s+\[data-fx\]\[data-fx-lcp\]\s*\{[^}]*opacity:\s*1/s',
            $css,
            '`data-fx-lcp` is used in the views but nothing in app.css acts on it, so every heading '
                .'carrying it is still hidden until the bundle runs.',
        );
    }
}
