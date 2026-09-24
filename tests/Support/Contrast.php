<?php

declare(strict_types=1);

namespace Tests\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Assert;

/**
 * WCAG contrast, in pure PHP (phase-24-25 §6.5).
 *
 * **A palette change fails CI instead of failing a user.** Contrast is the accessibility rule most
 * likely to be broken by a change nobody thinks of as an accessibility change — a brand colour
 * nudged two shades lighter, a "muted" token reused on a card instead of on the page background.
 * Nobody notices, because the person choosing the colour can read it.
 *
 * The maths is the WCAG 2.1 relative-luminance formula and it is not an approximation: sRGB
 * channels are linearised (the `^2.4` curve, not a simple square), weighted 0.2126 / 0.7152 /
 * 0.0722, and the ratio is `(lighter + 0.05) / (darker + 0.05)`. The 0.05 is the flare term that
 * stops two near-blacks reporting an enormous ratio.
 *
 * Thresholds, from WCAG 2.1 AA:
 *
 *   · **4.5** — body text
 *   · **3.0** — large text (18.66px bold, or 24px), and non-text UI such as a border or an icon
 *   · **7.0** — AAA body text, which this project does not require anywhere
 */
final class Contrast
{
    /** WCAG 2.1 AA, body text. */
    public const AA_TEXT = 4.5;

    /** WCAG 2.1 AA, large text and non-text UI. */
    public const AA_LARGE = 3.0;

    /**
     * The contrast ratio between two colours, 1.0 (identical) to 21.0 (black on white).
     */
    public static function ratio(string $hexA, string $hexB): float
    {
        $a = self::luminance($hexA);
        $b = self::luminance($hexB);

        $lighter = max($a, $b);
        $darker = min($a, $b);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Fail when two colours do not reach a threshold.
     */
    public static function assertAtLeast(float $threshold, string $foreground, string $background, string $what = ''): void
    {
        $ratio = self::ratio($foreground, $background);

        Assert::assertGreaterThanOrEqual(
            $threshold,
            round($ratio, 2),
            sprintf(
                '%s%s on %s is %.2f:1, below the %.1f:1 this pair needs.',
                $what === '' ? '' : $what.': ',
                $foreground,
                $background,
                $ratio,
                $threshold,
            ),
        );
    }

    /**
     * Assert every pair in `tests/Support/contrast-pairs.php`.
     *
     * Each row is `[foreground, background, threshold, description]`. The file is the declaration
     * of which pairs the design actually uses — a ratio nobody listed is a ratio nobody checks, so
     * the file is appended to whenever a component introduces a new pairing.
     *
     * @return array{checked: int, failures: list<string>}
     */
    public static function auditPairs(?string $path = null): array
    {
        $path ??= base_path('tests/Support/contrast-pairs.php');

        if (! is_file($path)) {
            return ['checked' => 0, 'failures' => ['contrast-pairs.php is missing']];
        }

        /** @var mixed $pairs */
        $pairs = require $path;

        $failures = [];
        $checked = 0;

        foreach (is_array($pairs) ? $pairs : [] as $pair) {
            if (! is_array($pair) || count($pair) < 3) {
                continue;
            }

            [$foreground, $background, $threshold] = $pair;
            $what = (string) ($pair[3] ?? '');

            $foreground = self::resolve((string) $foreground);
            $background = self::resolve((string) $background);

            $ratio = self::ratio($foreground, $background);
            $checked++;

            if (round($ratio, 2) + 1e-9 < (float) $threshold) {
                $failures[] = sprintf(
                    '%s — %s on %s is %.2f:1, needs %.1f:1',
                    $what === '' ? 'pair' : $what,
                    $foreground,
                    $background,
                    $ratio,
                    (float) $threshold,
                );
            }
        }

        return ['checked' => $checked, 'failures' => $failures];
    }

    /**
     * A colour from the pairs file: a hex string, or `composite:COLOUR:ALPHA:OVER`.
     *
     * The second form is how a translucent surface is written down. `bg-emerald-500/10` on a dark
     * card is not emerald-500 and it is not the card — it is the colour they make together, and
     * that is the only one a reader ever sees.
     */
    public static function resolve(string $value): string
    {
        if (! str_starts_with($value, 'composite:')) {
            return $value;
        }

        $parts = explode(':', $value);

        if (count($parts) !== 4) {
            throw new InvalidArgumentException(sprintf(
                '[%s] is not composite:COLOUR:ALPHA:OVER.',
                $value,
            ));
        }

        return self::composite($parts[1], (float) $parts[2], $parts[3]);
    }

    /**
     * A translucent colour laid over an opaque one, as the opaque colour it becomes.
     *
     * Needed because half this design system's dark-mode surfaces are `bg-{token}-500/10` — a tint
     * of the accent over the card. Measuring the contrast of the *token* against the card would be
     * measuring a colour that never appears on screen; what a reader sees is the composite.
     *
     * Simple source-over compositing in sRGB space. Not gamma-correct, and deliberately so: it is
     * what a browser does, and the answer has to match what the user actually sees rather than what
     * the maths would prefer.
     */
    public static function composite(string $foreground, float $alpha, string $background): string
    {
        $alpha = max(0.0, min(1.0, $alpha));

        [$fr, $fg, $fb] = self::channels($foreground);
        [$br, $bg, $bb] = self::channels($background);

        return sprintf(
            '#%02x%02x%02x',
            (int) round(($fr * $alpha + $br * (1 - $alpha)) * 255),
            (int) round(($fg * $alpha + $bg * (1 - $alpha)) * 255),
            (int) round(($fb * $alpha + $bb * (1 - $alpha)) * 255),
        );
    }

    /**
     * WCAG relative luminance for an sRGB colour.
     */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::channels($hex);

        return 0.2126 * self::linearise($r)
            + 0.7152 * self::linearise($g)
            + 0.0722 * self::linearise($b);
    }

    /**
     * `#rgb`, `#rrggbb` or the same without the hash, as three 0..1 channels.
     *
     * @return array{float, float, float}
     */
    public static function channels(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (preg_match('/^[0-9a-fA-F]{3}$/', $hex) === 1) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            throw new InvalidArgumentException(sprintf('[%s] is not a hex colour.', $hex));
        }

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    /**
     * Undo the sRGB transfer curve for one channel.
     *
     * The piecewise form matters: a plain `pow($c, 2.2)` is close but wrong near black, which is
     * exactly where a "is this muted grey readable" question is decided.
     */
    private static function linearise(float $channel): float
    {
        return $channel <= 0.04045
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;
    }
}
