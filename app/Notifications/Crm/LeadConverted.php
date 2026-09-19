<?php

declare(strict_types=1);

namespace App\Notifications\Crm;

use App\Models\Crm\Client;
use App\Models\Crm\LeadConversion;
use App\Notifications\Crm\Concerns\BuildsCrmNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A lead became a client" (phase-05 §10.3) — to the new client's account manager and to the converter.
 */
final class LeadConverted extends Notification
{
    use BuildsCrmNotification;

    public function __construct(
        public readonly LeadConversion $conversion,
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
            ->subject(sprintf('Lead converted: %s', $this->clientLabel()))
            ->line(sprintf('Lead %s was converted into client %s.', $this->leadNumber(), $this->clientLabel()))
            ->action('Open the client', $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'lead.converted',
            'module' => 'clients',
            'lead_conversion_id' => (int) $this->conversion->getKey(),
            'lead_id' => (int) $this->conversion->getAttribute('lead_id'),
            'client_id' => $this->conversion->getAttribute('client_id'),
            'title' => sprintf('Lead converted: %s', $this->clientLabel()),
            'body' => sprintf('Lead %s is now client %s.', $this->leadNumber(), $this->clientLabel()),
            'url' => $this->url(),
        ];
    }

    private function client(): ?Client
    {
        $id = $this->conversion->getAttribute('client_id');

        return $id === null ? null : Client::query()->withTrashed()->find((int) $id, ['id', 'client_code', 'name', 'company_name']);
    }

    private function clientLabel(): string
    {
        $client = $this->client();

        if (! $client instanceof Client) {
            return 'a client';
        }

        return trim(sprintf('%s %s', (string) $client->getAttribute('client_code'), (string) $client->getAttribute('display_name')));
    }

    private function leadNumber(): string
    {
        $snapshot = $this->conversion->getAttribute('lead_snapshot');

        return is_array($snapshot) && isset($snapshot['lead_no']) ? (string) $snapshot['lead_no'] : '#'.(int) $this->conversion->getAttribute('lead_id');
    }

    private function url(): string
    {
        $id = (int) $this->conversion->getAttribute('client_id');

        return $this->link('admin.clients.show', ['client' => $id], '/admin/clients/'.$id);
    }
}
