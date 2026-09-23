<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Services\Institute\Exceptions\InvalidGradeScale;
use App\Support\Money;

/**
 * INV-20-3: a scale's bands are contiguous, non-overlapping and cover exactly 0–100
 * (phase-19-23 §6.9).
 *
 * **None of this can be a CHECK**, because every rule here is about the *set* of bands rather than
 * about any one row — which is why it lives in a class that the saving transaction calls and that
 * `grades:verify-scales` re-runs nightly. A scale that fails is refused; a scale that has drifted since
 * is reported.
 *
 * **Band edges must be at two decimals, and that is a rule the contract does not spell out.** §6.9 says
 * each band's `min` is the previous band's `max` plus `0.01`, and the columns are `decimal(8,4)` — so
 * an administrator could enter `39.9950` and `40.0050` and satisfy the step exactly while leaving
 * `40.00` in the gap. Percentages are computed half-up at two decimals (§6.8), so `40.00` is precisely
 * the kind of value that occurs, and it would land in no band at all. Requiring 2dp edges is what makes
 * "contiguous" mean contiguous *for the values that exist*.
 *
 * Every refusal names the offending pair rather than saying "the bands are wrong", because the person
 * reading one is looking at a table of eight rows and needs to know which two.
 */
final class GradeBandValidator
{
    /** The gap the contract sets between one band's top and the next band's bottom. */
    private const STEP = '0.01';

    private const FLOOR = '0.00';

    private const CEILING = '100.00';

    /**
     * @param  list<array{grade?: string, min_percentage?: mixed, max_percentage?: mixed, is_pass?: mixed}>  $bands
     * @return list<array{grade: string, min_percentage: string, max_percentage: string, is_pass: bool}>
     *
     * @throws InvalidGradeScale
     */
    public function validate(array $bands): array
    {
        if (count($bands) < 2) {
            throw InvalidGradeScale::because('A scale needs at least two bands — one of them a fail.');
        }

        $clean = $this->normalise($bands);

        $this->assertGradesAreUnique($clean);

        usort($clean, static fn (array $a, array $b): int => Money::compare($a['min_percentage'], $b['min_percentage']));

        $this->assertCoversTheWholeRange($clean);
        $this->assertContiguous($clean);
        $this->assertPassLineIsASingleCut($clean);

        return $clean;
    }

    /** True when the bands are sound — for the nightly verifier, which reports rather than throws. */
    public function isValid(array $bands): bool
    {
        try {
            $this->validate($bands);

            return true;
        } catch (InvalidGradeScale) {
            return false;
        }
    }

    // -------------------------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $bands
     * @return list<array{grade: string, min_percentage: string, max_percentage: string, is_pass: bool}>
     */
    private function normalise(array $bands): array
    {
        $clean = [];

        foreach ($bands as $index => $band) {
            $grade = trim((string) ($band['grade'] ?? ''));

            if ($grade === '') {
                throw InvalidGradeScale::because(sprintf('Band %d has no grade letter.', $index + 1));
            }

            $min = $this->twoDecimals($band['min_percentage'] ?? null, $grade, 'lowest');
            $max = $this->twoDecimals($band['max_percentage'] ?? null, $grade, 'highest');

            if (Money::compare($min, $max) > 0) {
                throw InvalidGradeScale::because(sprintf(
                    '%s runs from %s down to %s. Its lowest mark has to be below its highest.',
                    $grade,
                    $min,
                    $max,
                ));
            }

            $clean[] = [
                'grade' => $grade,
                'min_percentage' => $min,
                'max_percentage' => $max,
                'is_pass' => (bool) ($band['is_pass'] ?? true),
            ];
        }

        return $clean;
    }

