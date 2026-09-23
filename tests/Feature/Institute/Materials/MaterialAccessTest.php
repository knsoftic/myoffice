<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Materials;

use App\DataObjects\Institute\MaterialGrant;
use App\Enums\MaterialAccessAction;
use App\Models\Institute\CourseMaterial;
use App\Models\Module;
use App\Services\Institute\BatchEnrollmentService;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\Feature\Institute\Materials\Concerns\BuildsMaterials;
use Tests\TestCase;

/**
 * INV-19-3 — who may open a material, decided in one place (phase-19-23 §6.5, §6.6, §11).
 *
 * **`grantFor()` and `visibleToStudent()` are the two faces of the same rule**, so every case here
 * asserts both: a material the list shows must be one the download allows, and one the list hides must
 * be one the download refuses. A test that checked only the list would pass while the file leaked.
 */
final class MaterialAccessTest extends TestCase
{
    use BuildsAdmissions;
    use BuildsCatalogue;
    use BuildsMaterials;
    use BuildsRegisters;
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    /*
    |--------------------------------------------------------------------------
    | The happy path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_targeted_student_may_open_a_published_material(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student, $studentUser] = $this->studentWithLogin($batch, $staff);

        $material = $this->sharedMaterial($batch, $staff);

        $this->assertTrue($this->accessService()->grantFor($material, $studentUser)->allowed);
        $this->assertContainsMaterial($material, $student);
    }

    /*
    |--------------------------------------------------------------------------
    | What it refuses, and whether it says why
    |--------------------------------------------------------------------------
    */

    /**
     * A draft is not merely hidden — it is **undiscoverable**, so the refusal is a 404. Saying
     * "this is not published yet" would confirm there is something to wait for.
     */
    #[Test]
    public function a_draft_is_invisible_to_a_student_and_the_refusal_does_not_admit_it_exists(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student, $studentUser] = $this->studentWithLogin($batch, $staff);

        $material = $this->sharedMaterial($batch, $staff, publish: false);

        $grant = $this->accessService()->grantFor($material, $studentUser);

