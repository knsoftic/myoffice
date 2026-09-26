<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Models\Institute\StudentAdmission;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\TestCase;

/**
 * A basket of courses is N admissions, not one admission with N courses ([D172]).
 *
 * The operator ticks three courses and quotes one figure. What gets written is still one admission per
 * course, because everything downstream of an admission is per course: `batches.course_id` is NOT NULL,
 * `admissions.batch_id` is one scalar, `completed_on` is one date, a certificate is drafted from one
 * enrolment, and `uq_sadm_live` is `(student_id, course_id, active_guard)`. A row covering three
 * courses would make the first three unanswerable and the fourth unenforceable.
 *
 * **The atomicity test is the reason this file exists.** Before `createMany()`, the only way to admit
 * three courses was to call `create()` three times — and `create()` opens its own transaction with its
 * duplicate guard *outside* it. A basket whose third course was already live therefore committed the
 * first two and then threw a validation error, which the operator reads as "nothing was saved". Two
 * admissions with their own numbers would be sitting there, and the next attempt would refuse those
 * two as well. `nothing_is_created_when_one_course_in_the_basket_is_already_live` is the assertion that
 * this cannot happen.
 *
 * **The money test is the other half.** One typed discount has to land in N `discount_amount` columns,
 * each with its own CHECK ceiling, and the shares have to add back to exactly what was typed. Figures
 * here are chosen so the division does not come out even, because a split that only works on round
 * numbers is a split that loses money on Tuesday.
 */
final class MultiCourseAdmissionTest extends TestCase
{
    use BuildsAdmissions;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function three_ticked_courses_become_three_admissions_each_priced_from_its_own_course(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $web = $this->publishedCourse(null, ['course_fee' => '30000.00'], $actor);
        $design = $this->publishedCourse(null, ['course_fee' => '18000.00'], $actor);
        $office = $this->publishedCourse(null, ['course_fee' => '9000.00'], $actor);

        $created = $this->admissionService()->createMany(
            $student,
            [
                ['course_id' => $web->getKey()],
                ['course_id' => $design->getKey()],
                ['course_id' => $office->getKey()],
            ],
            [],
            $actor,
        );

        $this->assertCount(3, $created);

        $this->assertSame(
            3,
            $created->pluck('admission_number')->unique()->count(),
            'Two admissions share a number, so the counter was read once instead of per row.',
        );

        $byCourse = $created->keyBy('course_id');

        $this->assertSame('30000.00', (string) $byCourse[$web->getKey()]->course_fee);
        $this->assertSame('18000.00', (string) $byCourse[$design->getKey()]->course_fee);
        $this->assertSame('9000.00', (string) $byCourse[$office->getKey()]->course_fee);

        $this->assertSame(
            3,
            StudentAdmission::query()->where('student_id', $student->getKey())->count(),
            'The basket wrote a different number of rows than it returned.',
        );
    }

    /**
     * The default the operator chose: a student registers once, however many courses they take.
     *
     * Getting this wrong is not a rounding error — three courses at 2,000 admission and 1,000
     * registration is 6,000 of invented charge on a quote nobody questioned, because every figure on
     * the screen came from the catalogue and looked right.
     */
    #[Test]
    public function the_admission_and_registration_fee_are_charged_once_for_the_basket(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $first = $this->publishedCourse(null, ['course_fee' => '30000.00', 'admission_fee' => '2000.00', 'registration_fee' => '1000.00'], $actor);
        $second = $this->publishedCourse(null, ['course_fee' => '18000.00', 'admission_fee' => '2000.00', 'registration_fee' => '1000.00'], $actor);

        $created = $this->admissionService()->createMany(
            $student,
            [['course_id' => $first->getKey()], ['course_id' => $second->getKey()]],
            [],
            $actor,
        );

        $byCourse = $created->keyBy('course_id');

        $this->assertSame('2000.00', (string) $byCourse[$first->getKey()]->admission_fee);
        $this->assertSame('1000.00', (string) $byCourse[$first->getKey()]->registration_fee);

        $this->assertSame('0.00', (string) $byCourse[$second->getKey()]->admission_fee, 'The second course charged the admission fee again.');
        $this->assertSame('0.00', (string) $byCourse[$second->getKey()]->registration_fee, 'The second course charged the registration fee again.');

        $this->assertSame(
            '51000.00',
            Money::sum($created->pluck('total_amount')->map(static fn ($v): string => (string) $v)->all()),
            'The basket total is not course fees plus one set of one-off fees.',
        );
    }

