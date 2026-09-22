<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Enums\AdmissionStage;
use App\Enums\CourseInquiryStatus;
use App\Enums\StudentApplicationStatus;
use App\Enums\StudentStatus;
use App\Models\Institute\StudentAdmission;
use App\Services\Institute\BatchService;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\TestCase;

/**
 * §68's pipeline (phase-14-17 §2.30.5, §2.31, §6.6).
 *
 * **The stage column is the only answer to "where are we", and every step checks it.** A pipeline
 * whose steps can be skipped produces an active student with no registration number and no fee — a
 * record the rest of the system then has to cope with for ever.
 *
 * **The agreed figures freeze on the first charge (INV-I2)** because a commission has been computed
 * from them by then, and money may already have moved.
 */
final class AdmissionPipelineTest extends TestCase
{
    use BuildsAdmissions;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | The steps, in order
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function converting_an_application_creates_a_student_and_an_admission_in_one_transaction(): void
    {
        $actor = $this->createSuperAdmin();
        $application = $this->publicApplication(actor: $actor);

        // D-IN-7: the public form creates one application row and nothing else.
        $this->assertDatabaseCount('students', 0);

        $admission = $this->applicationService()->convert($application, [], $actor);

        $this->assertSame(AdmissionStage::Application, $admission->stage);
        $this->assertNotEmpty($admission->admission_number);
        $this->assertNotEmpty($admission->student->student_code);
        $this->assertNull($admission->student->registration_number, 'the registration number comes at step four');
        $this->assertSame(StudentStatus::Applied, $admission->student->status);

        $application->refresh();
        $this->assertSame(StudentApplicationStatus::Converted, $application->status);
        $this->assertSame($admission->getKey(), $application->converted_admission_id);
    }

    #[Test]
    public function converting_twice_returns_the_same_admission_and_creates_no_second_student(): void
    {
        $actor = $this->createSuperAdmin();
        $application = $this->publicApplication(actor: $actor);

        $first = $this->applicationService()->convert($application, [], $actor);
        $second = $this->applicationService()->convert($application->refresh(), [], $actor);

        $this->assertTrue($first->is($second));
        // Two people clicking Convert on the same screen must not make two students.
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('student_admissions', 1);
    }

    #[Test]
    public function every_step_asserts_the_one_before_it(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->admission(actor: $actor);

        // Straight from `application` to `active` is the failure this pipeline exists to prevent.
        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessageMatches('/cannot go straight to Active/');

        $this->admissionService()->activate($admission, $actor);
    }

    #[Test]
    public function registration_issues_the_number_once_per_student_not_once_per_admission(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->admission(actor: $actor);

        $registered = $this->admissionService()->register($admission, $actor);
        $number = $registered->student->registration_number;

        $this->assertNotEmpty($number);
        $this->assertSame(AdmissionStage::Registration, $registered->stage);
        $this->assertSame(StudentStatus::Registered, $registered->student->refresh()->status);

        // A second course for the same person is the same registered student.
        $second = $this->admissionService()->create(
            $registered->student,
            $this->publishedCourse(actor: $actor),
            [],
            null,
            $actor,
        );

        $this->admissionService()->register($second, $actor);

        $this->assertSame($number, $registered->student->refresh()->registration_number);
    }

    #[Test]
    public function one_live_admission_per_student_per_course_and_a_terminal_one_frees_the_pair(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->admission(actor: $actor);
        $student = $admission->student;
        $course = $admission->course;

        try {
            $this->admissionService()->create($student, $course, [], null, $actor);
            $this->fail('a second live admission to the same course was accepted');
        } catch (CourseRuleException $e) {
            $this->assertStringContainsString('already has a live admission', $e->getMessage());
        }

        $this->admissionService()->withdraw($admission, 'Moved city', $actor);

        // Re-admission is the normal case, not the exception.
        $again = $this->admissionService()->create($student->refresh(), $course, [], null, $actor);

        $this->assertSame(AdmissionStage::Application, $again->stage);
        $this->assertDatabaseCount('student_admissions', 2);
    }

