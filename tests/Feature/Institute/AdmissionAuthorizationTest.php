<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Models\Branch;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAdmission;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\TestCase;

/**
 * Who may do what, and what each role can actually reach (phase-14-17 §4, §9).
 *
 * **The application inbox is its own module for one reason**, and it is tested here: a receptionist
 * triages the public form — claims it, rejects it, marks it a duplicate — without holding
 * `students.create`. The moment they convert one they are creating a student, so the policy asks for
 * that permission and the other module's too. A boundary nobody tests is a boundary that quietly
 * stops existing.
 *
 * **A list is scoped in the query, not by the policy.** A policy answers about one row; a branch
 * user's page count would otherwise tell them how many records exist elsewhere.
 */
final class AdmissionAuthorizationTest extends TestCase
{
    use BuildsAdmissions;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | The module boundary the inbox exists for
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_reviewer_can_triage_the_inbox_without_being_able_to_create_a_student(): void
    {
        $actor = $this->createSuperAdmin();
        $application = $this->publicApplication(actor: $actor);

        $reviewer = $this->createUserWithPermissions([
            'student_applications.view_any', 'student_applications.view',
            'student_applications.edit', 'student_applications.reject',
            'student_applications.change_status',
        ]);

        $this->assertFalse($reviewer->can('students.create'));

        $this->actingAs($reviewer)->get(route('admin.student-applications.index'))->assertOk();
        $this->actingAs($reviewer)->get(route('admin.student-applications.show', $application))->assertOk();

        $this->actingAs($reviewer)
            ->post(route('admin.student-applications.claim', $application))
            ->assertRedirect();

        // But converting crosses into the student directory, and that is a different right.
        $this->actingAs($reviewer)
            ->post(route('admin.student-applications.convert', $application), [])
            ->assertForbidden();

        $this->assertDatabaseCount('students', 0);
    }

    #[Test]
    public function converting_needs_both_students_create_and_admissions_create(): void
    {
        $actor = $this->createSuperAdmin();
        $application = $this->publicApplication(actor: $actor);

        // Everything except `admissions.create` — an admission is where the money goes, so it is asked
        // for separately from the student record.
        $halfWay = $this->createUserWithPermissions([
            'student_applications.view_any', 'student_applications.view', 'student_applications.edit',
            'students.create', 'students.view_any', 'students.view',
        ]);

        $this->actingAs($halfWay)
            ->post(route('admin.student-applications.convert', $application), [])
            ->assertForbidden();

        $full = $this->createUserWithPermissions([
            'student_applications.view_any', 'student_applications.view', 'student_applications.edit',
            'students.create', 'students.view_any', 'students.view',
            'admissions.create', 'admissions.view_any', 'admissions.view',
        ]);

        $this->actingAs($full)
            ->post(route('admin.student-applications.convert', $application), [])
            ->assertRedirect();

        $this->assertDatabaseCount('students', 1);
    }

    #[Test]
    public function an_application_is_never_deletable_by_anybody(): void
    {
        $actor = $this->createSuperAdmin();
        $application = $this->publicApplication(actor: $actor);

        // The module declares no `delete` ability at all (§4.1), so there is no permission to hold...
        $this->assertNotContains(
            'student_applications.delete',
            PermissionRegistry::permissionNames(),
        );

        // ...and no route to call it with.
        $this->assertFalse(
            Route::has('admin.student-applications.destroy'),
        );

        // The policy refuses everybody who is subject to it. A Super Admin passes `Gate::before`
        // ahead of any policy (CLAUDE.md §4), so it is asserted against somebody who is not one —
        // and for them the absence of a route is what makes it moot anyway.
        $anybody = $this->createUserWithPermissions([
            'student_applications.view_any', 'student_applications.view',
            'student_applications.edit', 'student_applications.change_status',
        ]);

        $this->assertFalse($anybody->can('delete', $application));
    }

