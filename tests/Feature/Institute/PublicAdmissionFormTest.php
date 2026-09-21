<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Enums\StudentApplicationStatus;
use App\Models\Institute\StudentApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\TestCase;

/**
 * §67's public admission form (phase-14-17 §6.4, §7.10).
 *
 * **[D-IN-7] One row, and nothing else.** No student, no `users` row, no fee. The distance between
 * "somebody asked" and "somebody is a student" is the whole reason this table exists, and a test that
 * did not check it would let that distance close by accident.
 *
 * **The key blocks and the fingerprint flags.** A replayed POST returns the application the first one
 * wrote; two genuine applications from the same person for the same course both land, because the
 * same person really does re-apply.
 */
final class PublicAdmissionFormTest extends TestCase
{
    use BuildsAdmissions;
    use InteractsWithRbac;
    use RefreshDatabase;

    /**
     * Nobody is signed in: every one of these is a stranger on the internet, and the point of most of
     * them is what a stranger can and cannot make the system do.
     */
    private function becomeGuest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(int $courseId, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sana Iqbal',
            'phone' => '0300-1234567',
            'email' => 'sana@example.test',
            'city' => 'Lahore',
            'course_id' => $courseId,
            'idempotency_key' => (string) Str::ulid(),
            'form_rendered_at' => time() - 30,
        ], $overrides);
    }

    /*
    |--------------------------------------------------------------------------
    | What it creates, and what it deliberately does not
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_form_creates_one_application_and_no_student_user_or_fee(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        $usersBefore = \App\Models\User::query()->count();

        $this->becomeGuest();

        $response = $this->post(route('site.admission.store'), $this->payload((int) $course->getKey()));

        $response->assertRedirect();

        $this->assertDatabaseCount('student_applications', 1);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('student_admissions', 0);
        $this->assertSame($usersBefore, \App\Models\User::query()->count());

        $application = StudentApplication::query()->firstOrFail();

        $this->assertSame(StudentApplicationStatus::Submitted, $application->status);
        $this->assertNotEmpty($application->application_number);
        $this->assertSame('03001234567', $application->phone, 'the phone is normalised to digits');
    }

    #[Test]
    public function the_thank_you_page_needs_a_signed_url(): void
    {
        $actor = $this->createSuperAdmin();
        $application = $this->publicApplication(actor: $actor);

        $this->becomeGuest();

        // Unsigned: the number is sequential by design, so walking it must not work.
        $this->get('/admission/submitted/'.$application->application_number)->assertForbidden();

        $this->get(URL::signedRoute('site.admission.submitted', ['application' => $application->application_number]))
            ->assertOk()
            ->assertSee($application->application_number);
    }

    /*
    |--------------------------------------------------------------------------
    | The guards
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_replayed_submit_is_the_same_application_not_an_error(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);
        $payload = $this->payload((int) $course->getKey());

        $this->becomeGuest();

        $this->post(route('site.admission.store'), $payload)->assertRedirect();
        $this->post(route('site.admission.store'), $payload)->assertRedirect();

        $this->assertDatabaseCount('student_applications', 1);
    }

    #[Test]
    public function the_same_person_applying_twice_is_flagged_and_never_blocked(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        $this->becomeGuest();

        // Two genuine submissions: different form renders, same person, same course.
        $this->post(route('site.admission.store'), $this->payload((int) $course->getKey()))->assertRedirect();
        $this->post(route('site.admission.store'), $this->payload((int) $course->getKey()))->assertRedirect();

        $this->assertDatabaseCount('student_applications', 2);

        $applications = StudentApplication::query()->orderBy('id')->get();

        $this->assertSame(
            $applications[0]->duplicate_fingerprint,
            $applications[1]->duplicate_fingerprint,
            'the fingerprint is what turns the row amber for a human',
        );
        $this->assertSame(1, $applications[1]->possibleDuplicates()->count());
    }

    #[Test]
    public function the_honeypot_and_the_timing_check_refuse_without_saying_which_caught_them(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        $this->becomeGuest();

        $this->post(route('site.admission.store'), $this->payload((int) $course->getKey(), [
            'website' => 'http://spam.example',
        ]))->assertSessionHasErrors('website');

        $this->post(route('site.admission.store'), $this->payload((int) $course->getKey(), [
            'form_rendered_at' => time(),
        ]))->assertSessionHasErrors('name');

        $this->assertDatabaseCount('student_applications', 0);
    }

    #[Test]
    public function a_draft_course_cannot_be_applied_to(): void
    {
        $actor = $this->createSuperAdmin();
        $draft = $this->draftCourse(actor: $actor);

        $this->becomeGuest();

        $this->post(route('site.admission.store'), $this->payload((int) $draft->getKey()))
            ->assertSessionHasErrors('course_id');

        $this->assertDatabaseCount('student_applications', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | The referral (INV-I4)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_collaborator_id_posted_by_the_browser_is_discarded(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        $this->becomeGuest();

        $this->post(route('site.admission.store'), $this->payload((int) $course->getKey(), [
            // A visitor naming a partner would be a visitor assigning a commission.
            'collaborator_id' => 1,
        ]))->assertRedirect();

        $application = StudentApplication::query()->firstOrFail();

        $this->assertNull($application->collaborator_id);
        $this->assertFalse((bool) $application->referral_code_valid);
    }

    #[Test]
    public function an_unrecognised_code_is_stored_verbatim_and_attaches_nobody(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        $this->becomeGuest();

        $this->post(route('site.admission.store'), $this->payload((int) $course->getKey(), [
            'referral_code' => 'NOT-A-REAL-CODE',
        ]))->assertRedirect();

        $application = StudentApplication::query()->firstOrFail();

        // Kept as submitted: a partner's mistyped code is worth seeing, and a blank field would hide
        // that anybody quoted one at all.
        $this->assertSame('NOT-A-REAL-CODE', $application->referral_code);
        $this->assertFalse((bool) $application->referral_code_valid);
        $this->assertNull($application->collaborator_id);

        // And converting it attaches nothing.
        $admission = $this->applicationService()->convert($application, [], $actor);

        $this->assertNull($admission->student->collaborator_id);
        $this->assertDatabaseCount('collaborator_referrals', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | The gate
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function closing_admissions_shows_a_page_rather_than_a_404_and_refuses_the_post(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);

        $this->setting('institute.admission_open', false);

        $this->becomeGuest();

        // 200, not 404: the page exists, the institute is simply not taking admissions today.
        $this->get(route('site.admission.create'))
            ->assertOk()
            ->assertSee('Admissions are closed');

        $this->post(route('site.admission.store'), $this->payload((int) $course->getKey()))
            ->assertStatus(422);

        $this->assertDatabaseCount('student_applications', 0);
    }

    #[Test]
    public function a_staff_user_who_can_open_admissions_sees_the_form_with_a_ribbon(): void
    {
        $actor = $this->createSuperAdmin();
        $this->publishedCourse(actor: $actor);

        $this->setting('institute.admission_open', false);

        $this->actingAs($actor)
            ->get(route('site.admission.create'))
            ->assertOk()
            ->assertSee('Apply for admission')
            ->assertSee('closed');
    }
}
