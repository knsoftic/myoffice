<?php

declare(strict_types=1);

namespace App\Notifications\Crm;

use App\Models\Crm\ClientDocument;
use App\Notifications\Crm\Concerns\BuildsCrmNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A document was shared with you" — to the client's portal logins (phase-05 §6.8 `setClientVisibility()`, §10.3,
 * test 68). Names the document and links to the portal's documents screen; the file itself is never attached.
 */
final class ClientDocumentShared extends Notification
{
    use BuildsCrmNotification;

    public function __construct(
        public readonly ClientDocument $document,
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
            ->subject(sprintf('New document: %s', (string) $this->document->getAttribute('title')))
            ->line(sprintf('"%s" has been shared with you in the client portal.', (string) $this->document->getAttribute('title')))
            ->action('View your documents', $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $category = $this->document->getAttribute('category');

        return [
            'kind' => 'client.document_shared',
            'module' => 'client_documents',
            'client_document_id' => (int) $this->document->getKey(),
            'title' => 'New document shared with you',
            'body' => (string) $this->document->getAttribute('title'),
            'category' => $category instanceof \BackedEnum ? $category->value : $category,
            'url' => $this->url(),
        ];
    }

    private function url(): string
    {
        return $this->link('client.documents.index', [], '/client/documents');
    }
}
