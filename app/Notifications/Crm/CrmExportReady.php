<?php

declare(strict_types=1);

namespace App\Notifications\Crm;

use App\Notifications\Crm\Concerns\BuildsCrmNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your export is ready" — delivers a queued CRM export (phase-05 §6.10, §10.4 `BuildCrmExport`, test 84).
 *
 * The link points at a controller route that re-runs the permission chain and checks the requester before it
 * streams the file from the private disk (D21); the file is never attached and never public.
 */
final class CrmExportReady extends Notification
{
    use BuildsCrmNotification;

    public function __construct(
        public readonly string $type,
        public readonly int $rows,
        public readonly string $downloadUrl,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->crmChannels($notifiable, ['database', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(sprintf('Your %s export is ready', $this->type))
            ->line(sprintf('%d row(s) were exported.', $this->rows))
            ->action('Download the export', $this->downloadUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'crm.export_ready',
            'module' => $this->type === 'clients' ? 'clients' : 'leads',
            'title' => sprintf('Your %s export is ready', $this->type),
            'body' => sprintf('%d row(s)', $this->rows),
            'url' => $this->downloadUrl,
        ];
    }
}
