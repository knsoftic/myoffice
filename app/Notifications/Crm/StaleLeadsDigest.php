<?php

declare(strict_types=1);

namespace App\Notifications\Crm;

use App\Notifications\Crm\Concerns\BuildsCrmNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The optional daily digest of an assignee's stale leads (phase-05 §10.3, §10.5 `crm:stale-lead-digest`).
 *
 * Sent only while `crm.stale_digest_enabled` is on, one per assignee, listing only that assignee's own leads.
 */
final class StaleLeadsDigest extends Notification
{
    use BuildsCrmNotification;

    /**
     * @param  list<array{id: int, lead_no: string, name: string}>  $leads  at most the first ten
     */
    public function __construct(
        public readonly int $count,
        public readonly int $staleDays,
        public readonly array $leads,
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
        $mail = (new MailMessage)
            ->subject(sprintf('%d lead(s) with no activity for %d days', $this->count, $this->staleDays))
            ->greeting('Leads waiting on you');

        foreach ($this->leads as $lead) {
            $mail->line(sprintf('%s — %s', $lead['lead_no'], $lead['name']));
        }

        return $mail->action('Open my leads', $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'lead.stale_digest',
            'module' => 'leads',
            'title' => sprintf('%d stale lead(s)', $this->count),
            'body' => sprintf('No activity for %d days or more.', $this->staleDays),
            'leads' => $this->leads,
            'url' => $this->url(),
        ];
    }

    private function url(): string
    {
        return $this->link('admin.leads.index', ['assignee' => 'me'], '/admin/leads?assignee=me');
    }
}
