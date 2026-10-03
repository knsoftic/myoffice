<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Models\Institute\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\TestCase;

/**
 * The front desk owns the student record: it may edit one, and delete one entered by mistake.
 *
 * Deleting stays limited by `StudentPolicy::delete()` — a student with any fee, enrolment or attendance
 * history is never removable, for the Receptionist or anybody else. The student page also had no delete
 * control at all, so the route was unreachable from the screen whatever the role held.
 */
final class ReceptionistStudentRecordTest extends TestCase
{
    use BuildsFinancialFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function the_receptionist_can_edit_a_student(): void
    {
        $receptionist = $this->createUserWithRole('Receptionist');
        $student = $this->fixtureStudent();

        $this->actingAs($receptionist)
            ->put(route('admin.students.update', $student), [
                'name' => 'Edited At The Desk',
                'phone' => '03001112244',
            ])
            ->assertRedirect(route('admin.students.show', $student))
            ->assertSessionHasNoErrors();

        $this->assertSame('Edited At The Desk', $student->refresh()->name);
    }

    #[Test]
    public function the_receptionist_can_delete_a_student_with_no_history_from_the_page(): void
    {
        $receptionist = $this->createUserWithRole('Receptionist');
        $student = $this->fixtureStudent();

        $this->actingAs($receptionist)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee('Delete student');

        $this->actingAs($receptionist)
            ->delete(route('admin.students.destroy', $student))
            ->assertRedirect(route('admin.students.index'));

        $this->assertSoftDeleted('students', ['id' => $student->getKey()]);
    }

    #[Test]
    public function the_students_list_offers_edit_on_every_row_and_delete_only_without_history(): void
    {
        $receptionist = $this->createUserWithRole('Receptionist');
        // Named apart: the fixture names every student after the same counter.
        $fresh = tap($this->fixtureStudent(), fn (Student $s) => $s->forceFill(['name' => 'Walk In Mistake'])->save());
        $charged = tap($this->fixtureStudent(), fn (Student $s) => $s->forceFill(['name' => 'Paying Learner'])->save());
        $this->charge(null, '1000.00', ['student_id' => $charged->getKey()]);

        $this->actingAs($receptionist)
            ->get(route('admin.students.index'))
            ->assertOk()
            ->assertSee(route('admin.students.edit', $fresh), false)
            ->assertSee(route('admin.students.edit', $charged), false)
            ->assertSee('Delete '.$fresh->name)
            ->assertDontSee('Delete '.$charged->name);
    }

    #[Test]
    public function a_student_with_history_cannot_be_deleted_even_by_the_receptionist(): void
    {
        $receptionist = $this->createUserWithRole('Receptionist');
        $student = $this->fixtureStudent();
        $this->charge(null, '1000.00', ['student_id' => $student->getKey()]);

        $this->assertTrue($student->hasHistory());

        $this->actingAs($receptionist)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertDontSee('Delete student');

        $this->actingAs($receptionist)
            ->delete(route('admin.students.destroy', $student))
            ->assertForbidden();

        $this->assertNotSoftDeleted('students', ['id' => $student->getKey()]);
        $this->assertInstanceOf(Student::class, Student::query()->find($student->getKey()));
    }
}
