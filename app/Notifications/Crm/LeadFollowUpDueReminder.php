<?php

declare(strict_types=1);

namespace App\Notifications\Crm;

use App\Models\Crm\Lead;
use App\Models\Crm\LeadFollowUp;
use App\Models\Scopes\LeadVisibilityScope;
use App\Notifications\Crm\Concerns\BuildsCrmNotification;
use App\Support\Format;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The follow-up reminder §18 asks for (phase-05 §10.3, §10.5 `crm:follow-up-reminders`, test 28).
 *
 * To the follow-up's assignee: the lead's name and company, the scheduled time (in the display timezone), what to
 * say, and a deep link. Channels come from `crm.follow_up_reminder_channels`. Sent at most once per follow-up —
 * `reminder_sent_at` is stamped before it is queued.
 */
final class LeadFollowUpDueReminder extends Notification
{
    use BuildsCrmNotification;

    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly LeadFollowUp $followUp,
        public readonly array $channels = ['database'],
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->crmChannels($notifiable, $this->channels);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(sprintf('Follow-up due: %s', $this->leadName()))
            ->greeting('Follow-up reminder')
            ->line(sprintf('%s with %s at %s.', $this->typeLabel(), $this->leadName(), $this->when()))
            ->when($this->notes() !== null, fn (MailMessage $mail) => $mail->line('Notes: '.$this->notes()))
            ->action('Open the lead', $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $lead = $this->lead();

        return [
            'kind' => 'lead.follow_up_due',
            'module' => 'leads',
            'lead_follow_up_id' => (int) $this->followUp->getKey(),
            'lead_id' => (int) $this->followUp->getAttribute('lead_id'),
            'title' => sprintf('Follow-up due: %s', $this->leadName()),
            'body' => sprintf('%s at %s', $this->typeLabel(), $this->when()),
            'lead_name' => $lead?->getAttribute('name'),
            'company' => $lead?->getAttribute('company'),
            'scheduled_at' => $this->scheduledAt()?->toIso8601String(),
            'notes' => $this->notes(),
            'url' => $this->url(),
        ];
    }

    private function lead(): ?Lead
    {
        /** @var Lead|null $lead */
        $lead = Lead::query()
            ->withoutGlobalScope(LeadVisibilityScope::class)
            ->withTrashed()
            ->find($this->followUp->getAttribute('lead_id'), ['id', 'lead_no', 'name', 'company']);

        return $lead;
    }

    private function leadName(): string
    {
        $lead = $this->lead();

        if (! $lead instanceof Lead) {
            return 'a lead';
        }

        $company = trim((string) $lead->getAttribute('company'));

        return (string) $lead->getAttribute('name').($company === '' ? '' : ' ('.$company.')');
    }

    private function typeLabel(): string
    {
        $type = $this->followUp->getAttribute('type');

        return $type instanceof BackedEnum && method_exists($type, 'label') ? (string) $type->label() : 'Follow-up';
    }

    private function scheduledAt(): ?CarbonInterface
    {
        $at = $this->followUp->getAttribute('scheduled_at');

        return $at instanceof CarbonInterface ? $at : null;
    }

    private function when(): string
    {
        $at = $this->scheduledAt();

        return $at === null ? '-' : Format::dateTime($at);
    }

    private function notes(): ?string
    {
        $notes = trim((string) $this->followUp->getAttribute('notes'));

        return $notes === '' ? null : $notes;
    }

    private function url(): string
    {
        $id = (int) $this->followUp->getAttribute('lead_id');

        return $this->link('admin.leads.show', ['lead' => $id], '/admin/leads/'.$id);
    }
}
