<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Enums\AdmissionStage;
use App\Models\Institute\Course;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAdmission;
use App\Models\User;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\TestCase;

/**
 * Edit (the non-financial details) and delete (an admission entered by mistake) on
 * `admin.admissions.*`.
 *
 * The edit never reaches the money, the stage or the number; the delete is refused the moment a
 * charge, a seat or a commission entitlement points at the row.
 */
final class AdmissionEditDeleteTest extends TestCase
{
    use BuildsAdmissions;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Edit
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_details_are_edited_and_the_money_and_stage_are_left_alone(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->freshAdmission($actor);
        $before = $admission->only(['course_fee', 'net_payable', 'stage', 'admission_number', 'student_id', 'course_id']);

        $this->actingAs($actor)->get(route('admin.admissions.edit', $admission))->assertOk()
            ->assertSee('Admission details');

        $this->actingAs($actor)
            ->put(route('admin.admissions.update', $admission), [
                'admission_date' => '2026-09-01',
                'counselor_id' => $actor->getKey(),
                'delivery_mode' => 'online',
                'preferred_timing' => 'evening',
                'notes' => 'Prefers weekend make-up classes.',
                // Smuggled: none of these may move through this route.
                'course_fee' => '1.00',
                'stage' => 'active',
                'admission_number' => 'HACKED',
            ])
            ->assertRedirect(route('admin.admissions.show', $admission))
            ->assertSessionHas('toast');

        $admission->refresh();

        $this->assertSame('2026-09-01', $admission->admission_date->toDateString());
        $this->assertSame('online', $admission->delivery_mode->value);
        $this->assertSame('evening', $admission->preferred_timing->value);
        $this->assertSame('Prefers weekend make-up classes.', $admission->notes);
        $this->assertSame($before, $admission->only(array_keys($before)));
    }

    #[Test]
    public function editing_without_admissions_edit_is_forbidden(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->freshAdmission($actor);

        $viewer = $this->createUserWithPermissions(['admissions.view_any', 'admissions.view']);

        $this->actingAs($viewer)->get(route('admin.admissions.edit', $admission))->assertForbidden();
        $this->actingAs($viewer)
            ->put(route('admin.admissions.update', $admission), ['admission_date' => '2026-09-01'])
            ->assertForbidden();
    }

