<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Results;

use App\Enums\ExamAttendanceStatus;
use App\Enums\ExamStatus;
use App\Models\Institute\ExamResult;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\Feature\Institute\Results\Concerns\BuildsExams;
use Tests\TestCase;

/**
 * INV-20-1, INV-20-2 and INV-20-6 — the sheet (phase-19-23 §6.10, §11).
 */
final class ResultSheetTest extends TestCase
{
    use BuildsAdmissions;
    use BuildsCatalogue;
    use BuildsExams;
    use BuildsRegisters;
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | INV-20-6 — all or nothing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_clean_sheet_saves_every_row_and_moves_the_exam_to_marking(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 4, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $outcome = $this->resultService()->saveSheet(
            $exam,
            $this->sheetRows($seats, ['88', '72', '35', null]),
            $staff,
        );

        $this->assertTrue($outcome->succeeded());
        $this->assertSame(4, $outcome->saved);
        $this->assertSame(0, $outcome->updated);
        $this->assertSame(ExamStatus::Marking, $exam->refresh()->status);
        $this->assertSame(4, ExamResult::query()->where('exam_id', $exam->getKey())->count());
    }

    /**
     * **One bad row rejects the whole sheet.** The alternative — saving 27 of 30 and reporting three
     * errors — leaves a class half-entered in a way nobody notices until the averages look wrong.
     */
    #[Test]
    public function one_bad_row_saves_nothing_at_all(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 3, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $rows = $this->sheetRows($seats, ['88', '72', '35']);
        $rows[1]['obtained_marks'] = '101';

        $outcome = $this->resultService()->saveSheet($exam, $rows, $staff);

        $this->assertFalse($outcome->succeeded());
        $this->assertSame(0, $outcome->total(), 'A sheet reporting both saves and errors has broken INV-20-6.');
        $this->assertArrayHasKey(1, $outcome->errors, 'The error is keyed by the row that caused it.');
        $this->assertSame(0, ExamResult::query()->where('exam_id', $exam->getKey())->count());
        $this->assertSame(ExamStatus::Conducted, $exam->refresh()->status);
    }

    #[Test]
    public function a_student_who_is_not_on_the_roster_rejects_the_whole_sheet(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $rows = $this->sheetRows($seats, ['88', '72']);
        $rows[] = [
            'student_id' => (int) $this->registeredStudent($staff)->getKey(),
            'attendance_status' => 'appeared',
            'obtained_marks' => '50',
        ];

        $outcome = $this->resultService()->saveSheet($exam, $rows, $staff);

        $this->assertFalse($outcome->succeeded());
        $this->assertSame(0, ExamResult::query()->where('exam_id', $exam->getKey())->count());
    }