    /**
     * A band edge at more than two decimals cannot be contiguous with the percentages that actually
     * occur — see the class note.
     */
    private function twoDecimals(mixed $value, string $grade, string $which): string
    {
        if ($value === null || $value === '' || ! is_numeric((string) $value)) {
            throw InvalidGradeScale::because(sprintf('%s has no %s percentage.', $grade, $which));
        }

        $raw = trim((string) $value);
        $rounded = Money::round($raw, 2);

        // `bccomp` at four places, **not** `Money::compare` — that rounds both sides to two before
        // comparing (`Money::SCALE`), so a value against its own two-decimal rounding is equal by
        // construction and this guard could never fire. It shipped as `Money::compare` and silently
        // accepted `39.9950`, storing it as `40.00`: the administrator excluded 40.00 from F and F
        // was given it. A rounding that moves a pass boundary is not a rounding, it is a change.
        if (bccomp($raw, $rounded, 4) !== 0) {
            throw InvalidGradeScale::because(sprintf(
                '%s’s %s percentage is %s. Band edges go to two decimals — a finer one leaves marks '
                .'falling between two bands, because a percentage is worked out to two.',
                $grade,
                $which,
                $raw,
            ));
        }

        if (Money::compare($rounded, self::FLOOR) < 0 || Money::compare($rounded, self::CEILING) > 0) {
            throw InvalidGradeScale::because(sprintf('%s’s %s percentage is outside 0–100.', $grade, $which));
        }

        return $rounded;
    }

    /** @param list<array{grade: string}> $bands */
    private function assertGradesAreUnique(array $bands): void
    {
        $seen = [];

        foreach ($bands as $band) {
            $key = mb_strtoupper($band['grade']);

            if (isset($seen[$key])) {
                throw InvalidGradeScale::because(sprintf('%s appears twice. Each grade is one band.', $band['grade']));
            }

            $seen[$key] = true;
        }
    }

    /** @param list<array{grade: string, min_percentage: string, max_percentage: string}> $bands */
    private function assertCoversTheWholeRange(array $bands): void
    {
        $lowest = $bands[0];
        $highest = $bands[count($bands) - 1];

        if (Money::compare($lowest['min_percentage'], self::FLOOR) !== 0) {
            throw InvalidGradeScale::because(sprintf(
                'The lowest band, %s, starts at %s. It has to start at 0 — a student can score nothing.',
                $lowest['grade'],
                $lowest['min_percentage'],
            ));
        }

        if (Money::compare($highest['max_percentage'], self::CEILING) !== 0) {
            throw InvalidGradeScale::because(sprintf(
                'The highest band, %s, stops at %s. It has to reach 100 — a student can score everything.',
                $highest['grade'],
                $highest['max_percentage'],
            ));
        }
    }

    /** @param list<array{grade: string, min_percentage: string, max_percentage: string}> $bands */
    private function assertContiguous(array $bands): void
    {
        for ($i = 1, $count = count($bands); $i < $count; $i++) {
            $below = $bands[$i - 1];
            $above = $bands[$i];

            $expected = Money::round(bcadd($below['max_percentage'], self::STEP, 4), 2);
            $comparison = Money::compare($above['min_percentage'], $expected);

            if ($comparison === 0) {
                continue;
            }

            throw InvalidGradeScale::because($comparison < 0
                ? sprintf(
                    '%s (up to %s) and %s (from %s) overlap. A mark cannot be two grades at once.',
                    $below['grade'],
                    $below['max_percentage'],
                    $above['grade'],
                    $above['min_percentage'],
                )
                : sprintf(
                    'Nothing covers the marks between %s and %s — %s stops at the first and %s starts at the second.',
                    $below['max_percentage'],
                    $above['min_percentage'],
                    $below['grade'],
                    $above['grade'],
                ));
        }
    }

    /**
     * Reading upwards, the scale may cross from fail to pass **once**.
     *
     * A scale that failed 60 % and passed 50 % is not a stricter scale; it is a mistake somebody made
     * while dragging rows around, and it would hand the same student a pass and a fail depending on
     * which band they landed in.
     *
     * @param  list<array{grade: string, is_pass: bool}>  $bands
     */
    private function assertPassLineIsASingleCut(array $bands): void
    {
        $crossings = 0;

        for ($i = 1, $count = count($bands); $i < $count; $i++) {
            if ($bands[$i - 1]['is_pass'] !== $bands[$i]['is_pass']) {
                $crossings++;
            }
        }

        if ($crossings > 1) {
            throw InvalidGradeScale::because(
                'The pass line crosses more than once. Reading from the lowest band upwards, the '
                .'scale has to fail everything below one point and pass everything above it.',
            );
        }

        if ($crossings === 1 && $bands[0]['is_pass']) {
            throw InvalidGradeScale::because(sprintf(
                'The lowest band, %s, passes while a higher one fails. That is the pass line upside down.',
                $bands[0]['grade'],
            ));
        }
    }
}
