<?php

declare(strict_types=1);

namespace App\Notifications\Institute;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * The portal credentials for a student or a teacher, sent once, to them.
 *
 * `StudentService::createLogin()` and `TeacherService::createLogin()` each generated a 16-character
 * password, wrote its hash and kept no copy. Both carried the same comment — *"Never returned, never
 * logged: the notification is the only thing that sees it"* — and **there was no notification.** The
 * account was created, the admin screen reported *"The password was sent to them"*, and from that
 * moment the password existed nowhere. Every login made that way was unusable by the one person it
 * belonged to. This is the notification both of those sentences were describing.
 *
 * **Mail only, and deliberately never `database`.** A database notification is a row in
 * `notifications` holding its payload as JSON — putting the password there would store in plain text
 * the one value the services go out of their way not to keep. `toArray()` is absent for the same
 * reason: there is no safe rendering of this into a stored record.
 *
 * **Not `ShouldQueue`, and that is the subtler half.** A queued notification is serialised into the
 * `jobs` table before it is sent. The password would sit there in plain text until a worker picked it
 * up — and, if the job failed, in `failed_jobs` indefinitely. So this one is sent inline: the caller
 * waits for the mail attempt, which is the right trade for a message whose whole value is that it is
 * the only copy. It is also why the send is wrapped in the services rather than here — a mail server
 * that is down must not undo an account that is already correct.
 *
 * **Why a password and not a set-password link.** `ClientPortalInvitation` sends a link, and for a
 * client that is better. It is wrong here: the reset broker expires in sixty minutes
 * (`config/auth.php`), and a student registered at the front desk may not open their mail until that
 * evening. A link that has already died teaches them the institute's email does not work. The
 * password is temporary in practice — `must_change_password` is set, so the first sign-in goes
 * straight to choosing their own.
 *
 * The consequence to understand: **if mail is not configured, this goes nowhere and the password is
 * lost.** That is not a flaw — it is what "the only copy" means. Both services refuse outright when
 * there is no address to send to, and `Settings → Email` must be working before the first login is
 * created.
 */
final class PortalLoginCreatedNotification extends Notification
{
    /**
     * @param  string  $portal  What to call the place they are signing in to, lower case: "student
     *                          portal", "teacher portal". It is read by a person, not matched.
     * @param  string  $whatTheyCanSee  The one line that makes the mail worth opening, without a
     *                                  trailing full stop.
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly string $password,
        private readonly string $recipientName,
        private readonly string $portal,
        private readonly string $whatTheyCanSee,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = trim((string) setting('company.name', ''));

        if ($company === '') {
            $company = (string) config('app.name', 'the institute');
        }

        $name = trim($this->recipientName);
        $email = (string) ($notifiable->email ?? '');

        return (new MailMessage)
            ->subject(sprintf('Your %s %s account', $company, $this->portal))
            ->greeting($name === '' ? 'Hello,' : sprintf('Hello %s,', $name))
            ->line(sprintf(
                'An account has been created for you on the %s %s, where you can %s.',
                $company,
                $this->portal,
                $this->whatTheyCanSee,
            ))
            ->line('Sign in with these details:')
            ->line(sprintf('**Email:** %s', $email))
            ->line(sprintf('**Password:** %s', $this->password))
            ->action('Sign in', url('/login'))
            // Said plainly because it is true, and because it is the only warning they get: nothing
            // at the institute can look this password up and send it again.
            ->line('You will be asked to choose your own password the first time you sign in. Keep this email until you have — nobody at the institute can look this password up for you.')
            ->salutation(sprintf('— %s', $company));
    }
}
