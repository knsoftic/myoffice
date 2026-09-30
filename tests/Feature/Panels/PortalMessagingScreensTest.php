<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Models\Institute\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\TestCase;

/**
 * Portal screens that were a 500 — the first two for every portal user, the fee slip for every student.
 *
 * - The message recipient picker selected only `id` and `name`, then asked the messaging matrix, which
 *   reads each candidate's `status` and roles. Strict models refused both, so the picker was a 500.
 * - The notification preferences screen called `label()` on `NotificationEvent`, which has a `title`
 *   and no `label()`, so the screen was a 500 on every panel.
 */
final class PortalMessagingScreensTest extends TestCase
{
    use BuildsFinancialFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function a_student_can_open_the_recipient_picker_and_is_offered_institute_staff(): void
    {
        $user = $this->studentLogin();
        $staff = $this->createUserWithRole('Admin', ['name' => 'Office Admin']);

        $response = $this->actingAs($user)
            ->getJson(route('student.messages.recipients', ['q' => 'Office']))
            ->assertOk();

        $this->assertContains($staff->getKey(), array_column($response->json('recipients'), 'id'));
    }

    #[Test]
    public function a_student_can_open_their_notification_preferences(): void
    {
        $this->actingAs($this->studentLogin())
            ->get(route('student.notifications.preferences'))
            ->assertOk();
    }

    /**
     * T64: the student's fee slip was a 500 for everybody, because the slip builder loads the charge's
     * collaborator and branch while the student panel selects their keys away. The student's copy names
     * the referrer (§41, Q9) — the name, not the money — and still ships no `collaborator_id`.
     */
    #[Test]
    public function a_referred_student_can_print_their_slip_and_it_names_the_referrer_only(): void
    {
        $partner = $this->partner();
        $student = $this->fixtureStudent();
        $user = $this->studentLogin($student);
        $charge = $this->charge($partner, '20000.00', [
            'student_id' => $student->getKey(),
            'collaborator_id' => $partner->getKey(),
        ]);

        $this->actingAs($user)
            ->get(route('student.fees.slip', $charge))
            ->assertOk()
            ->assertSee('Referred by '.$partner->name)
            ->assertDontSee('collaborator_id')
            ->assertDontSee('Commissionable amount');
    }

    private function studentLogin(?Student $student = null): User
    {
        $student ??= $this->fixtureStudent();
        $user = $this->createUserWithRole('Student');

        // `students.user_id` is not mass assignable (D2), so the binding is written directly, as the
        // isolation suites do.
        DB::table('students')->where('id', $student->getKey())->update(['user_id' => $user->getKey()]);

        return $user;
    }
}
