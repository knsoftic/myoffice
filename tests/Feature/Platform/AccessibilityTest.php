<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\A11y;
use Tests\TestCase;

/**
 * A11Y-01..A11Y-14 — the accessibility rules a machine can decide (phase-24-25 §6.5, §11).
 *
 * Two halves, and the first is not ceremony.
 *
 * **An assertion that never fails is not an assertion.** `assertEveryInputLabelled` walking a page
 * and finding nothing wrong is the same output whether the page is perfect or the XPath is broken,
 * and a broken accessibility checker is worse than none — it reports a clean bill on a page nobody
 * can use. So each assertion is shown failing on markup that should fail it, then accepting the
 * corrected markup.
 *
 * **The second half runs them on real screens**, chosen as a spread rather than exhaustively: the
 * public site, the sign-in page, two indexes, a form, a settings tab and a report hub. The full
 * sweep over every screen in the manifest is A11Y-SWEEP and runs with `a11y:scan`; this is the one
 * that runs on every commit.
 */
final class AccessibilityTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | The checker catches what it claims to
    |--------------------------------------------------------------------------
    */

    /**
     * @return iterable<string, array{0: callable, 1: string, 2: string}>
     */
    public static function assertionProvider(): iterable
    {
        $page = static fn (string $body, string $head = '<title>A page</title>'): string => sprintf(
            '<!DOCTYPE html><html lang="en"><head>%s</head><body>%s</body></html>',
            $head,
            $body,
        );

        yield 'a second h1' => [
            static fn (string $html) => A11y::assertSingleH1($html),
            $page('<h1>One</h1><h1>Two</h1>'),
            $page('<h1>One</h1>'),
        ];

        yield 'a skipped heading level' => [
            static fn (string $html) => A11y::assertHeadingOrder($html),
            $page('<h2>A</h2><h4>B</h4>'),
            $page('<h2>A</h2><h3>B</h3>'),
        ];

        yield 'a missing lang attribute' => [
            static fn (string $html) => A11y::assertHtmlLang($html),
            '<html><head><title>x</title></head><body></body></html>',
            $page(''),
        ];

        yield 'an empty title' => [
            static fn (string $html) => A11y::assertUniqueTitle($html),
            $page('', '<title>   </title>'),
            $page(''),
        ];

        yield 'two main landmarks' => [
            static fn (string $html) => A11y::assertLandmarks($html),
            $page('<header></header><nav></nav><main>a</main><main>b</main><footer></footer>'),
            $page('<header></header><nav></nav><main>a</main><footer></footer>'),
        ];

        yield 'an unlabelled input' => [
            static fn (string $html) => A11y::assertEveryInputLabelled($html),
            $page('<input type="text" name="q">'),
            $page('<label for="q">Search</label><input type="text" id="q" name="q">'),
        ];

        yield 'an icon-only button with no name' => [
            static fn (string $html) => A11y::assertIconButtonsLabelled($html),
            $page('<button><svg></svg></button>'),
            $page('<button aria-label="Delete"><svg></svg></button>'),
        ];

        yield 'an uncaptioned data table' => [
            static fn (string $html) => A11y::assertTablesCaptioned($html),
            $page('<table><tr><td>1</td></tr></table>'),
            $page('<table><caption>Clients</caption><tr><td>1</td></tr></table>'),
        ];

        yield 'an invalid field with no description' => [
            static fn (string $html) => A11y::assertErrorsAssociated($html),
            $page('<input aria-invalid="true" id="e">'),
            $page('<input aria-invalid="true" aria-describedby="err" id="e"><p id="err">Required</p>'),
        ];

        yield 'a chart with no text alternative' => [
            static fn (string $html) => A11y::assertChartHasTextAlternative($html),
            $page('<div><canvas></canvas></div>'),
            $page('<div><canvas></canvas><table class="sr-only"><tr><td>1</td></tr></table></div>'),
        ];

        yield 'a positive tabindex' => [
            static fn (string $html) => A11y::assertNoPositiveTabIndex($html),
            $page('<a href="#x" tabindex="3">x</a>'),
            $page('<a href="#x" tabindex="0">x</a>'),
        ];

        yield 'a skip link pointing at nothing' => [
            static fn (string $html) => A11y::assertSkipLink($html),
            $page('<a href="#content">Skip to content</a><main>x</main>'),
            $page('<a href="#content">Skip to content</a><main id="content">x</main>'),
        ];

        yield 'an aria-live value that announces nothing' => [
            static fn (string $html) => A11y::assertLiveRegions($html),
            $page('<div aria-live="loud"></div>'),
            $page('<div aria-live="polite"></div>'),
        ];
    }

    #[Test]
    #[DataProvider('assertionProvider')]
    public function the_checker_rejects_bad_markup_and_accepts_the_fix(
        callable $assertion,
        string $bad,
        string $good,
    ): void {
        $this->assertNotNull(
            $this->failureOf(static fn () => $assertion($bad)),
            'This markup should have been rejected and was not — the check does nothing.',
        );

        $message = $this->failureOf(static fn () => $assertion($good));

        $this->assertNull(
            $message,
            'The corrected markup was rejected too: '.(string) $message,
        );
    }

    #[Test]
    public function all_four_ways_of_naming_a_control_count(): void
    {
        foreach ([
            'label[for]' => '<label for="a">A</label><input id="a">',
            'a wrapping label' => '<label>A <input></label>',
            'aria-label' => '<input aria-label="A">',
            'aria-labelledby' => '<span id="lbl">A</span><input aria-labelledby="lbl">',
        ] as $how => $markup) {
            $this->assertNull(
                $this->failureOf(static fn () => A11y::assertEveryInputLabelled(self::wrap($markup))),
                $how.' should name a control.',
            );
        }

        // A label that points at nothing is the shape a rename leaves behind, and it announces
        // nothing at all — so it must not count.
        $this->assertNotNull(
            $this->failureOf(static fn () => A11y::assertEveryInputLabelled(
                self::wrap('<input aria-labelledby="gone">')
            )),
            'aria-labelledby pointing at a missing id is not a name.',
        );
    }

    #[Test]
    public function controls_that_need_no_name_are_skipped(): void
    {
        foreach ([
            'a hidden input' => '<input type="hidden" name="_token" value="x">',
            'a submit button' => '<input type="submit" value="Save">',
            'a decorative control' => '<button aria-hidden="true"><svg></svg></button>',
            'a presentational table' => '<table role="presentation"><tr><td>x</td></tr></table>',
        ] as $what => $markup) {
            $html = self::wrap($markup);

            $this->assertNull($this->failureOf(static fn () => A11y::assertEveryInputLabelled($html)), $what);
            $this->assertNull($this->failureOf(static fn () => A11y::assertIconButtonsLabelled($html)), $what);
            $this->assertNull($this->failureOf(static fn () => A11y::assertTablesCaptioned($html)), $what);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Real screens
    |--------------------------------------------------------------------------
    */

    /**
     * Screens a signed-in member of staff sees.
     *
     * `/login` is **not** here: an authenticated request to it is redirected away, which is the
     * correct behaviour and not something to assert accessibility against. It is covered by
     * {@see self::a_guest_screen_meets_the_machine_checkable_rules()} instead.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function screenProvider(): iterable
    {
        yield 'the admin dashboard' => ['/admin'];
        yield 'an index screen' => ['/admin/clients'];
        yield 'a form screen' => ['/admin/clients/create'];
        yield 'the users index' => ['/admin/users'];
        yield 'the operations settings tab' => ['/admin/settings/ops'];
        yield 'the reports hub' => ['/admin/reports'];
    }

    /**
     * Screens nobody has signed in for.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function guestScreenProvider(): iterable
    {
        yield 'the public home page' => ['/'];
        yield 'the sign-in screen' => ['/login'];
        yield 'the password reset request' => ['/forgot-password'];
    }

    #[Test]
    #[DataProvider('guestScreenProvider')]
    public function a_guest_screen_meets_the_machine_checkable_rules(string $uri): void
    {
        $response = $this->get($uri);

        $response->assertOk();

        $this->assertAccessible((string) $response->getContent(), $uri);
    }

    #[Test]
    #[DataProvider('screenProvider')]
    public function a_rendered_screen_meets_the_machine_checkable_rules(string $uri): void
    {
        $admin = User::query()
            ->whereHas('roles', static fn ($query) => $query->where('name', 'Super Admin'))
            ->firstOrFail();

        $admin->forceFill(['must_change_password' => false])->saveQuietly();

        $response = $this->actingAs($admin)->get($uri);

        $response->assertOk();

        $this->assertAccessible((string) $response->getContent(), $uri);
    }

    /**
     * Every machine-checkable rule, against one rendered page.
     */
    private function assertAccessible(string $html, string $uri): void
    {
        $this->assertNotSame('', $html, $uri.' rendered nothing.');

        A11y::assertHtmlLang($html, $uri);
        A11y::assertUniqueTitle($html, $uri);
        A11y::assertSingleH1($html, $uri);
        A11y::assertHeadingOrder($html, $uri);
        A11y::assertEveryInputLabelled($html, $uri);
        A11y::assertIconButtonsLabelled($html, $uri);
        A11y::assertErrorsAssociated($html, $uri);
        A11y::assertChartHasTextAlternative($html, $uri);
        A11y::assertNoPositiveTabIndex($html, $uri);

        // `requireToast: false` — a live region is only expected where feedback can appear, and a
        // static page has none. The call still rejects an `aria-live` value that announces nothing.
        A11y::assertLiveRegions($html, $uri, false);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Run an assertion and return its failure message, or null when it passed.
     */
    private function failureOf(callable $assertion): ?string
    {
        try {
            $assertion();

            return null;
        } catch (AssertionFailedError $error) {
            return $error->getMessage();
        }
    }

    private static function wrap(string $body): string
    {
        return '<!DOCTYPE html><html lang="en"><head><title>A page</title></head><body>'
            .$body
            .'</body></html>';
    }
}
