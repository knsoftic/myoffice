<?php

declare(strict_types=1);

namespace Tests\Unit\Institute;

use App\Services\Institute\Exceptions\InvalidGradeScale;
use App\Services\Institute\GradeBandValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * INV-20-3 — a scale's bands hold together (phase-19-23 §6.9).
 *
 * **A unit test with no database**, because the validator has no dependencies: it takes arrays and
 * either returns them sorted or throws. That is the whole design, and a test that needed a migration
 * to run would be evidence the design had slipped.
 *
 * Every refusal is checked for **what it names**, not merely that it threw. A message saying "the
 * bands are invalid" is useless to somebody looking at a table of eight rows, so the test holds the
 * validator to naming the pair.
 */
final class GradeBandValidatorTest extends TestCase
{
    private GradeBandValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new GradeBandValidator;
    }

    /*
    |--------------------------------------------------------------------------
    | What it accepts
    |--------------------------------------------------------------------------
    */

    /** The scale the seeder ships — if the validator refused it, the system could not start. */
    #[Test]
    public function the_seeded_default_scale_is_valid(): void
    {
        $clean = $this->validator->validate($this->sevenBandScale());

        $this->assertCount(7, $clean);
        $this->assertSame('F', $clean[0]['grade'], 'Sorted lowest first, whatever order they arrived in.');
        $this->assertSame('A+', $clean[6]['grade']);
    }

    /** Bands may be submitted in any order; the validator sorts them. */
    #[Test]
    public function bands_are_sorted_lowest_first_whatever_order_they_arrive_in(): void
    {
        $shuffled = array_reverse($this->sevenBandScale());

        $clean = $this->validator->validate($shuffled);

        $this->assertSame('0.00', $clean[0]['min_percentage']);
        $this->assertSame('100.00', $clean[6]['max_percentage']);
    }

    /** Two bands is the minimum that can express a pass and a fail. */
    #[Test]
    public function two_bands_are_enough(): void
    {
        $clean = $this->validator->validate([
            ['grade' => 'P', 'min_percentage' => '40.00', 'max_percentage' => '100.00', 'is_pass' => true],
            ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '39.99', 'is_pass' => false],
        ]);

        $this->assertCount(2, $clean);
    }

    /** A scale where everything passes is legitimate — a participation ladder has no fail band. */
    #[Test]
    public function a_scale_with_no_fail_band_is_allowed(): void
    {
        $clean = $this->validator->validate([
            ['grade' => 'MERIT', 'min_percentage' => '50.00', 'max_percentage' => '100.00', 'is_pass' => true],
            ['grade' => 'PASS', 'min_percentage' => '0.00', 'max_percentage' => '49.99', 'is_pass' => true],
        ]);

        $this->assertCount(2, $clean, 'Zero crossings is as valid as one.');
    }

    /*
    |--------------------------------------------------------------------------
    | What it refuses, and what the message names
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_single_band_is_refused(): void
    {
        $this->expectException(InvalidGradeScale::class);
        $this->expectExceptionMessageMatches('/at least two bands/');

        $this->validator->validate([
            ['grade' => 'P', 'min_percentage' => '0.00', 'max_percentage' => '100.00', 'is_pass' => true],
        ]);
    }

    /** A gap means a mark that belongs to no grade at all. */
    #[Test]
    public function a_gap_between_two_bands_is_refused_and_names_both(): void
    {
        $this->assertRefusedNaming(
            [
                ['grade' => 'A', 'min_percentage' => '60.00', 'max_percentage' => '100.00', 'is_pass' => true],
                ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '39.99', 'is_pass' => false],
            ],
            ['39.99', '60.00', 'F', 'A'],
        );
    }

    /** An overlap means a mark that is two grades at once. */
    #[Test]
    public function an_overlap_is_refused_and_names_both(): void
    {
        $this->assertRefusedNaming(
            [
                ['grade' => 'A', 'min_percentage' => '39.00', 'max_percentage' => '100.00', 'is_pass' => true],
                ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '39.99', 'is_pass' => false],
            ],
            ['overlap', 'F', 'A'],
        );
    }

    #[Test]
    public function a_scale_that_does_not_start_at_zero_is_refused(): void
    {
        $this->assertRefusedNaming(
            [
                ['grade' => 'A', 'min_percentage' => '50.00', 'max_percentage' => '100.00', 'is_pass' => true],
                ['grade' => 'F', 'min_percentage' => '10.00', 'max_percentage' => '49.99', 'is_pass' => false],
            ],
            ['start at 0', 'F'],
        );
    }

    #[Test]
    public function a_scale_that_does_not_reach_one_hundred_is_refused(): void
    {
        $this->assertRefusedNaming(
            [
                ['grade' => 'A', 'min_percentage' => '50.00', 'max_percentage' => '95.00', 'is_pass' => true],
                ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '49.99', 'is_pass' => false],
            ],
            ['reach 100', 'A'],
        );
    }

    #[Test]
    public function a_band_running_backwards_is_refused(): void
    {
        $this->assertRefusedNaming(
            [
                ['grade' => 'A', 'min_percentage' => '100.00', 'max_percentage' => '50.00', 'is_pass' => true],
                ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '49.99', 'is_pass' => false],
            ],
            ['A'],
        );
    }

    #[Test]
    public function a_repeated_grade_is_refused(): void
    {
        $this->assertRefusedNaming(
            [
                ['grade' => 'A', 'min_percentage' => '50.00', 'max_percentage' => '100.00', 'is_pass' => true],
                ['grade' => 'A', 'min_percentage' => '0.00', 'max_percentage' => '49.99', 'is_pass' => false],
            ],
            ['twice', 'A'],
        );
    }

    /**
     * A scale that fails 60% and passes 50% is not a stricter scale; it is a mistake somebody made
     * while dragging rows around, and it hands the same student a pass and a fail depending on where
     * they land.
     */
    #[Test]
    public function a_pass_line_that_crosses_twice_is_refused(): void
    {
        $this->assertRefusedNaming(
            [
                ['grade' => 'A', 'min_percentage' => '80.00', 'max_percentage' => '100.00', 'is_pass' => true],
                ['grade' => 'B', 'min_percentage' => '60.00', 'max_percentage' => '79.99', 'is_pass' => false],
                ['grade' => 'C', 'min_percentage' => '40.00', 'max_percentage' => '59.99', 'is_pass' => true],
                ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '39.99', 'is_pass' => false],
            ],
            ['crosses more than once'],
        );
    }

    #[Test]
    public function a_pass_line_the_wrong_way_up_is_refused(): void
    {
        $this->assertRefusedNaming(
            [
                ['grade' => 'F', 'min_percentage' => '50.00', 'max_percentage' => '100.00', 'is_pass' => false],
                ['grade' => 'P', 'min_percentage' => '0.00', 'max_percentage' => '49.99', 'is_pass' => true],
            ],
            ['upside down', 'P'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The two-decimal rule
    |--------------------------------------------------------------------------
    */

    /**
     * **The subtlety the contract does not state.** §6.9 says each band's minimum is the previous
     * band's maximum plus `0.01`, and the columns are `decimal(8,4)` — so `39.9950` and `40.0050`
     * satisfy the step exactly while leaving `40.00` in the gap. A percentage is computed half-up at
     * two decimals, so `40.00` is precisely the kind of value that occurs, and it would belong to no
     * band at all.
     */
    #[Test]
    public function a_band_edge_finer_than_two_decimals_is_refused(): void
    {
        $this->assertRefusedNaming(
            [
                ['grade' => 'A', 'min_percentage' => '40.0050', 'max_percentage' => '100.00', 'is_pass' => true],
                ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '39.9950', 'is_pass' => false],
            ],
            ['two decimals', 'falling between'],
        );
    }

    /** Trailing zeros are the same number, so `40.0000` is fine where `40.0050` is not. */
    #[Test]
    public function four_decimal_places_of_zero_are_still_two_decimals(): void
    {
        $clean = $this->validator->validate([
            ['grade' => 'P', 'min_percentage' => '40.0000', 'max_percentage' => '100.0000', 'is_pass' => true],
            ['grade' => 'F', 'min_percentage' => '0.0000', 'max_percentage' => '39.9900', 'is_pass' => false],
        ]);

        $this->assertSame('40.00', $clean[1]['min_percentage'], 'Normalised to two decimals.');
    }

    #[Test]
    public function an_edge_outside_zero_to_one_hundred_is_refused(): void
    {
        $this->expectException(InvalidGradeScale::class);

        $this->validator->validate([
            ['grade' => 'A', 'min_percentage' => '40.00', 'max_percentage' => '120.00', 'is_pass' => true],
            ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '39.99', 'is_pass' => false],
        ]);
    }

    #[Test]
    public function a_band_with_no_grade_letter_is_refused(): void
    {
        $this->assertRefusedNaming(
            [
                ['grade' => '', 'min_percentage' => '40.00', 'max_percentage' => '100.00', 'is_pass' => true],
                ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '39.99', 'is_pass' => false],
            ],
            ['no grade'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The nightly verifier's face
    |--------------------------------------------------------------------------
    */

    /** `grades:verify-scales` reports rather than throws, so it asks the same question this way. */
    #[Test]
    public function is_valid_answers_without_throwing(): void
    {
        $this->assertTrue($this->validator->isValid($this->sevenBandScale()));

        $this->assertFalse($this->validator->isValid([
            ['grade' => 'A', 'min_percentage' => '60.00', 'max_percentage' => '100.00', 'is_pass' => true],
            ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '39.99', 'is_pass' => false],
        ]));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<array<string, mixed>>  $bands
     * @param  list<string>  $expected  fragments the message must contain
     */
    private function assertRefusedNaming(array $bands, array $expected): void
    {
        try {
            $this->validator->validate($bands);

            $this->fail('These bands were accepted: '.json_encode($bands));
        } catch (InvalidGradeScale $e) {
            $message = implode(' ', $e->validator->errors()->all());

            foreach ($expected as $fragment) {
                $this->assertStringContainsString(
                    $fragment,
                    $message,
                    sprintf('The refusal has to name “%s” so the reader knows which rows to look at. Got: %s', $fragment, $message),
                );
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sevenBandScale(): array
    {
        return [
            ['grade' => 'A+', 'min_percentage' => '90.00', 'max_percentage' => '100.00', 'is_pass' => true],
            ['grade' => 'A', 'min_percentage' => '80.00', 'max_percentage' => '89.99', 'is_pass' => true],
            ['grade' => 'B', 'min_percentage' => '70.00', 'max_percentage' => '79.99', 'is_pass' => true],
            ['grade' => 'C', 'min_percentage' => '60.00', 'max_percentage' => '69.99', 'is_pass' => true],
            ['grade' => 'D', 'min_percentage' => '50.00', 'max_percentage' => '59.99', 'is_pass' => true],
            ['grade' => 'E', 'min_percentage' => '40.00', 'max_percentage' => '49.99', 'is_pass' => true],
            ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '39.99', 'is_pass' => false],
        ];
    }
}
