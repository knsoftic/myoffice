<?php

declare(strict_types=1);

namespace App\Notifications\Crm;

use App\Models\Crm\Lead;
use App\Notifications\Crm\Concerns\BuildsCrmNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A lead is now yours" — to the new assignee only (phase-05 §6.1 `assign()`, §10.3).
 *
 * Carries the lead number, name, company and a deep link; never another rep's pipeline data.
 */
final class LeadAssignedToYou extends Notification
{
    use BuildsCrmNotification;

    public function __construct(
        public readonly Lead $lead,
        public readonly ?string $assignedByName = null,
        public readonly ?string $reason = null,
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
            ->subject(sprintf('Lead %s assigned to you', (string) $this->lead->getAttribute('lead_no')))
            ->greeting('A lead is now yours')
            ->line($this->headline())
            ->when($this->reason !== null && $this->reason !== '', fn (MailMessage $mail) => $mail->line('Note: '.$this->reason))
            ->action('Open the lead', $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'lead.assigned',
            'module' => 'leads',
            'lead_id' => (int) $this->lead->getKey(),
            'title' => sprintf('Lead %s assigned to you', (string) $this->lead->getAttribute('lead_no')),
            'body' => $this->headline(),
            'assigned_by' => $this->assignedByName,
            'reason' => $this->reason,
            'url' => $this->url(),
        ];
    }

    private function headline(): string
    {
        $company = trim((string) $this->lead->getAttribute('company'));

        return trim(sprintf(
            '%s%s%s',
            (string) $this->lead->getAttribute('name'),
            $company === '' ? '' : ' ('.$company.')',
            $this->assignedByName === null ? '' : ' — assigned by '.$this->assignedByName,
        ));
    }

    private function url(): string
    {
        $id = (int) $this->lead->getKey();

        return $this->link('admin.leads.show', ['lead' => $id], '/admin/leads/'.$id);
    }
}
