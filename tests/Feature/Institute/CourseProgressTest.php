<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Enums\ProgressSource;
use App\Enums\ProgressStatus;
use App\Models\Institute\CourseTopic;
use App\Models\Institute\StudentCourseProgress;
use App\Models\Institute\StudentModuleProgress;
use App\Models\Institute\StudentTopicProgress;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\TestCase;

/**
 * The syllabus at three levels (§83, phase-14-17 §6.10; FT-44).
 *
 * **Two rules carry the whole design.** A hand-set row is never overwritten by a class-level mark, so
 * a teacher's judgement about one student survives; and a skipped or deactivated topic leaves *both*
 * sides of the fraction, so dropping work raises the percentage instead of freezing it. Getting the
 * second backwards is the classic progress-bar bug: the number stalls and nobody can say why.
 */
final class CourseProgressTest extends TestCase
{
    use BuildsRegisters;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Opening a syllabus
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function enrolling_a_student_opens_their_syllabus(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1, 1], $actor);
        $batch = $this->enrollingBatch($course, [], $actor);

        $enrollment = $this->seat($batch, actor: $actor);

        $progress = StudentCourseProgress::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->first();

        $this->assertNotNull($progress, 'a seat comes with a syllabus to get through');
        $this->assertSame(3, StudentTopicProgress::query()->where('student_course_progress_id', $progress->getKey())->count());
        $this->assertSame(1, StudentModuleProgress::query()->where('student_course_progress_id', $progress->getKey())->count());
        $this->assertSame('0.0000', (string) $progress->completion_percentage);
        $this->assertSame(ProgressStatus::Pending, $progress->status);
    }

    #[Test]
    public function opening_it_twice_is_idempotent(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1], $actor);
        $batch = $this->enrollingBatch($course, [], $actor);
        $enrollment = $this->seat($batch, actor: $actor);

        $this->progressService()->openFor($enrollment->refresh(), $actor);
        $this->progressService()->openFor($enrollment->refresh(), $actor);

        $this->assertSame(1, StudentCourseProgress::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->count());
        $this->assertSame(2, StudentTopicProgress::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | FT-44 — the fan-out and the shield
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function marking_a_topic_for_a_batch_reaches_every_active_student(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1, 1, 1], $actor);
        $batch = $this->enrollingBatch($course, [], $actor);

        $first = $this->seat($batch, actor: $actor);
        $second = $this->seat($batch->refresh(), actor: $actor);

        $topics = $this->topicsOf($course);

        $this->progressService()->markTopicForBatch($batch->refresh(), $topics[0], [], $actor);

        foreach ([$first, $second] as $enrollment) {
            $progress = StudentCourseProgress::query()
                ->where('student_batch_enrollment_id', $enrollment->getKey())
                ->firstOrFail();

            $this->assertSame(1, (int) $progress->topics_completed);
            $this->assertSame('25.0000', (string) $progress->completion_percentage, 'one of four equal topics');
            $this->assertSame(ProgressStatus::InProgress, $progress->status);
        }
    }

    #[Test]
    public function a_hand_set_row_is_never_overwritten_by_the_class_level_mark(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1], $actor);
        $batch = $this->enrollingBatch($course, [], $actor);

        $mine = $this->seat($batch, actor: $actor);
        $theirs = $this->seat($batch->refresh(), actor: $actor);

        $topics = $this->topicsOf($course);

        // A teacher records that this one student has only half grasped it.
        $this->progressService()->markTopicForStudent($mine->refresh(), $topics[0], [
            'completion_percentage' => '50.0000',
        ], $actor);

        // Then the class covers it.
        $this->progressService()->markTopicForBatch($batch->refresh(), $topics[0], [], $actor);

        $mineProgress = StudentCourseProgress::query()->where('student_batch_enrollment_id', $mine->getKey())->firstOrFail();
        $theirsProgress = StudentCourseProgress::query()->where('student_batch_enrollment_id', $theirs->getKey())->firstOrFail();

        $mineRow = StudentTopicProgress::query()
            ->where('student_course_progress_id', $mineProgress->getKey())
            ->where('course_topic_id', $topics[0]->getKey())
            ->firstOrFail();

        $theirsRow = StudentTopicProgress::query()
            ->where('student_course_progress_id', $theirsProgress->getKey())
            ->where('course_topic_id', $topics[0]->getKey())
            ->firstOrFail();

        $this->assertSame('50.0000', (string) $mineRow->completion_percentage,
            'a judgement about one student is not undone by the next class-level mark');
        $this->assertSame(ProgressSource::Manual, $mineRow->source);
        $this->assertTrue($mineRow->isProtectedFromBatchMark());

        $this->assertSame('100.0000', (string) $theirsRow->completion_percentage);
        $this->assertSame(ProgressSource::BatchCoverage, $theirsRow->source);
    }

    #[Test]
    public function skipping_a_topic_raises_the_percentage(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1, 1, 1], $actor);
        $batch = $this->enrollingBatch($course, [], $actor);
        $enrollment = $this->seat($batch, actor: $actor);

        $topics = $this->topicsOf($course);

        $this->progressService()->markTopicForBatch($batch->refresh(), $topics[0], [], $actor);

        $progress = StudentCourseProgress::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->firstOrFail();

        $this->assertSame('25.0000', (string) $progress->completion_percentage);

        // Drop an uncovered topic: three remain, one of them done.
        $this->progressService()->skipTopic($batch->refresh(), $topics[3], 'Dropped from this batch.', $actor);

        $progress->refresh();

        $this->assertSame('33.3300', (string) $progress->completion_percentage,
            'dropping work must advance the number, not freeze it');
        $this->assertSame(3, (int) $progress->topics_total);
    }

    #[Test]
    public function skipping_a_topic_takes_a_reason(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1], $actor);
        $batch = $this->enrollingBatch($course, [], $actor);
        $this->seat($batch, actor: $actor);

        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessageMatches('/Say why/');

        $this->progressService()->skipTopic($batch->refresh(), $this->topicsOf($course)[0], '   ', $actor);
    }

    #[Test]
    public function deactivating_a_topic_recomputes_and_also_raises_it(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1, 1, 1], $actor);
        $batch = $this->enrollingBatch($course, [], $actor);
        $enrollment = $this->seat($batch, actor: $actor);

        $topics = $this->topicsOf($course);
        $this->progressService()->markTopicForBatch($batch->refresh(), $topics[0], [], $actor);

        $progress = StudentCourseProgress::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->firstOrFail();

        $this->assertSame('25.0000', (string) $progress->completion_percentage);

        // Out of the outline entirely, rather than skipped for this batch.
        CourseTopic::query()->whereKey($topics[3]->getKey())->update(['is_active' => false]);

        $this->progressService()->recompute($progress);

        $this->assertSame('33.3300', (string) $progress->refresh()->completion_percentage,
            'a deactivated topic leaves both sides, exactly as a skipped one does');
    }

    /*
    |--------------------------------------------------------------------------
    | The formulas, under both weightings
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_weighting_setting_is_the_whole_of_what_it_changes(): void
    {
        $actor = $this->createSuperAdmin();
        // A heavy topic and two light ones.
        $course = $this->courseWithOutline([8, 1, 1], $actor);
        $batch = $this->enrollingBatch($course, [], $actor);
        $enrollment = $this->seat($batch, actor: $actor);

        $topics = $this->topicsOf($course);

        $this->setting('institute.progress_weighting', 'topic_weight');
        $this->progressService()->markTopicForBatch($batch->refresh(), $topics[0], [], $actor);

        $progress = StudentCourseProgress::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->firstOrFail();

        // 8 of 10 by weight.
        $this->assertSame('80.0000', (string) $progress->completion_percentage);

        $this->setting('institute.progress_weighting', 'topic_count');
        $this->progressService()->recompute($progress);

        // One of three, counting every topic equally.
        $this->assertSame('33.3300', (string) $progress->refresh()->completion_percentage);
    }

    #[Test]
    public function the_module_and_course_rows_agree_with_the_topic_rows(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1, 2], $actor);
        $batch = $this->enrollingBatch($course, [], $actor);
        $enrollment = $this->seat($batch, actor: $actor);

        $topics = $this->topicsOf($course);

        $this->progressService()->markTopicForBatch($batch->refresh(), $topics[0], [], $actor);
        $this->progressService()->markTopicForBatch($batch->refresh(), $topics[2], ['completion_percentage' => '50.0000'], $actor);

        $progress = StudentCourseProgress::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->firstOrFail();

        // (100×1 + 0×1 + 50×2) / 4 = 50
        $this->assertSame('50.0000', (string) $progress->completion_percentage);

        $moduleRow = StudentModuleProgress::query()
            ->where('student_course_progress_id', $progress->getKey())
            ->firstOrFail();

        $this->assertSame((string) $progress->completion_percentage, (string) $moduleRow->completion_percentage,
            'one module means the module and the course must be the same number');

        // And the cached weight columns hold to their CHECK.
        $this->assertLessThanOrEqual((int) $progress->weight_total, (int) $progress->weight_completed);
        $this->assertSame(4, (int) $progress->weight_total);
    }

    #[Test]
    public function a_course_with_no_outline_reports_zero_and_never_divides_by_zero(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->courseWithNoOutline($actor);
        $batch = $this->enrollingBatch($course, [], $actor);
        $enrollment = $this->seat($batch, actor: $actor);

        $progress = StudentCourseProgress::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->firstOrFail();

        $this->assertSame('0.0000', (string) $progress->completion_percentage);
        $this->assertSame(0, (int) $progress->topics_total);
        $this->assertSame(ProgressStatus::Pending, $progress->status);

        $this->assertSame('0.00', $this->progressService()->recountBatchSyllabus($batch->refresh()));
    }

    /*
    |--------------------------------------------------------------------------
    | The batch syllabus cache, and the recompute command
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_batch_syllabus_counts_the_whole_outline_not_only_what_was_touched(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1, 1, 1], $actor);
        $batch = $this->enrollingBatch($course, [], $actor);

        $this->progressService()->markTopicForBatch($batch->refresh(), $this->topicsOf($course)[0], [], $actor);

        $this->assertSame('25.0000', (string) $batch->refresh()->syllabus_completion_percentage,
            'a syllabus that read 100% after one covered topic would be a lie');
    }

    #[Test]
    public function a_class_marked_held_covers_the_topic_it_carried(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1], $actor);
        $batch = $this->runningBatch($course, [], $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);

        $topic = $this->topicsOf($course)[0];

        $session = $this->classOn($batch, Carbon::today()->subDay()->toDateString(), 'scheduled');
        $session->forceFill(['course_topic_id' => $topic->getKey()])->save();

        $this->sessionService()->markHeld($session->refresh(), $actor);

        $this->assertDatabaseHas('batch_topic_coverage', [
            'batch_id' => $batch->getKey(),
            'course_topic_id' => $topic->getKey(),
            'status' => 'completed',
            'class_session_id' => $session->getKey(),
        ]);

        $progress = StudentCourseProgress::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->firstOrFail();

        $this->assertSame('50.0000', (string) $progress->completion_percentage);
    }

    #[Test]
    public function the_recompute_command_reproduces_every_stored_percentage(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1, 1], $actor);
        $batch = $this->enrollingBatch($course, [], $actor);
        $enrollment = $this->seat($batch, actor: $actor);

        $this->progressService()->markTopicForBatch($batch->refresh(), $this->topicsOf($course)[0], [], $actor);

        $progress = StudentCourseProgress::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->firstOrFail();

        $stored = (string) $progress->completion_percentage;

        StudentCourseProgress::query()->whereKey($progress->getKey())->update([
            'completion_percentage' => '1.0000',
            'topics_completed' => 0,
        ]);

        $this->artisan('progress:recompute', ['--batch' => $batch->getKey()])->assertSuccessful();

        $this->assertSame($stored, (string) $progress->refresh()->completion_percentage,
            'every progress percentage is a cache derived from the topic rows (INV-I11)');
        $this->assertSame(1, (int) $progress->refresh()->topics_completed);
    }

    #[Test]
    public function every_percentage_is_a_four_decimal_column_holding_a_two_decimal_figure(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1, 1], $actor);
        $batch = $this->enrollingBatch($course, [], $actor);
        $enrollment = $this->seat($batch, actor: $actor);

        $this->progressService()->markTopicForBatch($batch->refresh(), $this->topicsOf($course)[0], [], $actor);

        $progress = StudentCourseProgress::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->firstOrFail();

        // One of three is 33.33 — half-up at two, stored in decimal(8,4) (CLAUDE.md §3, INV-I11).
        $this->assertSame('33.3300', (string) $progress->completion_percentage);
        $this->assertSame(0, Money::compare('33.33', (string) $progress->completion_percentage));
    }

    #[Test]
    public function a_soft_deleted_progress_row_is_restored_rather_than_wedging_the_seat(): void
    {
        // §2.26 gives this table a `deleted_at` to honour CLAUDE.md §3, but `uq_scp` is on
        // `student_batch_enrollment_id` alone and does not include it. So a soft-deleted row is
        // invisible to a default query and still occupies the guard: `openFor()` used to answer null
        // and then insert into a 1062, and that seat could never have progress again (D19).
        $actor = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1, 1], $actor);
        $batch = $this->enrollingBatch($course, [], $actor);
        $enrollment = $this->seat($batch, actor: $actor);

        $this->progressService()->markTopicForBatch($batch->refresh(), $this->topicsOf($course)[0], [], $actor);

        $original = StudentCourseProgress::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->firstOrFail();

        $original->delete();

        $this->assertSoftDeleted('student_course_progress', ['id' => $original->getKey()]);

        $reopened = $this->progressService()->openFor($enrollment->refresh(), $actor);

        // The same row, brought back — not a second one, and not an exception.
        $this->assertSame($original->getKey(), $reopened->getKey());
        $this->assertFalse($reopened->trashed());
        $this->assertSame(
            1,
            StudentCourseProgress::withTrashed()
                ->where('student_batch_enrollment_id', $enrollment->getKey())
                ->count(),
        );

        // And its topic rows came back with it, so the percentage survives the round trip.
        $this->assertSame(3, StudentTopicProgress::query()->where('student_course_progress_id', $reopened->getKey())->count());
        $this->assertSame('33.3300', (string) $reopened->completion_percentage);
    }
}