    /** `fees_once => false` is the other policy, and it has to actually charge per course. */
    #[Test]
    public function fees_once_can_be_switched_off_to_charge_per_course(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $first = $this->publishedCourse(null, ['course_fee' => '10000.00', 'admission_fee' => '2000.00', 'registration_fee' => '1000.00'], $actor);
        $second = $this->publishedCourse(null, ['course_fee' => '10000.00', 'admission_fee' => '2000.00', 'registration_fee' => '1000.00'], $actor);

        $created = $this->admissionService()->createMany(
            $student,
            [['course_id' => $first->getKey()], ['course_id' => $second->getKey()]],
            ['fees_once' => false],
            $actor,
        );

        foreach ($created as $admission) {
            $this->assertSame('2000.00', (string) $admission->admission_fee);
            $this->assertSame('1000.00', (string) $admission->registration_fee);
        }

        $this->assertSame(
            '26000.00',
            Money::sum($created->pluck('total_amount')->map(static fn ($v): string => (string) $v)->all()),
        );
    }

    /**
     * The split has to be exact, and the figures here make sure it is not exact by luck.
     *
     * Three equal lines and a 1,000 discount is 333.333… each. Truncated that is 999.99 and one paisa
     * has evaporated; rounded up it is 1,000.01 and one has been invented. `Money::allocate()` places
     * the remainder on the largest-remainder line, so the shares add to exactly what was typed — and
     * the sum of the three `net_payable` columns equals the figure quoted to the student.
     */
    #[Test]
    public function the_basket_discount_is_split_pro_rata_and_the_shares_add_back_exactly(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $courses = [
            $this->publishedCourse(null, ['course_fee' => '10000.00', 'admission_fee' => '0.00', 'registration_fee' => '0.00'], $actor),
            $this->publishedCourse(null, ['course_fee' => '10000.00', 'admission_fee' => '0.00', 'registration_fee' => '0.00'], $actor),
            $this->publishedCourse(null, ['course_fee' => '10000.00', 'admission_fee' => '0.00', 'registration_fee' => '0.00'], $actor),
        ];

        $created = $this->admissionService()->createMany(
            $student,
            array_map(static fn ($course): array => ['course_id' => $course->getKey()], $courses),
            ['discount_amount' => '1000.00', 'discount_reason' => 'Three-course package'],
            $actor,
        );

        $shares = $created->pluck('discount_amount')->map(static fn ($v): string => (string) $v)->all();

        $this->assertSame(
            '1000.00',
            Money::sum($shares),
            'The pro-rata shares do not add back to the discount that was agreed: '.implode(' + ', $shares),
        );

        $this->assertSame(
            '29000.00',
            Money::sum($created->pluck('net_payable')->map(static fn ($v): string => (string) $v)->all()),
            'The sum of what each admission owes is not the figure quoted for the basket.',
        );

        // Every share is within its own line, which is what keeps `chk_sadm_discount_ceiling` satisfied
        // without the service having to check each row separately.
        foreach ($created as $admission) {
            $this->assertTrue(
                Money::compare(
                    Money::add((string) $admission->discount_amount, (string) $admission->scholarship_amount),
                    (string) $admission->total_amount,
                ) <= 0,
                'A line was discounted below free.',
            );
        }
    }

    /** A discount and a scholarship together, split independently, still summing exactly. */
    #[Test]
    public function a_scholarship_is_split_alongside_the_discount(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $courses = [
            $this->publishedCourse(null, ['course_fee' => '7000.00', 'admission_fee' => '0.00', 'registration_fee' => '0.00'], $actor),
            $this->publishedCourse(null, ['course_fee' => '3000.00', 'admission_fee' => '0.00', 'registration_fee' => '0.00'], $actor),
            $this->publishedCourse(null, ['course_fee' => '1000.00', 'admission_fee' => '0.00', 'registration_fee' => '0.00'], $actor),
        ];

        $created = $this->admissionService()->createMany(
            $student,
            array_map(static fn ($course): array => ['course_id' => $course->getKey()], $courses),
            ['discount_amount' => '700.00', 'scholarship_amount' => '333.00', 'discount_reason' => 'Package plus merit'],
            $actor,
        );

        $this->assertSame('700.00', Money::sum($created->pluck('discount_amount')->map(static fn ($v): string => (string) $v)->all()));
        $this->assertSame('333.00', Money::sum($created->pluck('scholarship_amount')->map(static fn ($v): string => (string) $v)->all()));
        $this->assertSame('9967.00', Money::sum($created->pluck('net_payable')->map(static fn ($v): string => (string) $v)->all()));
    }

