<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Results;

use App\Models\Institute\GradeScale;
use App\Models\Institute\GradeScaleBand;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Services\Institute\Exceptions\InvalidGradeScale;
use App\Services\Institute\GradeScaleService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\Feature\Institute\Results\Concerns\BuildsExams;
use Tests\TestCase;

/**
 * INV-20-3 and INV-20-4 through the real service path (phase-19-23 §6.8, §6.9, §11).
 *
 * The geometry itself is exhaustively covered by `tests/Unit/Institute/GradeBandValidatorTest` with no
 * database at all. What this file asserts is what only a database can: that the rules survive the
 * transaction, that `uq_gs_default` permits exactly one default, and that a band which has graded
 * somebody cannot be removed however it is asked.
 */
final class GradeScaleTest extends TestCase
{
    use BuildsCatalogue;
    use BuildsExams;
    use BuildsRegisters;
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | INV-20-3 — the bands hold together, and the service refuses when they do not
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_valid_scale_stores_its_bands_lowest_first_and_counts_them(): void
    {
        $scale = $this->gradeScale();

        $this->assertSame(4, (int) $scale->getAttribute('bands_count'));
        $this->assertSame(
            'F,C,B,A',
            $scale->bands()->orderBy('sort_order')->pluck('grade')->implode(','),
        );
    }