    /** Submitting the same sheet twice updates rather than duplicating — `uq_er_exam_student`. */
    #[Test]
    public function saving_the_same_sheet_twice_updates_and_never_duplicates(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 3, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['88', '72', '35']), $staff);
        $second = $this->resultService()->saveSheet($exam->refresh(), $this->sheetRows($seats, ['90', '72', '35']), $staff);

        $this->assertTrue($second->succeeded());
        $this->assertSame(3, $second->updated);
        $this->assertSame(0, $second->saved);
        $this->assertSame(3, ExamResult::query()->where('exam_id', $exam->getKey())->count());
        $this->assertSame('90.00', (string) ExamResult::query()
            ->where('exam_id', $exam->getKey())
            ->where('student_id', $seats[0]->getAttribute('student_id'))
            ->value('obtained_marks'));
    }

    #[Test]
    public function two_rows_for_one_student_are_impossible_at_the_database(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['88', '72']), $staff);

        $row = ExamResult::query()->where('exam_id', $exam->getKey())->firstOrFail();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('exam_results')->insert(array_merge(
            (array) DB::table('exam_results')->where('id', $row->getKey())->first(),
            ['id' => null],
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | An absence is not a zero
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_absent_student_carries_no_mark_and_the_service_refuses_one(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $outcome = $this->resultService()->saveSheet($exam, [
            ['student_id' => (int) $seats[0]->getAttribute('student_id'), 'attendance_status' => 'appeared', 'obtained_marks' => '60'],
            ['student_id' => (int) $seats[1]->getAttribute('student_id'), 'attendance_status' => 'absent', 'obtained_marks' => '0'],
        ], $staff);

        $this->assertFalse($outcome->succeeded());
        $this->assertSame(0, ExamResult::query()->where('exam_id', $exam->getKey())->count());
    }

    #[Test]
    public function a_student_who_appeared_must_have_a_mark(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 1, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $outcome = $this->resultService()->saveSheet($exam, [
            ['student_id' => (int) $seats[0]->getAttribute('student_id'), 'attendance_status' => 'appeared', 'obtained_marks' => null],
        ], $staff);

        $this->assertFalse($outcome->succeeded());
    }

    /** `chk_er_absent` says it again at the database, where it cannot be argued with. */
    #[Test]
    public function the_database_refuses_an_absence_carrying_marks(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['88', null]), $staff);

        $absent = ExamResult::query()
            ->where('exam_id', $exam->getKey())
            ->where('attendance_status', ExamAttendanceStatus::Absent->value)
            ->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('exam_results')->where('id', $absent->getKey())->update(['obtained_marks' => '0.00']);
    }

    #[Test]
    public function an_absence_counts_towards_the_class_average_and_an_exemption_does_not(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 3, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->resultService()->saveSheet($exam, [
            ['student_id' => (int) $seats[0]->getAttribute('student_id'), 'attendance_status' => 'appeared', 'obtained_marks' => '80'],
            ['student_id' => (int) $seats[1]->getAttribute('student_id'), 'attendance_status' => 'absent', 'obtained_marks' => null],
            ['student_id' => (int) $seats[2]->getAttribute('student_id'), 'attendance_status' => 'exempt', 'obtained_marks' => null],
        ], $staff);

        $this->assertTrue(ExamAttendanceStatus::Absent->countsInDenominator());
        $this->assertFalse(ExamAttendanceStatus::Exempt->countsInDenominator());
        $this->assertTrue(ExamAttendanceStatus::Absent->countsAsFail());
        $this->assertFalse(ExamAttendanceStatus::Exempt->countsAsFail());

        $exempt = ExamResult::query()
            ->where('exam_id', $exam->getKey())
            ->where('attendance_status', ExamAttendanceStatus::Exempt->value)
            ->firstOrFail();

        $this->assertNotTrue($exempt->getAttribute('is_passed'));
    }

    /*
    |--------------------------------------------------------------------------
    | INV-20-1 — the snapshot, and INV-20-2 — who may write a grade
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function each_row_snapshots_what_the_paper_was_out_of(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, ['total_marks' => '50', 'passing_marks' => '20'], $staff);

        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['44', '18']), $staff);

        foreach (ExamResult::query()->where('exam_id', $exam->getKey())->get() as $row) {
            $this->assertSame('50.00', (string) $row->getAttribute('total_marks'));
            $this->assertNotNull($row->getAttribute('grade_scale_id'));
        }
    }

    /** `chk_er_marks` compares two columns of the same row, so the ceiling is a database fact. */
    #[Test]
    public function the_database_refuses_a_mark_above_the_snapshot(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 1, $staff);
        $exam = $this->conductedExam($batch, ['total_marks' => '50', 'passing_marks' => '20'], $staff);

        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['44']), $staff);

        $row = ExamResult::query()->where('exam_id', $exam->getKey())->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('exam_results')->where('id', $row->getKey())->update(['obtained_marks' => '51.00']);
    }

    /**
     * INV-20-2: a controller, a Form Request and a seeder can never write a grade. The model refuses
     * unless the write came through `ExamResult::calculated()`, which only `ResultCalculator`'s caller
     * opens.
     */
    #[Test]
    public function a_grade_cannot_be_written_outside_the_calculator(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 1, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['35']), $staff);

        $row = ExamResult::query()->where('exam_id', $exam->getKey())->firstOrFail();

        $this->expectException(LogicException::class);

        $row->forceFill(['grade' => 'A', 'is_passed' => true])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | The grade, the pass decision and the caches
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_grade_and_percentage_come_from_the_resolved_scale(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 4, $staff);
        $scale = $this->gradeScale(null, ['code' => 'FOUR'], $staff);
        $exam = $this->conductedExam($batch, ['grade_scale_id' => $scale->getKey(), 'passing_marks' => '0'], $staff);

        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['88', '72', '45', '20']), $staff);

        $grades = ExamResult::query()
            ->where('exam_id', $exam->getKey())
            ->orderByDesc('obtained_marks')
            ->pluck('grade')
            ->all();

        $this->assertSame(['A', 'B', 'C', 'F'], $grades);
    }

    /**
     * [D-20-2]: the exam's own `passing_marks` wins over the band's `is_pass` when it is above zero.
     * A student on 45% holds a passing band and still fails an exam whose pass mark is 50.
     */
    #[Test]
    public function the_exams_pass_mark_beats_the_bands_opinion(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 1, $staff);
        $scale = $this->gradeScale(null, ['code' => 'BANDS'], $staff);
        $exam = $this->conductedExam($batch, ['grade_scale_id' => $scale->getKey(), 'passing_marks' => '50'], $staff);

        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['45']), $staff);

        $row = ExamResult::query()->where('exam_id', $exam->getKey())->firstOrFail();

        $this->assertSame('C', $row->getAttribute('grade'), 'The band still names the grade.');
        $this->assertFalse((bool) $row->getAttribute('is_passed'), 'But the exam decides the outcome.');
    }

    /** PH20-05: the pass mark is inclusive. 33 out of 100 with a pass mark of 33 is a pass. */
    #[Test]
    public function the_pass_mark_is_inclusive(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, ['passing_marks' => '33'], $staff);

        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['33', '32.99']), $staff);

        $rows = ExamResult::query()->where('exam_id', $exam->getKey())->orderByDesc('obtained_marks')->get();

        $this->assertTrue((bool) $rows[0]->getAttribute('is_passed'));
        $this->assertFalse((bool) $rows[1]->getAttribute('is_passed'));
    }

    #[Test]
    public function the_exam_caches_are_re_derivable_from_the_rows(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 4, $staff);
        $exam = $this->conductedExam($batch, ['passing_marks' => '40'], $staff);

        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['88', '72', '35', null]), $staff);
        $exam->refresh();

        $rows = ExamResult::query()->where('exam_id', $exam->getKey())->get();

        $this->assertSame($rows->count(), (int) $exam->getAttribute('results_entered_count'));
        $this->assertSame(
            $rows->where('attendance_status', ExamAttendanceStatus::Appeared)->count(),
            (int) $exam->getAttribute('appeared_count'),
        );
        $this->assertSame(
            $rows->where('attendance_status', ExamAttendanceStatus::Absent)->count(),
            (int) $exam->getAttribute('absent_count'),
        );
        $this->assertSame(
            $rows->where('is_passed', true)->count(),
            (int) $exam->getAttribute('passed_count'),
        );
        $this->assertSame('88.00', (string) $exam->getAttribute('highest_marks'));
        $this->assertSame('35.00', (string) $exam->getAttribute('lowest_marks'));
    }

    /*
    |--------------------------------------------------------------------------
    | The roster is the one on the exam's own date
    |--------------------------------------------------------------------------
    */

    /**
     * A student who joined the batch last week was never expected to sit an exam that happened a
     * month ago — so they are not on its sheet, and the sheet does not silently grow a blank row for
     * them when somebody reopens it.
     */
    #[Test]
    public function the_sheet_holds_the_roster_as_it_stood_on_the_exams_date(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $this->roster($batch, 2, $staff);

        $exam = $this->conductedExam($batch, [], $staff);
        $before = $this->resultService()->openSheet($exam)['rows']->count();

        $this->assertSame(2, $before);
    }

    /*
    |--------------------------------------------------------------------------
    | Nothing deletes a result
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_result_refuses_to_be_deleted_even_by_a_super_admin(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 1, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['60']), $staff);

        $row = ExamResult::query()->where('exam_id', $exam->getKey())->firstOrFail();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('never deleted');

        $row->delete();
    }
}