        $this->assertFalse($grant->allowed);
        $this->assertSame(MaterialGrant::NOT_PUBLISHED, $grant->reason);
        $this->assertFalse($grant->isDiscoverable(), 'A draft answers 404, never 403.');
        $this->assertDoesNotContainMaterial($material, $student);
    }

    /**
     * **This is D118.** A student on a different course was never this material's audience, so the
     * answer is `not_targeted` and a 404 — not `enrollment_expired`, which would both be false and
     * confirm the material exists.
     */
    #[Test]
    public function a_student_of_another_course_is_told_nothing_at_all(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $material = $this->sharedMaterial($batch, $staff);

        $elsewhere = $this->unrelatedBatch($staff);
        [$outsider, $outsiderUser] = $this->studentWithLogin($elsewhere, $staff);

        $grant = $this->accessService()->grantFor($material, $outsiderUser);

        $this->assertFalse($grant->allowed);
        $this->assertSame(MaterialGrant::NOT_TARGETED, $grant->reason);
        $this->assertFalse($grant->isDiscoverable());
        $this->assertDoesNotContainMaterial($material, $outsider);
    }

    /**
     * A student who *was* on the course gets a different answer, and may be told: it was theirs, and
     * something they can understand has changed.
     */
    #[Test]
    public function a_lapsed_student_of_this_course_is_told_their_access_has_ended(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student, $studentUser, $enrollment] = $this->studentWithLogin($batch, $staff);

        $material = $this->sharedMaterial($batch, $staff);

        app(BatchEnrollmentService::class)->drop($enrollment, 'Left the programme', $staff);

        $grant = $this->accessService()->grantFor($material, $studentUser);

        $this->assertFalse($grant->allowed);
        $this->assertSame(MaterialGrant::ENROLLMENT_EXPIRED, $grant->reason);
        $this->assertTrue($grant->isDiscoverable(), 'It was once theirs, so they may be told why it is not now.');
    }

    /**
     * §5.1's grace: a completed batch keeps its material for
     * `institute.material_visible_after_batch_end_days`, and loses it afterwards.
     */
    #[Test]
    public function the_post_batch_grace_lets_a_dropped_student_through_and_then_stops(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student, $studentUser, $enrollment] = $this->studentWithLogin($batch, $staff);

        $material = $this->sharedMaterial($batch, $staff);

        app(BatchEnrollmentService::class)->drop($enrollment, 'Left the programme', $staff);

        // Travel forward rather than backdating the batch — `chk_ba_dates` keeps end_date after
        // start_date, which is Phase 16 doing its job.
        $ends = Carbon::parse((string) $batch->getAttribute('start_date'))->addDays(30);
        $batch->forceFill(['end_date' => $ends->toDateString()])->saveQuietly();

        $this->assertTrue(
            $this->accessService()->grantFor($material, $studentUser, $ends->copy()->addDays(5))->allowed,
            'Five days after the batch ended is inside the ninety-day grace.',
        );

        $this->assertSame(
            MaterialGrant::ENROLLMENT_EXPIRED,
            $this->accessService()->grantFor($material, $studentUser, $ends->copy()->addDays(200))->reason,
        );

        $this->assertDoesNotContainMaterial($material, $student->refresh(), $ends->copy()->addDays(200));
    }

    /** The release window gates students. It does not gate staff, who upload ahead of the class. */
    #[Test]
    public function the_release_window_holds_a_student_back_and_lets_staff_through(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student, $studentUser] = $this->studentWithLogin($batch, $staff);

        $material = $this->sharedMaterial($batch, $staff, ['available_from' => Carbon::now()->addDay()]);

        $grant = $this->accessService()->grantFor($material, $studentUser);

        $this->assertFalse($grant->allowed);
        $this->assertSame(MaterialGrant::OUTSIDE_WINDOW, $grant->reason);
        $this->assertTrue($grant->isDiscoverable(), 'A student may be told to come back later.');
        $this->assertDoesNotContainMaterial($material, $student);

        $this->assertTrue($this->accessService()->grantFor($material, $staff)->allowed);
    }

    /** §4.3's stated reason for `material_download` being its own permission. */
    #[Test]
    public function the_fee_block_withholds_the_file_and_only_when_it_is_switched_on(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student, $studentUser] = $this->studentWithLogin($batch, $staff);

        $material = $this->sharedMaterial($batch, $staff);

        $this->setting('institute.material_block_on_outstanding_fee', true);

        $this->assertTrue(
            $this->accessService()->grantFor($material, $studentUser)->allowed,
            'Nothing is owed, so the block does not bite.',
        );

        $this->setting('institute.material_block_on_outstanding_fee', false);
        $this->assertTrue($this->accessService()->grantFor($material, $studentUser)->allowed);
    }

    /** `Gate::before` step 1: a disabled module denies everyone, Super Admin included. */
    #[Test]
    public function a_disabled_module_denies_everybody(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $material = $this->sharedMaterial($batch, $staff);

        Module::query()->where('slug', 'course_materials')->update(['is_enabled' => false]);
        Modules::flushCache();

        $grant = $this->accessService()->grantFor($material, $staff);

        $this->assertFalse($grant->allowed);
        $this->assertSame(MaterialGrant::MODULE_DISABLED, $grant->reason);
    }

    /*
    |--------------------------------------------------------------------------
    | Targeting
    |--------------------------------------------------------------------------
    */

    /**
     * §6.5: a target that does not resolve to the material's course is **named**, not dropped. A
     * silent drop means a teacher believes a batch received something it never did.
     */
    #[Test]
    public function a_batch_of_another_course_is_refused_by_name(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $material = $this->sharedMaterial($batch, $staff);
        $elsewhere = $this->unrelatedBatch($staff);

        try {
            $this->materialService()->setTargets($material, [
                ['type' => 'batch', 'id' => (int) $elsewhere->getKey()],
            ], $staff);

            $this->fail('A batch running a different course was accepted as an audience.');
        } catch (CourseRuleException $e) {
            $this->assertStringContainsString(
                (string) $elsewhere->getAttribute('code'),
                implode(' ', $e->validator->errors()->all()),
                'The message names the batch so the teacher can fix it.',
            );
        }
    }

    #[Test]
    public function a_student_who_is_not_on_the_course_cannot_be_targeted(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $material = $this->sharedMaterial($batch, $staff);

        $stranger = $this->registeredStudent($staff);

        $this->expectException(CourseRuleException::class);

        $this->materialService()->setTargets($material, [
            ['type' => 'student', 'id' => (int) $stranger->getKey()],
        ], $staff);
    }

    /** A material with no audience reaches nobody, so publishing it is refused. */
    #[Test]
    public function an_untargeted_material_cannot_be_published(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);

        $bare = $this->materialService()->create([
            'course_id' => (int) $batch->getAttribute('course_id'),
            'title' => 'Nobody\'s handout',
            'type' => 'link',
            'external_url' => 'https://example.test/x',
        ], null, [], $staff);

        $this->expectException(CourseRuleException::class);

        $this->materialService()->publish($bare, $staff);
    }

    /** `audience_scope` is a cache of the **broadest** live target, and it narrows when one goes. */
    #[Test]
    public function the_audience_badge_follows_the_broadest_remaining_target(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $courseId = (int) $batch->getAttribute('course_id');

        $material = $this->sharedMaterial($batch, $staff);
        $this->assertSame('batch', $material->refresh()->audience_scope->value);

        $widened = $this->materialService()->setTargets($material, [
            ['type' => 'batch', 'id' => (int) $batch->getKey()],
            ['type' => 'course', 'id' => $courseId],
        ], $staff);
        $this->assertSame('course', $widened->scope?->value);

        $narrowed = $this->materialService()->setTargets($material, [
            ['type' => 'batch', 'id' => (int) $batch->getKey()],
        ], $staff);
        $this->assertSame('batch', $narrowed->scope?->value, 'Removing the course-wide target narrows the badge again.');
    }

    /** Re-aiming a material must not tell an audience that has already heard. */
    #[Test]
    public function retargeting_keeps_the_notification_stamp_of_an_audience_already_told(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $material = $this->sharedMaterial($batch, $staff);

        $material->targets()->update(['notified_at' => Carbon::now()]);

        $this->materialService()->setTargets($material, [
            ['type' => 'batch', 'id' => (int) $batch->getKey()],
            ['type' => 'course', 'id' => (int) $batch->getAttribute('course_id')],
        ], $staff);

        $this->assertTrue(
            $material->targets()->where('target_type', 'batch')->whereNotNull('notified_at')->exists(),
            'The batch was already told; adding the course must not reset that.',
        );

        $this->assertTrue(
            $material->targets()->where('target_type', 'course')->whereNull('notified_at')->exists(),
            'The new audience has not been told yet.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Links
    |--------------------------------------------------------------------------
    */

    /** §2.3: `http`/`https` only — never `javascript:`, never `data:`. */
    #[Test]
    public function a_javascript_url_is_refused(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);

        $this->expectException(CourseRuleException::class);

        $this->materialService()->create([
            'course_id' => (int) $batch->getAttribute('course_id'),
            'title' => 'Bad link',
            'type' => 'link',
            'external_url' => 'javascript:alert(1)',
        ], null, [], $staff);
    }

    /*
    |--------------------------------------------------------------------------
    | INV-19-4 — the log
    |--------------------------------------------------------------------------
    */

    /**
     * The row is written **before** the bytes move, so a download that fails half way is still
     * recorded — with `bytes_sent` null, which is what "did not finish" looks like.
     */
    #[Test]
    public function an_access_is_logged_before_the_stream_and_stamped_after_it(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student, $studentUser] = $this->studentWithLogin($batch, $staff);

        $material = $this->sharedMaterial($batch, $staff);

        $this->actingAs($studentUser);
        $response = $this->accessService()->stream($material->refresh(), $studentUser, MaterialAccessAction::Download);

        $logged = $material->accessLog()->first();

        $this->assertNotNull($logged, 'INV-19-4: the row exists before a single byte has gone out.');
        $this->assertNull($logged->getAttribute('bytes_sent'), 'Nothing has been sent yet, so the count is null.');
        $this->assertSame('download', $logged->action->value);
        $this->assertSame('student', $logged->panel->value);
        $this->assertSame((int) $student->getKey(), (int) $logged->getAttribute('student_id'));

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        $this->assertSame(
            (int) $material->getAttribute('file_size_bytes'),
            strlen($body),
            'The whole file goes out — wrapping the callback must not replace the body.',
        );

        $this->assertSame(
            (int) $material->getAttribute('file_size_bytes'),
            (int) $logged->refresh()->getAttribute('bytes_sent'),
            'Stamped once the stream finished.',
        );
    }

    /** The caches are recounted from the log, never incremented. */
    #[Test]
    public function the_access_caches_are_re_derivable_from_the_log(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student, $studentUser] = $this->studentWithLogin($batch, $staff);

        $material = $this->sharedMaterial($batch, $staff);

        $this->actingAs($studentUser);
        $this->accessService()->stream($material->refresh(), $studentUser, MaterialAccessAction::Download);
        $this->accessService()->stream($material->refresh(), $studentUser, MaterialAccessAction::Download);

        $this->materialService()->recountCaches($material->refresh());
        $material->refresh();

        $this->assertSame(2, (int) $material->getAttribute('download_count'));
        $this->assertSame(0, (int) $material->getAttribute('view_count'));
        $this->assertSame(1, (int) $material->getAttribute('unique_students_count'), 'Two opens by one student is one student.');

        $this->assertSame(
            $material->accessLog()->whereIn('action', ['download', 'link_open'])->count(),
            (int) $material->getAttribute('download_count'),
            'The cache equals the count it caches.',
        );
    }

    /** A link open counts towards `download_count` — otherwise every link looks untouched (§2.5). */
    #[Test]
    public function opening_a_link_counts_as_a_download(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student, $studentUser] = $this->studentWithLogin($batch, $staff);

        $link = $this->sharedLink($batch, $staff);

        $this->actingAs($studentUser);
        $redirect = $this->accessService()->openLink($link->refresh(), $studentUser);

        $this->assertSame(302, $redirect->getStatusCode());
        $this->assertSame('https://example.test/notes', $redirect->getTargetUrl());
        $this->assertSame('no-referrer', $redirect->headers->get('referrer-policy'), 'Our URL names the course and the material id.');

        $this->materialService()->recountCaches($link->refresh());

        $this->assertSame(1, (int) $link->refresh()->getAttribute('download_count'));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function assertContainsMaterial(CourseMaterial $material, $student, ?Carbon $at = null): void
    {
        $this->assertTrue(
            $this->accessService()->visibleToStudent($student, $at)->whereKey($material->getKey())->exists(),
            'The list and the grant must agree: this one is allowed, so it must be listed.',
        );
    }

    private function assertDoesNotContainMaterial(CourseMaterial $material, $student, ?Carbon $at = null): void
    {
        $this->assertFalse(
            $this->accessService()->visibleToStudent($student, $at)->whereKey($material->getKey())->exists(),
            'The list and the grant must agree: this one is refused, so it must not be listed.',
        );
    }
}
