<?php

declare(strict_types=1);

namespace App\Notifications\Crm;

use App\Models\Crm\Client;
use App\Notifications\Crm\Concerns\BuildsCrmNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The client portal invitation (phase-05 §6.7 `enablePortal()`, §10.3, test 62).
 *
 * Mail only. It carries a **password-set link** — the standard password-reset route with a fresh broker token —
 * and **never a password**: no password exists that the sender could read, and nothing in this class accepts one.
 */
final class ClientPortalInvitation extends Notification
{
    use BuildsCrmNotification;

    public function __construct(
        public readonly Client $client,
        #[\SensitiveParameter]
        private readonly string $token,
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
        $email = (string) ($notifiable->email ?? '');

        return (new MailMessage)
            ->subject('Your client portal is ready')
            ->greeting(sprintf('Hello %s,', (string) ($notifiable->name ?? '')))
            ->line(sprintf('A client portal account has been created for %s.', (string) $this->client->getAttribute('display_name')))
            ->line('Use the button below to choose your own password and sign in. The link can be used once and expires.')
            ->action('Set your password', $this->setPasswordUrl($email))
            ->line('If you were not expecting this invitation you can ignore this email.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'client.portal_invitation',
            'module' => 'clients',
            'client_id' => (int) $this->client->getKey(),
            'title' => 'Client portal invitation sent',
        ];
    }

    private function setPasswordUrl(string $email): string
    {
        return $this->link(
            'password.reset',
            ['token' => $this->token, 'email' => $email],
            '/reset-password/'.rawurlencode($this->token).'?email='.rawurlencode($email),
        );
    }
}
