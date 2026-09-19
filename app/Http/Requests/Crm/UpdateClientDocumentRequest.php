<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\DataObjects\Crm\DocumentData;
use App\Models\Crm\Client;
use App\Models\Crm\ClientDocument;
use Illuminate\Validation\Validator;

/**
 * Edit a document's description — `admin.clients.documents.update`, `can:update,document` (phase-05 §7, §8.9).
 *
 * Title, category, description and the validity dates only. The file itself is never replaced (upload a new
 * document), and visibility has its own route and ability (`admin.clients.documents.visibility`).
 */
final class UpdateClientDocumentRequest extends StoreClientDocumentRequest
{
    public function authorize(): bool
    {
        $client = $this->boundModel('client', Client::class);
        $document = $this->boundModel('document', ClientDocument::class);

        return $client instanceof Client
            && $document instanceof ClientDocument
            && (int) $document->client_id === (int) $client->getKey()
            && $this->actorCan('update', $document);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->metadataRules();
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [];
    }

    public function toData(): DocumentData
    {
        return DocumentData::fromArray($this->validated());
    }
}
