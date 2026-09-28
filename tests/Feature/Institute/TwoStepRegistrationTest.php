<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Enums\PaymentMethod;
use App\Enums\StudentFeeType;
use App\Models\Institute\Course;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAdmission;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeePayment;
use App\Models\User;
use App\Notifications\Institute\PortalLoginCreatedNotification;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\TestCase;

/**
 * Registration in two steps: the student and their login, then their courses, bill and first payment.
 *
 * Three things here are worth more than the rest, because each one is a way the money could be
 * quietly wrong:
 *
 *   - **the tax is computed once on the basket and allocated**, never computed per line. A sum of
 *     rounded figures is not the rounding of the sum, and `generateStructure()` aborts the whole fee
 *     structure when the heads do not add to `net_payable`;
 *   - **the payment is split across the courses in proportion to what each owes**, so a student who
 *     paid something towards three courses has paid something towards three courses — not all of it
 *     towards the first, which is what a single receipt would have recorded;
 *   - **the extra fee and the tax earn no commission.** `StudentFeeType::Other` answers true to
 *     `isCommissionableByDefault()`, so booking either as `Other` would have paid a partner a share
 *     of the government's money. Both have their own case and both answer false.
 */
final class TwoStepRegistrationTest extends TestCase
{
    use BuildsAdmissions;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Step 1
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function step_one_creates_the_student_and_a_working_login(): void
    {
        Notification::fake();

        $actor = $this->createSuperAdmin();

        $this->actingAs($actor)
            ->post(route('admin.students.register.store'), $this->studentPayload())
            ->assertRedirect();

        $student = Student::query()->where('email', 'ayesha@example.test')->sole();

        $this->assertNotNull($student->user_id, 'No login was created alongside the student.');

        $user = $student->loadMissing('user')->user;

        $this->assertTrue($user->must_change_password, 'The operator-set password must be temporary.');
        $this->assertTrue($user->hasRole('Student'));
        $this->assertTrue(
            password_verify('Str0ng-Passw0rd!', (string) $user->password),
            'The password the operator typed is not the one that was stored.',
        );
    }

    /**
     * No credentials e-mail when the operator chose the password.
     *
     * Mailing a password the operator already knows would put it in an inbox and a mail log as well,
     * for no gain. Where no password is given the old behaviour is untouched — that is the next test.
     */
    #[Test]
    public function step_one_sends_no_credentials_email_because_the_operator_has_the_password(): void
    {
        Notification::fake();

        $actor = $this->createSuperAdmin();

        $this->actingAs($actor)->post(route('admin.students.register.store'), $this->studentPayload())->assertRedirect();

        Notification::assertNothingSent();
    }

    /** The generated-and-mailed path is unchanged for every other caller. */
    #[Test]
    public function creating_a_login_without_a_password_still_generates_and_mails_one(): void
    {
        Notification::fake();

        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $user = $this->studentService()->createLogin($student, $actor);

        $this->assertNotNull($user);
        Notification::assertSentTo($user, PortalLoginCreatedNotification::class);
    }