    /**
     * **The reason this file exists.** All of the basket, or none of it.
     */
    #[Test]
    public function nothing_is_created_when_one_course_in_the_basket_is_already_live(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $alreadyLive = $this->publishedCourse(null, ['course_fee' => '10000.00'], $actor);
        $fresh = $this->publishedCourse(null, ['course_fee' => '10000.00'], $actor);
        $alsoFresh = $this->publishedCourse(null, ['course_fee' => '10000.00'], $actor);

        $this->admissionService()->create($student, $alreadyLive, [], null, $actor);

        $before = StudentAdmission::query()->where('student_id', $student->getKey())->count();
        $this->assertSame(1, $before);

        try {
            $this->admissionService()->createMany(
                $student,
                [
                    ['course_id' => $fresh->getKey()],
                    ['course_id' => $alsoFresh->getKey()],
                    ['course_id' => $alreadyLive->getKey()],
                ],
                [],
                $actor,
            );

            $this->fail('The basket was accepted although one of its courses was already live.');
        } catch (CourseRuleException) {
            // The refusal is the point; what it must not have done is write anything.
        }

        $this->assertSame(
            $before,
            StudentAdmission::query()->where('student_id', $student->getKey())->count(),
            'The refused basket left admissions behind. That is the partial-submit bug this method exists '
            .'to prevent: the operator sees a validation error and believes nothing was saved.',
        );
    }

    /** Every clash named at once, so the operator does not fix one and meet the next. */
    #[Test]
    public function the_refusal_names_every_clashing_course_not_just_the_first(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $first = $this->publishedCourse(null, ['name' => 'Alpha course'], $actor);
        $second = $this->publishedCourse(null, ['name' => 'Beta course'], $actor);

        $this->admissionService()->create($student, $first, [], null, $actor);
        $this->admissionService()->create($student, $second, [], null, $actor);

        try {
            $this->admissionService()->createMany(
                $student,
                [['course_id' => $first->getKey()], ['course_id' => $second->getKey()]],
                [],
                $actor,
            );

            $this->fail('Two live courses were accepted.');
        } catch (CourseRuleException $exception) {
            $message = implode(' ', $exception->validator->errors()->all());

            $this->assertStringContainsString('Alpha course', $message);
            $this->assertStringContainsString(
                'Beta course',
                $message,
                'Only the first clash was reported, so the operator would resubmit and meet the second.',
            );
        }
    }