    #[Test]
    public function a_closed_admission_cannot_be_edited(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->freshAdmission($actor);
        $this->admissionService()->withdraw($admission, 'Moved city', $actor);

        $this->actingAs($this->staff())->get(route('admin.admissions.edit', $admission))->assertForbidden();

        // Super Admin passes every gate (Gate::before), so the service is what refuses them.
        $this->actingAs($actor)
            ->put(route('admin.admissions.update', $admission), ['admission_date' => '2026-09-01', 'notes' => 'late'])
            ->assertSessionHasErrors('stage');

        $this->assertNotSame('late', $admission->refresh()->notes);
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_uncharged_admission_is_soft_deleted_and_frees_the_course_for_the_student(): void
    {
        $actor = $this->createSuperAdmin();
        [$student, $course] = [$this->student([], $actor), $this->publishedCourse(actor: $actor)];
        $admission = $this->admissionService()->create($student, $course, [], null, $actor);

        $this->actingAs($actor)
            ->delete(route('admin.admissions.destroy', $admission))
            ->assertRedirect(route('admin.admissions.index'))
            ->assertSessionHas('toast');

        $this->assertSoftDeleted('student_admissions', ['id' => $admission->getKey()]);

        $trashed = StudentAdmission::withTrashed()->findOrFail($admission->getKey());
        $this->assertSame(AdmissionStage::Cancelled, $trashed->stage);
        $this->assertNotEmpty($trashed->cancellation_reason);

        // uq_sadm_live reads trashed rows too: the delete must not lock the student out of the course.
        $again = $this->admissionService()->create($student->refresh(), $course, [], null, $actor);
        $this->assertNotSame($admission->getKey(), $again->getKey());
    }

    #[Test]
    public function deleting_is_forbidden_once_anything_has_been_charged(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->freshAdmission($actor);

        // What Phase 18 writes with the first charge.
        StudentAdmission::query()->whereKey($admission->getKey())->update([
            'charged_amount' => '30000.00',
            'figures_locked_at' => now(),
        ]);

        $this->actingAs($this->staff())
            ->delete(route('admin.admissions.destroy', $admission))
            ->assertForbidden();

        // Super Admin is let through the gate; the service still refuses and names why.
        $this->actingAs($actor)
            ->delete(route('admin.admissions.destroy', $admission))
            ->assertSessionHasErrors('admission');

        $this->assertNotSoftDeleted('student_admissions', ['id' => $admission->getKey()]);
    }

    #[Test]
    public function deleting_without_admissions_delete_is_forbidden(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->freshAdmission($actor);

        $editor = $this->createUserWithPermissions(['admissions.view_any', 'admissions.view', 'admissions.edit']);

        $this->actingAs($editor)->delete(route('admin.admissions.destroy', $admission))->assertForbidden();
        $this->assertNotSoftDeleted('student_admissions', ['id' => $admission->getKey()]);
    }

    #[Test]
    public function the_service_names_the_fee_rows_that_would_be_orphaned(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->admissionService()->register($this->freshAdmission($actor), $actor);

        $this->actingAs($actor)->post(route('admin.admissions.fees', $admission), ['installments' => 0])->assertRedirect();

        try {
            $this->admissionService()->delete($admission->refresh(), $actor);
            $this->fail('An admission with fee rows was deleted.');
        } catch (CourseRuleException $e) {
            $this->assertStringContainsString('fee row', $e->getMessage());
        }

        $this->assertNotSoftDeleted('student_admissions', ['id' => $admission->getKey()]);
    }

    /*
    |--------------------------------------------------------------------------
    | Buttons
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_list_and_the_page_show_edit_and_delete_only_where_they_are_allowed(): void
    {
        $actor = $this->createSuperAdmin();
        $deletable = $this->freshAdmission($actor);
        $charged = $this->freshAdmission($actor);
        StudentAdmission::query()->whereKey($charged->getKey())->update([
            'charged_amount' => '30000.00',
            'figures_locked_at' => now(),
        ]);

        foreach ([$actor, $this->staff()] as $user) {
            $index = $this->actingAs($user)->get(route('admin.admissions.index'))->assertOk();
            $index->assertSee(route('admin.admissions.edit', $deletable), false);
            $index->assertSee('Delete '.$deletable->admission_number);
            $index->assertDontSee('Delete '.$charged->admission_number);

            $this->actingAs($user)->get(route('admin.admissions.show', $deletable))->assertOk()
                ->assertSee(route('admin.admissions.edit', $deletable), false)
                ->assertSee('Delete '.$deletable->admission_number);

            $this->actingAs($user)->get(route('admin.admissions.show', $charged))->assertOk()
                ->assertDontSee('Delete '.$charged->admission_number);
        }

        // A viewer sees neither.
        $viewer = $this->createUserWithPermissions(['admissions.view_any', 'admissions.view']);

        $this->actingAs($viewer)->get(route('admin.admissions.index'))->assertOk()
            ->assertDontSee(route('admin.admissions.edit', $deletable), false)
            ->assertDontSee('Delete '.$deletable->admission_number);
    }

    /** A front-desk account holding the admissions rights, without Super Admin's bypass. */
    private function staff(): User
    {
        return $this->createUserWithPermissions([
            'admissions.view_any', 'admissions.view', 'admissions.edit', 'admissions.delete',
        ]);
    }

    private function freshAdmission(User $actor): StudentAdmission
    {
        /** @var Student $student */
        $student = $this->student([], $actor);
        /** @var Course $course */
        $course = $this->publishedCourse(null, [
            'course_fee' => '30000.00',
            'admission_fee' => '2000.00',
            'registration_fee' => '1000.00',
        ], $actor);

        return $this->admissionService()->create($student, $course, [], null, $actor);
    }
}
