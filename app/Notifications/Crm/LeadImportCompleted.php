<?php

declare(strict_types=1);

namespace App\Notifications\Crm;

use App\Models\Crm\LeadImport;
use App\Notifications\Crm\Concerns\BuildsCrmNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your import finished" — to the importer, with the four counts and the error-report link (phase-05 §10.3). One
 * notification per import, never one per row (R-6).
 */
final class LeadImportCompleted extends Notification
{
    use BuildsCrmNotification;

    public function __construct(
        public readonly LeadImport $import,
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
            ->subject(sprintf('Lead import finished: %s', (string) $this->import->getAttribute('original_filename')))
            ->line($this->summary())
            ->action('Open the import', $this->url());

        if ($this->hasErrors()) {
            $mail->line('Some rows were not imported. Download the error report from the import screen.');
        }

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $id = (int) $this->import->getKey();

        return [
            'kind' => 'lead.import_completed',
            'module' => 'leads',
            'lead_import_id' => $id,
            'title' => 'Lead import finished',
            'body' => $this->summary(),
            'created' => (int) $this->import->getAttribute('created_count'),
            'updated' => (int) $this->import->getAttribute('updated_count'),
            'skipped' => (int) $this->import->getAttribute('skipped_count'),
            'failed' => (int) $this->import->getAttribute('failed_count'),
            'url' => $this->url(),
            'error_report_url' => $this->hasErrors() ? $this->link('admin.leads.import.errors', ['import' => $id], '/admin/leads/import/'.$id.'/errors') : null,
        ];
    }

    private function summary(): string
    {
        return sprintf(
            '%d created, %d updated, %d skipped, %d failed.',
            (int) $this->import->getAttribute('created_count'),
            (int) $this->import->getAttribute('updated_count'),
            (int) $this->import->getAttribute('skipped_count'),
            (int) $this->import->getAttribute('failed_count'),
        );
    }

    private function hasErrors(): bool
    {
        return trim((string) $this->import->getAttribute('error_report_path')) !== '';
    }

    private function url(): string
    {
        $id = (int) $this->import->getKey();

        return $this->link('admin.leads.import.show', ['import' => $id], '/admin/leads/import/'.$id);
    }
}
