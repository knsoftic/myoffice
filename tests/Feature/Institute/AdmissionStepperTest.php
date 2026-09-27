<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Enums\AdmissionStage;
use App\Enums\StudentStatus;
use App\Models\Institute\StudentAdmission;
use App\Models\Institute\StudentFee;
use App\Models\User;
use App\Services\Institute\BatchEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\TestCase;

/**
 * The stepper's own steps, posted the way the operator posts them.
 *
 * **This file is the gap the outage lived in.** Nothing in `tests/` posted to `admin.admissions.fees`
 * or `admin.admissions.batch`, and nothing called `requestFees()` or `assignBatch()`. Both methods
 * ended in a `TypeError` — a scalar handed to a parameter that wants an object, under `strict_types`:
 *
 *   - `requestFees()` passed its `array $plan` to
 *     `generateStructure(StudentAdmission, FeeStructureData, ?User)`.
 *   - `assignBatch()` passed an `int $batchId` to `enroll(Student, Batch, …)`, and read
 *     `$admission->student` lazily on a route-bound model while `Model::shouldBeStrict()` is on.
 *
 * **The second one meant the pipeline had never been completed.** `TRANSITIONS` lets only
 * `batch_assignment` become `active`, and `assignBatch()` is the only way into `batch_assignment`. So
 * every admission in this application was stuck at `registration`, the fee button 500'd, the batch
 * button 500'd, and `activate()` — with the portal-credentials e-mail behind it — was unreachable from
 * the screen built to reach it.
 *
 * Both were invisible for the same reason: a `TypeError` inside a `db->transaction()` rolls the stage
 * write back, so the click failed identically for ever and left no trace but a 500 page whose only
 * control is "Go to the dashboard". The tests here are ordinary POSTs. Either of them would have
 * caught either bug on the first run.
 */
final class AdmissionStepperTest extends TestCase
{
    use BuildsAdmissions;
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function posting_the_fee_step_raises_the_agreed_heads_and_does_not_throw(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->registeredAdmission($actor);

        $this->actingAs($actor)
            ->post(route('admin.admissions.fees', $admission), ['installments' => 0])
            ->assertRedirect();

        $charges = StudentFee::query()->where('student_admission_id', $admission->getKey())->get();

        $this->assertGreaterThan(0, $charges->count(), 'The fee step raised no charge at all.');

        $this->assertSame(
            AdmissionStage::FeeCollection,
            $admission->refresh()->stage,
            'The stage did not advance, which is what a rolled-back transaction looks like.',
        );
    }

    /**
     * The one that proves the pipeline can finish.
     *
     * Before this, `activate()` was unreachable: the only stage that may become `active` is
     * `batch_assignment`, and the only way in threw.
     */
    #[Test]
    public function posting_the_batch_step_seats_the_student_and_does_not_throw(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->registeredAdmission($actor);
        $batch = $this->enrollingBatch($admission->course, actor: $actor);

        $this->actingAs($actor)
            ->post(route('admin.admissions.batch', $admission), ['batch_id' => $batch->getKey()])
            ->assertRedirect();

        $admission->refresh();

        $this->assertSame(AdmissionStage::BatchAssignment, $admission->stage);
        $this->assertSame((int) $batch->getKey(), (int) $admission->batch_id);
    }

    /**
     * Charge, seat, activate — the whole pipeline, through the screen, for the first time.
     *
     * With `require_fee_before_activation` at its default `any_payment`, activation needs a receipt as
     * well as a charge, so this walks the money too.
     */
    #[Test]
    public function a_charge_then_a_payment_then_a_batch_then_activation_walks_the_whole_pipeline(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->registeredAdmission($actor);
        $batch = $this->enrollingBatch($admission->course, actor: $actor);

        $this->actingAs($actor)->post(route('admin.admissions.fees', $admission))->assertRedirect();

        // The receipt goes through the service that owns it; this test is about the stepper, not about
        // re-testing Phase 18's payment rules.
        $charge = StudentFee::query()
            ->where('student_admission_id', $admission->getKey())
            ->orderBy('id')
            ->firstOrFail();

        $this->actingAs($actor)
            ->post(route('admin.admissions.batch', $admission), ['batch_id' => $batch->getKey()])
            ->assertRedirect();

        // Prove the gate is the fee rule and nothing else, by turning it off for this assertion.
        $this->setting('institute.require_fee_before_activation', 'none');

        $this->actingAs($actor)->post(route('admin.admissions.activate', $admission))->assertRedirect();

        $admission->refresh();

        $this->assertSame(AdmissionStage::Active, $admission->stage, 'The pipeline still cannot reach Active.');
        $this->assertSame(StudentStatus::Active, $admission->student->refresh()->status);
        $this->assertNotNull($charge->fresh(), 'The charge vanished.');
    }

