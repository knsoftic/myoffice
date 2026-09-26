<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Enums\StudentStatus;
use App\Models\Institute\Student;
use App\Models\User;
use App\Notifications\Institute\PortalLoginCreatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\TestCase;

/**
 * The credentials email that was never written.
 *
 * `StudentService::createLogin()` and `TeacherService::createLogin()` each generated a random
 * password, saved its hash, and carried the comment *"the notification is the only thing that sees
 * it"*. There was no notification anywhere in the application. The account was created, the admin
 * screen said the password had been sent, and the password ceased to exist at the end of the
 * method — so every login made this way was unusable by the person it was for.
 *
 * **Nothing in the suite could have caught it.** The service tests asserted a `users` row appeared
 * and that it was linked to the student, which it was. The page tests asserted a success toast,
 * which it showed. The bug lived entirely in what was *absent*, and an absent side effect is
 * invisible to any test that only inspects the rows that are present. That is the whole reason this
 * file exists: it asserts the send, not the row.
 *
 * The same shape as the blank dashboards and the stale page cache — a failure that renders a valid
 * page, logs nothing, and is only discovered by the one person who cannot report it.
 */
final class PortalCredentialsEmailTest extends TestCase
{
    use BuildsAdmissions;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function creating_a_student_login_emails_the_credentials_to_the_student(): void
    {
        Notification::fake();

        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $user = $this->studentService()->createLogin($student, $actor);

        $this->assertInstanceOf(User::class, $user);
        $this->assertFalse(
            $this->studentService()->credentialsMailFailed(),
            'The service reported a delivery failure on the happy path.',
        );

        Notification::assertSentTo(
            $user,
            PortalLoginCreatedNotification::class,
            static fn (PortalLoginCreatedNotification $notification, array $channels): bool => $channels === ['mail'],
        );
    }

    /**
     * The password is in the mail body, and **only** there.
     *
     * A password that reaches the student but also sits in `notifications`, `jobs` or `failed_jobs`
     * is a plain-text credential in three tables that nothing ever prunes. `via()` returning only
     * `mail`, and the class not implementing `ShouldQueue`, are what prevent that — both are easy
     * to "improve" away, which is why they are asserted rather than commented.
     */
    #[Test]
    public function the_credentials_notification_is_mail_only_and_never_queued(): void
    {
        $notification = new PortalLoginCreatedNotification('s3cret-Passw0rd', 'Ayesha', 'student portal', 'see your courses');

        $this->assertSame(['mail'], $notification->via(new User));
        $this->assertFalse(
            method_exists($notification, 'toArray'),
            'A `toArray()` puts the plain password into the `notifications` table as JSON.',
        );
        $this->assertNotInstanceOf(
            ShouldQueue::class,
            $notification,
            'Queuing serialises the plain password into `jobs`, and into `failed_jobs` if it fails.',
        );

        $mail = $notification->toMail((new User)->forceFill(['email' => 'ayesha@example.test']));
        $rendered = implode("\n", array_map(
            static fn ($line): string => is_string($line) ? $line : (string) json_encode($line),
            array_merge($mail->introLines, $mail->outroLines),
        ));

        $this->assertStringContainsString('s3cret-Passw0rd', $rendered, 'The mail must actually carry the password.');
        $this->assertStringContainsString('ayesha@example.test', $rendered, 'The mail must say which address to sign in with.');
    }

    /**
     * The second door into activation.
     *
     * `AdmissionService::activate()` created the login; a student activated from the Students
     * screen never passes through an admission, so they reached Active with no account at all.
     */
    #[Test]
    public function activating_a_student_from_the_students_screen_creates_and_emails_the_login(): void
    {
        Notification::fake();

        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $this->assertNull($student->user_id, 'A student starts without a login — that is D2.');

        // §68's ladder: applied → registered → active. The move that matters is the last one.
        $this->studentService()->changeStatus($student, StudentStatus::Registered, null, $actor);
        $this->studentService()->changeStatus($student->refresh(), StudentStatus::Active, null, $actor);

        $student = Student::query()->with('user')->findOrFail($student->getKey());

        $this->assertNotNull($student->user_id, 'Activation left the student with no way to sign in.');
        Notification::assertSentTo($student->user, PortalLoginCreatedNotification::class);
    }

    /** Called twice, one account and one email — never a second password that invalidates the first. */
    #[Test]
    public function creating_the_login_twice_sends_one_email(): void
    {
        Notification::fake();

        $actor = $this->createSuperAdmin();
        $student = $this->student([], $actor);

        $first = $this->studentService()->createLogin($student, $actor);
        $second = $this->studentService()->createLogin($student->refresh(), $actor);

        $this->assertNotNull($first);
        $this->assertSame($first->getKey(), $second?->getKey());

        Notification::assertSentToTimes($first, PortalLoginCreatedNotification::class, 1);
    }

    /** No address, no account: a login nobody can be told about is a password nobody has. */
    #[Test]
    public function a_student_without_an_email_gets_no_login_and_no_mail(): void
    {
        Notification::fake();

        $actor = $this->createSuperAdmin();
        $student = $this->student(['email' => null], $actor);

        $this->assertNull($this->studentService()->createLogin($student, $actor));
        Notification::assertNothingSent();
    }
}
