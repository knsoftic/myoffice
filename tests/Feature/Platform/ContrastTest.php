<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Contrast;
use Tests\TestCase;

/**
 * A11Y-CONTRAST — every documented colour pair reaches its WCAG threshold (phase-24-25 §6.5, §11).
 *
 * **This is the accessibility rule most likely to be broken by a change nobody thinks of as an
 * accessibility change.** A brand colour nudged two shades lighter, a "muted" token reused on a card
 * instead of on the page — nobody notices, because the person choosing the colour can read it. The
 * pairs file is the design system's own list of what it renders, and this test is what turns a
 * palette edit into a failing build instead of an unreadable status chip.
 *
 * The maths is checked too. A contrast function that is subtly wrong passes everything, which is
 * worse than one that is obviously wrong.
 */
final class ContrastTest extends TestCase
{
    /**
     * The database is irrelevant here; skipping the seed keeps this suite fast enough to run on
     * every commit, which is the point of a regression guard.
     *
     * @var bool
     */
    protected $seed = false;

    #[Test]
    public function every_documented_pair_reaches_its_threshold(): void
    {
        $audit = Contrast::auditPairs();

        $this->assertGreaterThan(
            100,
            $audit['checked'],
            'contrast-pairs.php should cover the whole palette; it covered '.$audit['checked'].' pairs.',
        );

        $this->assertSame(
            [],
            $audit['failures'],
            "These rendered colour pairs are below their WCAG threshold:\n  "
            .implode("\n  ", $audit['failures']),
        );
    }

    #[Test]
    public function the_ratio_matches_the_wcag_reference_values(): void
    {
        // The two ends of the scale, which pin the formula's constants.
        $this->assertSame(21.0, round(Contrast::ratio('#000000', '#ffffff'), 2));
        $this->assertSame(1.0, round(Contrast::ratio('#ffffff', '#ffffff'), 2));

        // A mid grey, which pins the 2.4 exponent — a `pow($c, 2.2)` approximation gives 4.53 here.
        $this->assertSame(4.48, round(Contrast::ratio('#777777', '#ffffff'), 2));

        // Pure black must be exactly zero luminance, and only the piecewise linear segment gives
        // that: the power form alone returns ((0 + 0.055) / 1.055) ^ 2.4 = 0.0003, which would make
        // black-on-white 20.4:1 instead of 21:1 and shift every dark-background ratio with it.
        $this->assertSame(0.0, Contrast::luminance('#000000'));
        $this->assertSame(1.0, Contrast::luminance('#ffffff'));
    }

    #[Test]
    public function the_ratio_is_symmetric_and_accepts_short_hex(): void
    {
        $this->assertSame(
            Contrast::ratio('#ffffff', '#000000'),
            Contrast::ratio('#000000', '#ffffff'),
            'Contrast has no direction — the lighter colour is found, not assumed.',
        );

        $this->assertSame(
            Contrast::ratio('#ffffff', '#000000'),
            Contrast::ratio('#fff', '#000'),
        );

        $this->assertSame(
            Contrast::ratio('#ffffff', '#000000'),
            Contrast::ratio('ffffff', '000000'),
            'A missing hash is a typo, not a different colour.',
        );
    }

    #[Test]
    public function a_translucent_surface_is_measured_as_what_it_becomes(): void
    {
        // Half-opacity white over black is mid grey. If this were gamma-corrected it would not be,
        // and it would not match what a browser paints either.
        $this->assertSame('#808080', Contrast::composite('#ffffff', 0.5, '#000000'));

        $this->assertSame('#ff0000', Contrast::composite('#ff0000', 1.0, '#00ff00'));
        $this->assertSame('#00ff00', Contrast::composite('#ff0000', 0.0, '#00ff00'));

        $this->assertSame(
            Contrast::composite('#10b981', 0.10, '#0f172a'),
            Contrast::resolve('composite:#10b981:0.10:#0f172a'),
        );

        $this->assertSame('#123456', Contrast::resolve('#123456'));
    }

    #[Test]
    public function the_solid_badge_uses_the_measured_shades(): void
    {
        $badge = (string) file_get_contents(
            base_path('resources/views/components/ui/badge.blade.php')
        );

        // `bg-{token}-600 text-white` failed AA on thirteen of fifteen tokens, and `-500` with
        // white reached 1.92:1 on yellow. These are the shades that measure clean.
        foreach (['amber', 'yellow', 'emerald', 'sky', 'teal', 'lime', 'green', 'orange', 'cyan'] as $token) {
            $this->assertStringContainsString(
                'bg-'.$token.'-700 text-white',
                $badge,
                sprintf('The solid %s badge should be -700 with white text (4.92:1 at worst).', $token),
            );

            $this->assertStringContainsString(
                'dark:bg-'.$token.'-400 dark:text-slate-950',
                $badge,
                sprintf('The dark-mode solid %s badge should be -400 with dark text (6.76:1 at worst).', $token),
            );
        }

        $this->assertStringNotContainsString(
            'bg-yellow-500 text-white',
            $badge,
            'White on yellow-500 is 1.92:1 — not low contrast, unreadable.',
        );
    }
}