    /** One course twice would pass the pre-check and then collide mid-transaction. */
    #[Test]
    public function the_same_course_twice_in_one_basket_is_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);
        $course = $this->publishedCourse(null, [], $actor);

        $this->expectException(CourseRuleException::class);

        $this->admissionService()->createMany(
            $student,
            [['course_id' => $course->getKey()], ['course_id' => $course->getKey()]],
            [],
            $actor,
        );
    }

    /** A basket discounted past what it charges is refused with the basket's own figures. */
    #[Test]
    public function a_discount_larger_than_the_basket_is_refused_before_anything_is_written(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $courses = [
            $this->publishedCourse(null, ['course_fee' => '1000.00', 'admission_fee' => '0.00', 'registration_fee' => '0.00'], $actor),
            $this->publishedCourse(null, ['course_fee' => '1000.00', 'admission_fee' => '0.00', 'registration_fee' => '0.00'], $actor),
        ];

        try {
            $this->admissionService()->createMany(
                $student,
                array_map(static fn ($course): array => ['course_id' => $course->getKey()], $courses),
                ['discount_amount' => '5000.00'],
                $actor,
            );

            $this->fail('A discount larger than the basket was accepted.');
        } catch (CourseRuleException $exception) {
            $this->assertStringContainsString('below free', implode(' ', $exception->validator->errors()->all()));
        }

        $this->assertSame(0, StudentAdmission::query()->where('student_id', $student->getKey())->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Over HTTP — the screen the operator actually uses
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_store_endpoint_creates_one_admission_per_ticked_course(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $first = $this->publishedCourse(null, ['course_fee' => '30000.00'], $actor);
        $second = $this->publishedCourse(null, ['course_fee' => '18000.00'], $actor);

        $response = $this->actingAs($actor)->post(route('admin.admissions.store'), [
            'student_id' => $student->getKey(),
            'course_ids' => [$first->getKey(), $second->getKey()],
            'discount_amount' => '1000.00',
            'discount_reason' => 'Two-course package',
        ]);

        $response->assertRedirect(route('admin.admissions.index', ['q' => $student->student_code]));

        $admissions = StudentAdmission::query()->where('student_id', $student->getKey())->get();

        $this->assertCount(2, $admissions);
        $this->assertSame('1000.00', Money::sum($admissions->pluck('discount_amount')->map(static fn ($v): string => (string) $v)->all()));
    }

    /**
     * One course still behaves exactly as it did before this change.
     *
     * Not a formality: `admin.admissions.show` is where the stepper lives, and the operator who admits
     * one student to one course should not be made to find their way back to it through a list.
     */
    #[Test]
    public function one_ticked_course_still_lands_on_that_admissions_own_page(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);
        $course = $this->publishedCourse(null, [], $actor);

        $response = $this->actingAs($actor)->post(route('admin.admissions.store'), [
            'student_id' => $student->getKey(),
            'course_ids' => [$course->getKey()],
        ]);

        $admission = StudentAdmission::query()->where('student_id', $student->getKey())->sole();

        $response->assertRedirect(route('admin.admissions.show', $admission));
    }

    /** The singular spelling every existing caller posts has to keep working. */
    #[Test]
    public function the_legacy_single_course_id_payload_is_still_accepted(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);
        $course = $this->publishedCourse(null, ['course_fee' => '12345.00'], $actor);

        $this->actingAs($actor)->post(route('admin.admissions.store'), [
            'student_id' => $student->getKey(),
            'course_id' => $course->getKey(),
        ])->assertRedirect();

        $admission = StudentAdmission::query()->where('student_id', $student->getKey())->sole();

        $this->assertSame((int) $course->getKey(), (int) $admission->course_id);
        $this->assertSame('12345.00', (string) $admission->course_fee);
    }

    /** Per-course overrides arrive keyed by course id and must land on the right course. */
    #[Test]
    public function a_negotiated_fee_lands_on_the_course_it_was_typed_against(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $first = $this->publishedCourse(null, ['course_fee' => '30000.00'], $actor);
        $second = $this->publishedCourse(null, ['course_fee' => '18000.00'], $actor);

        $this->actingAs($actor)->post(route('admin.admissions.store'), [
            'student_id' => $student->getKey(),
            'course_ids' => [$first->getKey(), $second->getKey()],
            'lines' => [
                (string) $second->getKey() => ['course_fee' => '15000.00'],
            ],
        ])->assertRedirect();

        $byCourse = StudentAdmission::query()->where('student_id', $student->getKey())->get()->keyBy('course_id');

        $this->assertSame('30000.00', (string) $byCourse[$first->getKey()]->course_fee, 'The untouched course lost its catalogue price.');
        $this->assertSame('15000.00', (string) $byCourse[$second->getKey()]->course_fee, 'The negotiated price landed on the wrong course.');
    }

    /**
     * A stale form: the operator unticks a course and the browser keeps its fee inputs.
     *
     * Ignoring the orphan would be the wrong kind of forgiving — the figure on their screen and the
     * figure stored would differ, and nothing would say so.
     */
    #[Test]
    public function a_fee_for_an_unticked_course_is_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $ticked = $this->publishedCourse(null, [], $actor);
        $unticked = $this->publishedCourse(null, [], $actor);

        $this->actingAs($actor)->post(route('admin.admissions.store'), [
            'student_id' => $student->getKey(),
            'course_ids' => [$ticked->getKey()],
            'lines' => [
                (string) $unticked->getKey() => ['course_fee' => '1.00'],
            ],
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, StudentAdmission::query()->where('student_id', $student->getKey())->count());
    }

    #[Test]
    public function an_empty_basket_is_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $this->actingAs($actor)->post(route('admin.admissions.store'), [
            'student_id' => $student->getKey(),
        ])->assertSessionHasErrors('course_ids');
    }

    #[Test]
    public function a_user_without_the_create_permission_cannot_post_a_basket(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);
        $course = $this->publishedCourse(null, [], $actor);

        $outsider = $this->createUserWithPermissions(['admissions.view_any']);

        $this->actingAs($outsider)->post(route('admin.admissions.store'), [
            'student_id' => $student->getKey(),
            'course_ids' => [$course->getKey()],
        ])->assertForbidden();

        $this->assertSame(0, StudentAdmission::query()->where('student_id', $student->getKey())->count());
    }
}