    /*
    |--------------------------------------------------------------------------
    | The figures
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_agreed_figures_are_computed_through_money_and_never_taken_from_the_form(): void
    {
        $actor = $this->createSuperAdmin();

        $admission = $this->admission(null, [
            'course_fee' => '30000.00',
            'admission_fee' => '2000.00',
            'registration_fee' => '1000.00',
            'discount_amount' => '5000.00',
            'scholarship_amount' => '2000.00',
            // A total the form claims, and which is wrong. It must be ignored.
            'total_amount' => '1.00',
            'net_payable' => '1.00',
        ], $actor);

        $this->assertSame('33000.00', $admission->total_amount);
        $this->assertSame('26000.00', $admission->net_payable);
        $this->assertSame('23000.00', $admission->course_fee_net_payable,
            'the generated column is the commission engine\'s denominator and is derived, never written');
    }

    #[Test]
    public function nothing_is_sold_below_free(): void
    {
        $actor = $this->createSuperAdmin();

        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessageMatches('/Nothing is sold below free/');

        $this->admission(null, [
            'course_fee' => '10000.00',
            'admission_fee' => '0.00',
            'registration_fee' => '0.00',
            'discount_amount' => '9000.00',
            'scholarship_amount' => '5000.00',
        ], $actor);
    }

    #[Test]
    public function the_figures_freeze_once_a_charge_has_been_issued(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->admission(actor: $actor);

        // Before the lock: editable, with a reason.
        $this->admissionService()->updateFigures($admission, [
            'course_fee' => '25000.00',
        ], 'Agreed a lower fee at the counter', $actor);

        $this->assertSame('25000.00', $admission->refresh()->course_fee);

        // Phase 18 stamps this on the first charge. Nothing in this phase writes it, so the test does
        // what that phase will.
        StudentAdmission::query()->whereKey($admission->getKey())->update(['figures_locked_at' => now()]);

        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessageMatches('/locked when the first charge was issued/');

        $this->admissionService()->updateFigures($admission->refresh(), [
            'course_fee' => '1000.00',
        ], 'Trying to change it after the fact', $actor);
    }

    #[Test]
    public function the_four_money_caches_refuse_a_write_from_outside_the_fee_service(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->admission(actor: $actor);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/written only by StudentFeeService/');

        $admission->forceFill(['paid_amount' => '5000.00'])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Activation — the one place §68's ordering rule is applied
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function activation_names_every_unmet_condition_rather_than_just_refusing(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->admission(actor: $actor);

        $gaps = $this->admissionService()->activationGaps($admission);

        $this->assertContains('no registration number has been issued', $gaps);
        $this->assertContains('no batch has been assigned', $gaps);
    }

    #[Test]
    public function the_fee_rule_is_read_from_the_setting_and_nowhere_else(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->admission(actor: $actor);

        $this->admissionService()->register($admission, $actor);

        // A real batch: Phase 16 attached the foreign key this column always declared, so an invented
        // id is now refused by the database rather than quietly accepted.
        $batch = app(BatchService::class)->create([
            'code' => 'FEE-RULE-1',
            'name' => 'Fee rule batch',
            'course_id' => $admission->course_id,
            'start_date' => now()->toDateString(),
            'student_capacity' => 10,
            'delivery_mode' => 'physical',
        ], $actor);

        StudentAdmission::query()->whereKey($admission->getKey())->update(['batch_id' => $batch->getKey()]);

        $this->setting('institute.require_fee_before_activation', 'any_payment');
        $this->assertContains('nothing has been charged yet', $this->admissionService()->activationGaps($admission->refresh()));

        // With the rule switched off, a batch seat is enough — which is the whole point of the setting.
        $this->setting('institute.require_fee_before_activation', 'none');
        $this->assertSame([], $this->admissionService()->activationGaps($admission->refresh()));
    }

    /*
    |--------------------------------------------------------------------------
    | Cancelling and withdrawing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function cancelling_is_refused_while_money_has_cleared(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->admission(actor: $actor);

        // What Phase 18 writes when a receipt clears.
        StudentAdmission::query()->whereKey($admission->getKey())->update([
            'charged_amount' => '30000.00',
            'paid_amount' => '10000.00',
        ]);

        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessageMatches('/refund or transfer it first/');

        $this->admissionService()->cancel($admission->refresh(), 'Changed their mind', $actor);
    }

    #[Test]
    public function cancelling_and_withdrawing_both_demand_a_reason(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->admission(actor: $actor);

        try {
            $this->admissionService()->withdraw($admission, '   ', $actor);
            $this->fail('a withdrawal with no reason was accepted');
        } catch (CourseRuleException $e) {
            $this->assertStringContainsString('takes a reason', $e->getMessage());
        }

        $withdrawn = $this->admissionService()->withdraw($admission, 'Took a job instead', $actor);

        $this->assertSame(AdmissionStage::Withdrawn, $withdrawn->stage);
        $this->assertSame('Took a job instead', $withdrawn->withdrawal_reason);
        $this->assertNull($withdrawn->active_guard, 'a terminal admission frees the student/course pair');
    }

    /*
    |--------------------------------------------------------------------------
    | The funnel does not lose a step
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function converting_an_application_closes_the_inquiry_behind_it(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);
        $inquiry = $this->inquiry(['course_id' => $course->getKey()], $actor);

        $application = $this->staffApplication($course, [], $actor);
        $application->forceFill(['course_inquiry_id' => $inquiry->getKey()])->save();

        $admission = $this->applicationService()->convert($application->refresh(), [], $actor);

        $inquiry->refresh();

        $this->assertSame(CourseInquiryStatus::AdmissionConfirmed, $inquiry->status);
        $this->assertSame($admission->student_id, $inquiry->converted_student_id);
        $this->assertNotNull($inquiry->converted_at);
    }
}
