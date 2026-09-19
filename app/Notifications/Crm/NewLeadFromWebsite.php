<?php

declare(strict_types=1);

namespace App\Notifications\Crm;

use App\Enums\InquirySource;
use App\Models\Crm\Lead;
use App\Notifications\Crm\Concerns\BuildsCrmNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A website inquiry became a lead" (phase-05 §10.3) — to the lead's assignee, or, while it is unassigned, to the
 * users holding `leads.assign`.
 */
final class NewLeadFromWebsite extends Notification
{
    use BuildsCrmNotification;

    public function __construct(
        public readonly Lead $lead,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->crmChannels($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(sprintf('New website lead %s: %s', (string) $this->lead->getAttribute('lead_no'), (string) $this->lead->getAttribute('name')))
            ->greeting('New lead from the website')
            ->line($this->summary())
            ->when($this->excerpt((string) $this->lead->getAttribute('notes')) !== '', fn (MailMessage $mail) => $mail->line($this->excerpt((string) $this->lead->getAttribute('notes'))))
            ->action('Open the lead', $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'lead.new_from_website',
            'module' => 'leads',
            'lead_id' => (int) $this->lead->getKey(),
            'title' => sprintf('New website lead %s', (string) $this->lead->getAttribute('lead_no')),
            'body' => $this->summary(),
            'unassigned' => $this->lead->getAttribute('assigned_to') === null,
            'url' => $this->url(),
        ];
    }

    private function summary(): string
    {
        $company = trim((string) $this->lead->getAttribute('company'));
        $source = $this->lead->getAttribute('source');
        $sourceLabel = $source instanceof InquirySource ? $source->label() : 'Website';

        return sprintf(
            '%s%s via %s%s',
            (string) $this->lead->getAttribute('name'),
            $company === '' ? '' : ' ('.$company.')',
            $sourceLabel,
            $this->lead->getAttribute('interested_service') ? ' — '.(string) $this->lead->getAttribute('interested_service') : '',
        );
    }

    private function url(): string
    {
        $id = (int) $this->lead->getKey();

        return $this->link('admin.leads.show', ['lead' => $id], '/admin/leads/'.$id);
    }
}
