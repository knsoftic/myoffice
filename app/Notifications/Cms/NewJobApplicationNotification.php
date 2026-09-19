<?php

declare(strict_types=1);

namespace App\Notifications\Cms;

use App\Models\Cms\JobApplication;
use App\Models\Cms\JobOpening;
use App\Notifications\Cms\Concerns\BuildsCmsNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A candidate applied" (phase-04 §10.2) — to users holding `job_applications.view_any` (database + mail)
 * and to every address in `website.careers_notify_emails` (mail only).
 *
 * Job title, applicant name, experience and a deep link. **The CV is never attached to an email** (D21):
 * it stays on the private disk and is reachable only through `admin.job-applications.cv`, which re-runs
 * the permission chain and writes the sensitive-access trail.
 */
final class NewJobApplicationNotification extends Notification
{
    use BuildsCmsNotification;

    public function __construct(
        public readonly JobApplication $application,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, ['database', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $experience = $this->application->getAttribute('experience_years');

        return (new MailMessage)
            ->subject(sprintf('New application: %s', $this->jobTitle()))
            ->greeting('New job application')
            ->line(sprintf('Position: %s', $this->jobTitle()))
            ->line(sprintf('Applicant: %s', (string) $this->application->getAttribute('applicant_name')))
            ->when($experience !== null, fn (MailMessage $mail) => $mail->line(sprintf('Experience: %d %s', (int) $experience, (int) $experience === 1 ? 'year' : 'years')))
            ->line('The CV is available in the admin panel only; it is never sent by email.')
            ->action('Review the application', $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'job_application.new',
            'module' => 'job_applications',
            'job_application_id' => (int) $this->application->getKey(),
            'job_opening_id' => (int) $this->application->getAttribute('job_opening_id'),
            'title' => sprintf('New application: %s', $this->jobTitle()),
            'applicant_name' => (string) $this->application->getAttribute('applicant_name'),
            'experience_years' => $this->application->getAttribute('experience_years'),
            'url' => $this->url(),
        ];
    }

    private function jobTitle(): string
    {
        $title = JobOpening::withTrashed()->whereKey($this->application->getAttribute('job_opening_id'))->value('title');

        return is_string($title) && $title !== '' ? $title : 'a job opening';
    }

    private function url(): string
    {
        $id = (int) $this->application->getKey();

        return $this->link('admin.job-applications.show', ['application' => $id], '/admin/job-applications/'.$id);
    }
}