    /*
    |--------------------------------------------------------------------------
    | What a permission does not buy
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function reading_students_does_not_let_you_change_one(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $reader = $this->createUserWithPermissions(['students.view_any', 'students.view']);

        $this->actingAs($reader)->get(route('admin.students.index'))->assertOk();
        $this->actingAs($reader)->get(route('admin.students.show', $student))->assertOk();

        $this->actingAs($reader)->get(route('admin.students.edit', $student))->assertForbidden();
        $this->actingAs($reader)
            ->post(route('admin.students.status', $student), ['status' => 'dropped', 'reason' => 'Trying it on'])
            ->assertForbidden();

        $this->assertSame('applied', $student->refresh()->status->value);
    }

    #[Test]
    public function the_admission_money_columns_are_absent_without_view_financial(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->admission(null, ['course_fee' => '44444.00'], $actor);

        $plain = $this->createUserWithPermissions(['admissions.view_any', 'admissions.view']);

        // Absent, not blanked: a column of dashes tells a reader there is money they may not see,
        // which is itself information (the Phase 13 rule).
        $this->actingAs($plain)
            ->get(route('admin.admissions.index'))
            ->assertOk()
            ->assertDontSee('Net payable')
            ->assertDontSee('44,444');

        $withMoney = $this->createUserWithPermissions([
            'admissions.view_any', 'admissions.view', 'admissions.view_financial',
        ]);

        $this->actingAs($withMoney)
            ->get(route('admin.admissions.index'))
            ->assertOk()
            ->assertSee('Net payable');
    }

    #[Test]
    public function the_admissions_export_is_refused_outright_without_the_money_permission(): void
    {
        $actor = $this->createSuperAdmin();
        $this->admission(actor: $actor);

        $plain = $this->createUserWithPermissions([
            'admissions.view_any', 'admissions.view', 'admissions.export',
        ]);

        // An admissions export without the figures is a list of reference numbers.
        $this->actingAs($plain)
            ->get(route('admin.admissions.export', ['format' => 'csv']))
            ->assertForbidden();
    }

    #[Test]
    public function a_locked_admission_refuses_the_figures_route_to_everybody(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->admission(actor: $actor);

        StudentAdmission::query()
            ->whereKey($admission->getKey())
            ->update(['figures_locked_at' => now()]);

        $payload = [
            'course_fee' => '1.00',
            'admission_fee' => '0.00',
            'registration_fee' => '0.00',
            'reason' => 'Trying it on after the lock',
        ];

        // For anybody the policy governs, the route is simply shut.
        $editor = $this->createUserWithPermissions([
            'admissions.view_any', 'admissions.view', 'admissions.edit', 'admissions.view_financial',
        ]);

        $this->actingAs($editor)
            ->put(route('admin.admissions.figures', $admission), $payload)
            ->assertForbidden();

        // A Super Admin passes `Gate::before` and reaches the service — which refuses, and says why.
        // That second guard is the one that matters: the policy hides a button, the service is what
        // makes the rule true (INV-I2).
        $this->actingAs($actor)
            ->put(route('admin.admissions.figures', $admission), $payload)
            ->assertSessionHasErrors('figures');

        $this->assertSame('30000.00', $admission->refresh()->course_fee);
    }

    /*
    |--------------------------------------------------------------------------
    | Branch isolation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_branch_user_sees_their_own_branch_and_the_records_that_belong_to_none(): void
    {
        $actor = $this->createSuperAdmin();

        $lahore = Branch::query()->create(['name' => 'Lahore', 'code' => 'LHR', 'is_active' => true]);
        $karachi = Branch::query()->create(['name' => 'Karachi', 'code' => 'KHI', 'is_active' => true]);

        $mine = $this->student(['name' => 'Lahore Student', 'branch_id' => $lahore->getKey()], $actor);
        $theirs = $this->student(['name' => 'Karachi Student', 'branch_id' => $karachi->getKey()], $actor);
        $everywhere = $this->student(['name' => 'Everywhere Student', 'branch_id' => null], $actor);

        $reader = $this->createUserWithPermissions(['students.view_any', 'students.view']);
        $reader->forceFill(['branch_id' => $lahore->getKey()])->save();

        $response = $this->actingAs($reader)->get(route('admin.students.index'));

        $response->assertOk();
        $response->assertSee('Lahore Student');
        // Null means "everywhere", not "nobody" ([D-IN-5]).
        $response->assertSee('Everywhere Student');
        $response->assertDontSee('Karachi Student');

        // And the row itself is out of reach, not merely off the list.
        $this->actingAs($reader)->get(route('admin.students.show', $theirs))->assertForbidden();
        $this->actingAs($reader)->get(route('admin.students.show', $mine))->assertOk();
        $this->actingAs($reader)->get(route('admin.students.show', $everywhere))->assertOk();
    }

    #[Test]
    public function a_student_with_history_is_not_deletable_even_with_the_permission(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->admission(actor: $actor);
        $student = $admission->student;

        $deleter = $this->createUserWithPermissions([
            'students.view_any', 'students.view', 'students.delete',
        ]);

        // No fee rows yet, so the policy allows it — the restriction is about history, not status.
        $this->assertTrue($deleter->can('delete', $student));

        // Phase 18 writes this; the test does what that phase will.
        DB::table('student_fees')->insert([
            'student_id' => $student->getKey(),
            'student_admission_id' => $admission->getKey(),
            'fee_number' => 'FEE-TEST-1',
            'fee_type' => 'course_fee',
            'gross_amount' => '1000.00',
            'net_amount' => '1000.00',
            'due_date' => now()->toDateString(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($student->fresh()->hasHistory());
        $this->assertFalse($deleter->can('delete', $student->fresh()));
    }
}
