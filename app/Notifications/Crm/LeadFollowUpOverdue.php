<?php

declare(strict_types=1);

namespace App\Notifications\Crm;

use App\Models\Crm\Lead;
use App\Models\Crm\LeadFollowUp;
use App\Models\Scopes\LeadVisibilityScope;
use App\Notifications\Crm\Concerns\BuildsCrmNotification;
use App\Support\Format;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A follow-up was missed" — once per follow-up, to its assignee, when `crm:follow-ups-mark-missed` moves it past
 * `crm.follow_up_overdue_grace_minutes` (phase-05 §10.3, §10.5, test 30).
 */
final class LeadFollowUpOverdue extends Notification
{
    use BuildsCrmNotification;

    public function __construct(
        public readonly LeadFollowUp $followUp,
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
            ->subject(sprintf('Missed follow-up: %s', $this->leadName()))
            ->line(sprintf('The follow-up with %s scheduled for %s was not completed.', $this->leadName(), $this->when()))
            ->action('Reschedule it', $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'lead.follow_up_overdue',
            'module' => 'leads',
            'lead_follow_up_id' => (int) $this->followUp->getKey(),
            'lead_id' => (int) $this->followUp->getAttribute('lead_id'),
            'title' => sprintf('Missed follow-up: %s', $this->leadName()),
            'body' => sprintf('Scheduled for %s', $this->when()),
            'url' => $this->url(),
        ];
    }

    private function leadName(): string
    {
        /** @var Lead|null $lead */
        $lead = Lead::query()
            ->withoutGlobalScope(LeadVisibilityScope::class)
            ->withTrashed()
            ->find($this->followUp->getAttribute('lead_id'), ['id', 'name', 'company']);

        if (! $lead instanceof Lead) {
            return 'a lead';
        }

        $company = trim((string) $lead->getAttribute('company'));

        return (string) $lead->getAttribute('name').($company === '' ? '' : ' ('.$company.')');
    }

    private function when(): string
    {
        $at = $this->followUp->getAttribute('scheduled_at');

        return $at instanceof CarbonInterface ? Format::dateTime($at) : '-';
    }

    private function url(): string
    {
        $id = (int) $this->followUp->getAttribute('lead_id');

        return $this->link('admin.leads.show', ['lead' => $id], '/admin/leads/'.$id);
    }
}