    #[Test]
    public function step_one_refuses_an_email_that_already_has_an_account(): void
    {
        $actor = $this->createSuperAdmin();
        User::factory()->create(['email' => 'ayesha@example.test']);

        $this->actingAs($actor)
            ->post(route('admin.students.register.store'), $this->studentPayload())
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Student::query()->where('email', 'ayesha@example.test')->count());
    }

    #[Test]
    public function step_one_refuses_mismatched_passwords(): void
    {
        $actor = $this->createSuperAdmin();

        $this->actingAs($actor)
            ->post(route('admin.students.register.store'), array_merge($this->studentPayload(), [
                'password_confirmation' => 'something-else',
            ]))
            ->assertSessionHasErrors('password');

        $this->assertSame(0, Student::query()->where('email', 'ayesha@example.test')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2 — courses, bill, money
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function step_two_enrols_on_every_ticked_course_and_bills_them(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->registeredStudent($actor);

        $courses = [
            $this->publishedCourse(null, ['course_fee' => '30000.00', 'admission_fee' => '2000.00', 'registration_fee' => '1000.00'], $actor),
            $this->publishedCourse(null, ['course_fee' => '18000.00', 'admission_fee' => '2000.00', 'registration_fee' => '1000.00'], $actor),
        ];

        $this->actingAs($actor)
            ->post(route('admin.students.register.complete', $student), $this->coursePayload($courses))
            ->assertRedirect(route('admin.students.show', $student));

        $admissions = StudentAdmission::query()->where('student_id', $student->getKey())->get();

        $this->assertCount(2, $admissions, 'One admission per ticked course.');

        // 30000 + 18000 + one admission fee + one registration fee.
        $this->assertSame(
            '51000.00',
            Money::sum($admissions->pluck('net_payable')->map(static fn ($v): string => (string) $v)->all()),
        );

        $this->assertGreaterThan(
            0,
            StudentFee::query()->whereIn('student_admission_id', $admissions->pluck('id'))->count(),
            'The bill was not raised.',
        );
    }

    /**
     * The one that proves the arithmetic. A rate that does not divide evenly, across three courses.
     */
    #[Test]
    public function the_tax_is_computed_once_on_the_basket_and_its_shares_add_back_exactly(): void
    {
        $actor = $this->createSuperAdmin();
        $this->setting('institute.tax_rate', '7.7777');
        $student = $this->registeredStudent($actor);

        $courses = [
            $this->publishedCourse(null, ['course_fee' => '333.33', 'admission_fee' => '0.00', 'registration_fee' => '0.00'], $actor),
            $this->publishedCourse(null, ['course_fee' => '333.33', 'admission_fee' => '0.00', 'registration_fee' => '0.00'], $actor),
            $this->publishedCourse(null, ['course_fee' => '333.34', 'admission_fee' => '0.00', 'registration_fee' => '0.00'], $actor),
        ];

        $this->actingAs($actor)
            ->post(route('admin.students.register.complete', $student), $this->coursePayload($courses))
            ->assertRedirect();

        $admissions = StudentAdmission::query()->where('student_id', $student->getKey())->get();

        $storedTax = Money::sum($admissions->pluck('tax_amount')->map(static fn ($v): string => (string) $v)->all());

        // 1000.00 at 7.7777% is 77.78. Computed per line it would be 77.79 — one paisa invented, and
        // enough to make `generateStructure()` refuse the whole structure.
        $this->assertSame('77.78', $storedTax, 'The tax shares do not add back to the tax on the basket.');

        $this->assertSame(
            '1077.78',
            Money::sum($admissions->pluck('net_payable')->map(static fn ($v): string => (string) $v)->all()),
        );
    }

    /**
     * That the charges balance at all is the proof the fee structure was issuable.
     *
     * `generateStructure()` sums the heads it wrote and compares them with `net_payable`, aborting
     * the transaction when they differ — so an admission with charges is an admission whose extra
     * fee and tax were accounted for.
     */
    #[Test]
    public function the_extra_fee_and_the_tax_appear_as_their_own_charge_heads(): void
    {
        $actor = $this->createSuperAdmin();
        $this->setting('institute.extra_fee_amount', '500.00');
        $this->setting('institute.tax_rate', '10.0000');
        $student = $this->registeredStudent($actor);

        $course = $this->publishedCourse(null, ['course_fee' => '10000.00', 'admission_fee' => '0.00', 'registration_fee' => '0.00'], $actor);

        $this->actingAs($actor)
            ->post(route('admin.students.register.complete', $student), $this->coursePayload([$course]))
            ->assertRedirect();

        $admission = StudentAdmission::query()->where('student_id', $student->getKey())->sole();

        $this->assertSame('500.00', (string) $admission->extra_fee);
        // (10000 + 500) at 10% = 1050.00
        $this->assertSame('1050.00', (string) $admission->tax_amount);
        $this->assertSame('11550.00', (string) $admission->net_payable);

        $heads = StudentFee::query()
            ->where('student_admission_id', $admission->getKey())
            ->pluck('fee_type')
            ->map(static fn ($t): string => $t instanceof StudentFeeType ? $t->value : (string) $t)
            ->all();

        $this->assertContains(StudentFeeType::ExtraFee->value, $heads);
        $this->assertContains(StudentFeeType::Tax->value, $heads);
    }

    /** Neither new head is the institute's earning to share with a partner. */
    #[Test]
    public function neither_the_extra_fee_nor_the_tax_earns_commission(): void
    {
        $this->assertFalse(StudentFeeType::ExtraFee->isCommissionableByDefault());
        $this->assertFalse(StudentFeeType::Tax->isCommissionableByDefault());
        $this->assertNull(StudentFeeType::ExtraFee->commissionSettingKey());
        $this->assertNull(StudentFeeType::Tax->commissionSettingKey());

        // The trap this avoids: `Other` IS commissionable, so either of them booked as `Other` would
        // have paid a partner a share of a tax.
        $this->assertTrue(StudentFeeType::Other->isCommissionableByDefault());
    }

    /**
     * One typed figure, spread across the courses in proportion to what each owes.
     */
    #[Test]
    public function the_payment_is_split_across_the_courses_and_adds_back_exactly(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->registeredStudent($actor);

        $courses = [
            $this->publishedCourse(null, ['course_fee' => '30000.00', 'admission_fee' => '0.00', 'registration_fee' => '0.00'], $actor),
            $this->publishedCourse(null, ['course_fee' => '10000.00', 'admission_fee' => '0.00', 'registration_fee' => '0.00'], $actor),
        ];

        $this->actingAs($actor)
            ->post(route('admin.students.register.complete', $student), array_merge($this->coursePayload($courses), [
                'payment_amount' => '10000.00',
                'payment_method' => PaymentMethod::Cash->value,
                'paid_on' => now()->toDateString(),
            ]))
            ->assertRedirect();

        $admissions = StudentAdmission::query()->where('student_id', $student->getKey())->get()->keyBy('course_id');

        $this->assertSame(
            '10000.00',
            Money::sum($admissions->pluck('paid_amount')->map(static fn ($v): string => (string) $v)->all()),
            'The receipts do not add back to what was typed.',
        );

        // 30000 : 10000 is 3 : 1, so 7500 and 2500.
        $this->assertSame('7500.00', (string) $admissions[$courses[0]->getKey()]->paid_amount);
        $this->assertSame('2500.00', (string) $admissions[$courses[1]->getKey()]->paid_amount);

        $this->assertGreaterThan(0, StudentFeePayment::query()->count(), 'No receipt was written.');
    }

    #[Test]
    public function paying_more_than_the_bill_is_refused_and_nothing_is_written(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->registeredStudent($actor);
        $course = $this->publishedCourse(null, ['course_fee' => '1000.00', 'admission_fee' => '0.00', 'registration_fee' => '0.00'], $actor);

        $this->actingAs($actor)
            ->post(route('admin.students.register.complete', $student), array_merge($this->coursePayload([$course]), [
                'payment_amount' => '99999.00',
                'payment_method' => PaymentMethod::Cash->value,
            ]))
            ->assertSessionHasErrors('payment_amount');

        $this->assertSame(
            0,
            StudentAdmission::query()->where('student_id', $student->getKey())->count(),
            'The refused payment left admissions behind — the whole step must be one transaction.',
        );
    }

    #[Test]
    public function registering_with_no_payment_bills_the_student_and_takes_nothing(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->registeredStudent($actor);
        $course = $this->publishedCourse(null, ['course_fee' => '5000.00'], $actor);

        $this->actingAs($actor)
            ->post(route('admin.students.register.complete', $student), $this->coursePayload([$course]))
            ->assertRedirect();

        $this->assertSame(0, StudentFeePayment::query()->count());
        $this->assertGreaterThan(0, StudentAdmission::query()->where('student_id', $student->getKey())->sole()->charged_amount);
    }

    #[Test]
    public function a_discount_without_a_reason_is_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->registeredStudent($actor);
        $course = $this->publishedCourse(null, [], $actor);

        $this->actingAs($actor)
            ->post(route('admin.students.register.complete', $student), array_merge($this->coursePayload([$course]), [
                'discount_amount' => '500.00',
                'discount_reason' => '',
            ]))
            ->assertSessionHasErrors('discount_reason');
    }

    #[Test]
    public function a_user_without_the_admission_permission_cannot_complete_a_registration(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->registeredStudent($actor);
        $course = $this->publishedCourse(null, [], $actor);

        $outsider = $this->createUserWithPermissions(['students.view_any', 'students.view', 'students.edit']);

        $this->actingAs($outsider)
            ->post(route('admin.students.register.complete', $student), $this->coursePayload([$course]))
            ->assertForbidden();

        $this->assertSame(0, StudentAdmission::query()->where('student_id', $student->getKey())->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function studentPayload(): array
    {
        return [
            'name' => 'Ayesha Khan',
            'phone' => '03001234567',
            'email' => 'ayesha@example.test',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ];
    }

    /**
     * @param  list<Course>  $courses
     * @return array<string, mixed>
     */
    private function coursePayload(array $courses): array
    {
        return [
            'course_ids' => array_map(static fn ($c): int => (int) $c->getKey(), $courses),
            'idempotency_key' => (string) Str::ulid(),
        ];
    }

    /** A student who already has a login — the state step 2 begins from. */
    private function registeredStudent(User $actor): Student
    {
        $this->actingAs($actor)->post(route('admin.students.register.store'), $this->studentPayload());

        return Student::query()->where('email', 'ayesha@example.test')->sole();
    }
}