    #[Test]
    public function a_gap_between_two_bands_is_refused_and_names_both(): void
    {
        $this->expectException(InvalidGradeScale::class);
        $this->expectExceptionMessage('39.00');

        $this->gradeScale([
            ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '39.00', 'is_pass' => false],
            ['grade' => 'A', 'min_percentage' => '41.00', 'max_percentage' => '100.00', 'is_pass' => true],
        ]);
    }

    #[Test]
    public function an_overlap_is_refused(): void
    {
        $this->expectException(InvalidGradeScale::class);
        $this->expectExceptionMessage('overlap');

        $this->gradeScale([
            ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '50.00', 'is_pass' => false],
            ['grade' => 'A', 'min_percentage' => '40.00', 'max_percentage' => '100.00', 'is_pass' => true],
        ]);
    }

    /**
     * The guard against finer-than-two-decimal edges was a tautology for one commit:
     * `Money::compare()` rounds both sides to two before comparing, so a value against its own
     * two-decimal rounding is equal by construction. `39.9950` sailed through and was stored as
     * `40.00` — the administrator had excluded 40.00 from F, and F was given it.
     */
    #[Test]
    public function a_band_edge_finer_than_two_decimals_is_refused_rather_than_silently_rounded(): void
    {
        $this->expectException(InvalidGradeScale::class);

        $this->gradeScale([
            ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '39.9950', 'is_pass' => false],
            ['grade' => 'A', 'min_percentage' => '40.0050', 'max_percentage' => '100.00', 'is_pass' => true],
        ]);

        $this->assertDatabaseMissing('grade_scale_bands', ['max_percentage' => '40.0000']);
    }

    /**
     * **The pass line may cross once or not at all** — the contract says *at most* one transition, and
     * [D-20-2] is the reason: when an exam carries a `passing_marks` above zero it decides pass and
     * fail outright and the bands are only a naming ladder. A participation scale with no fail band is
     * a real thing, and this test exists because tightening the rule to "exactly one" is a tempting
     * one-line change that would refuse it.
     */
    #[Test]
    public function a_scale_whose_bands_all_agree_is_allowed(): void
    {
        $allPass = $this->gradeScale([
            ['grade' => 'PASS', 'min_percentage' => '0.00', 'max_percentage' => '49.99', 'is_pass' => true],
            ['grade' => 'MERIT', 'min_percentage' => '50.00', 'max_percentage' => '100.00', 'is_pass' => true],
        ], ['code' => 'ALLPASS']);

        $this->assertSame(2, (int) $allPass->getAttribute('bands_count'));
    }

    /** Two crossings is a ladder that fails 60% and passes 50%, which is never anybody's intention. */
    #[Test]
    public function a_pass_line_that_crosses_twice_is_refused(): void
    {
        $this->expectException(InvalidGradeScale::class);
        $this->expectExceptionMessage('crosses more than once');

        $this->gradeScale([
            ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '39.99', 'is_pass' => false],
            ['grade' => 'C', 'min_percentage' => '40.00', 'max_percentage' => '59.99', 'is_pass' => true],
            ['grade' => 'B', 'min_percentage' => '60.00', 'max_percentage' => '100.00', 'is_pass' => false],
        ]);
    }

    /** The lowest band passing while a higher one fails is the pass line upside down. */
    #[Test]
    public function an_upside_down_pass_line_is_refused(): void
    {
        $this->expectException(InvalidGradeScale::class);
        $this->expectExceptionMessage('upside down');

        $this->gradeScale([
            ['grade' => 'P', 'min_percentage' => '0.00', 'max_percentage' => '49.99', 'is_pass' => true],
            ['grade' => 'F', 'min_percentage' => '50.00', 'max_percentage' => '100.00', 'is_pass' => false],
        ]);
    }

    /**
     * A refusal must leave nothing behind. The validator runs before the transaction opens, so a
     * rejected scale should not exist even as an empty row with no bands.
     */
    #[Test]
    public function a_refused_scale_writes_no_row_at_all(): void
    {
        $before = GradeScale::query()->count();

        try {
            $this->gradeScale([
                ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '39.00', 'is_pass' => false],
                ['grade' => 'A', 'min_percentage' => '41.00', 'max_percentage' => '100.00', 'is_pass' => true],
            ], ['code' => 'ORPHAN']);
        } catch (InvalidGradeScale) {
            // expected
        }

        $this->assertSame($before, GradeScale::query()->count());
        $this->assertDatabaseMissing('grade_scales', ['code' => 'ORPHAN']);
    }

    #[Test]
    public function the_band_lookup_answers_at_the_edges(): void
    {
        $scale = $this->gradeScale();
        $service = app(GradeScaleService::class);

        $this->assertSame('F', $service->bandFor($scale, '0.0000')?->getAttribute('grade'));
        $this->assertSame('F', $service->bandFor($scale, '39.9900')?->getAttribute('grade'));
        $this->assertSame('C', $service->bandFor($scale, '40.0000')?->getAttribute('grade'));
        $this->assertSame('A', $service->bandFor($scale, '80.0000')?->getAttribute('grade'));
        $this->assertSame('A', $service->bandFor($scale, '100.0000')?->getAttribute('grade'));
    }

    /*
    |--------------------------------------------------------------------------
    | uq_gs_default — exactly one default, enforced by the index and not only the service
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function setting_a_default_clears_the_previous_one(): void
    {
        $staff = $this->createSuperAdmin();
        $seeded = $this->seededScale();
        $scale = $this->gradeScale(null, [], $staff);

        $this->assertTrue((bool) $seeded->getAttribute('is_default'));

        app(GradeScaleService::class)->setDefault($scale, $staff);

        $this->assertTrue((bool) $scale->refresh()->getAttribute('is_default'));
        $this->assertFalse((bool) $seeded->refresh()->getAttribute('is_default'));
        $this->assertSame(1, GradeScale::query()->where('is_default', true)->count());
    }

    /**
     * The index is the backstop, not the mechanism. `default_guard` is `1` for a default row and NULL
     * otherwise, and MariaDB's tolerance of NULLs in a unique index is what lets every non-default
     * scale coexist while only one row can carry the 1.
     */
    #[Test]
    public function two_defaults_are_impossible_even_writing_straight_to_the_table(): void
    {
        $scale = $this->gradeScale();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('grade_scales')->where('id', $scale->getKey())->update(['is_default' => true]);
    }

    #[Test]
    public function an_inactive_scale_cannot_become_the_default(): void
    {
        $staff = $this->createSuperAdmin();
        $scale = $this->gradeScale(null, ['is_active' => false], $staff);

        $this->expectException(CourseRuleException::class);

        app(GradeScaleService::class)->setDefault($scale, $staff);
    }

    /*
    |--------------------------------------------------------------------------
    | INV-20-4 — a scale behind a printed card stays reachable
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_band_that_has_graded_somebody_refuses_to_be_deleted(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $scale = $this->gradeScale(null, [], $staff);

        $exam = $this->conductedExam($batch, ['grade_scale_id' => $scale->getKey()], $staff);
        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['88', '35']), $staff);

        $awarded = GradeScaleBand::query()
            ->where('grade_scale_id', $scale->getKey())
            ->where('grade', 'A')
            ->firstOrFail();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('awarded');

        $awarded->delete();
    }

    #[Test]
    public function a_scale_that_has_graded_somebody_refuses_to_be_deleted_and_is_deactivated_instead(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $scale = $this->gradeScale(null, [], $staff);

        $exam = $this->conductedExam($batch, ['grade_scale_id' => $scale->getKey()], $staff);
        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['88', '35']), $staff);

        try {
            $scale->refresh()->delete();
            $this->fail('A used scale was deleted.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Deactivate', $e->getMessage());
        }

        app(GradeScaleService::class)->deactivate($scale->refresh(), 'Superseded by the 2027 ladder', $staff);

        $this->assertFalse((bool) $scale->refresh()->getAttribute('is_active'));
        $this->assertNull($scale->refresh()->getAttribute('deleted_at'));
        // Still reachable: the printed card names a grade, and the band has to explain it.
        $this->assertSame(4, $scale->bands()->count());
    }

    /**
     * A scale nobody has used is an ordinary row and goes away cleanly — which is what makes the
     * refusal above about evidence rather than about grade scales being undeletable.
     */
    #[Test]
    public function an_unused_scale_deletes_normally(): void
    {
        $scale = $this->gradeScale();

        $scale->delete();

        $this->assertSoftDeleted('grade_scales', ['id' => $scale->getKey()]);
    }
}