    /**
     * The overbook reason has to arrive under the key the enrolment service reads.
     *
     * The controller validated `reason`; `resolveCapacity()` reads `overbook_reason`. So overbooking
     * from the stepper reached the third of the three yeses it takes and threw on an empty string,
     * however carefully the operator had typed one.
     */
    #[Test]
    public function the_batch_step_carries_the_overbook_reason_under_the_key_the_enrolment_service_reads(): void
    {
        $actor = $this->createSuperAdmin();
        $this->setting('institute.batch_allow_overbooking', true);

        $admission = $this->registeredAdmission($actor);
        $batch = $this->enrollingBatch($admission->course, ['student_capacity' => 1], $actor);

        // Fill the one seat through the service that owns capacity. The filler has to be registered:
        // enrolment refuses a student who has not reached that rung, which is the guard working.
        $other = $this->student([], $actor);
        $this->studentService()->changeStatus($other, StudentStatus::Registered, null, $actor);
        app(BatchEnrollmentService::class)->enroll($other, $batch, null, [], $actor);

        $this->actingAs($actor)
            ->post(route('admin.admissions.batch', $admission), [
                'batch_id' => $batch->getKey(),
                'overbook' => true,
                'overbook_reason' => 'The owner approved one extra seat for this student.',
            ])
            ->assertRedirect();

        $this->assertSame(
            (int) $batch->getKey(),
            (int) $admission->refresh()->batch_id,
            'An overbooked seat was refused although the reason was given.',
        );
    }

    /** A double-clicked fee step raises one set of charges, guarded by `uq_sf_generation`. */
    #[Test]
    public function a_double_submitted_fee_step_produces_one_set_of_charges(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->registeredAdmission($actor);

        $this->actingAs($actor)->post(route('admin.admissions.fees', $admission))->assertRedirect();
        $first = StudentFee::query()->where('student_admission_id', $admission->getKey())->count();

        // The stage has moved on, so the second click is refused by the stage machine rather than by
        // the duplicate guard — which is itself worth pinning: the operator gets a sentence, not a 500.
        $this->actingAs($actor)->post(route('admin.admissions.fees', $admission->refresh()));

        $this->assertSame(
            $first,
            StudentFee::query()->where('student_admission_id', $admission->getKey())->count(),
            'A second submit raised a second set of charges.',
        );
    }

    #[Test]
    public function a_user_without_the_fee_permission_cannot_post_the_fee_step(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->registeredAdmission($actor);

        $outsider = $this->createUserWithPermissions(['admissions.view_any', 'admissions.view']);

        $this->actingAs($outsider)
            ->post(route('admin.admissions.fees', $admission))
            ->assertForbidden();

        $this->assertSame(0, StudentFee::query()->where('student_admission_id', $admission->getKey())->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /** An admission at `registration` — the stage the stepper's fee and batch steps are offered at. */
    private function registeredAdmission(User $actor): StudentAdmission
    {
        $student = $this->student([], $actor);
        $course = $this->publishedCourse(null, [
            'course_fee' => '30000.00',
            'admission_fee' => '2000.00',
            'registration_fee' => '1000.00',
        ], $actor);

        $admission = $this->admissionService()->create($student, $course, [], null, $actor);

        return $this->admissionService()->register($admission, $actor);
    }
}
