<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Crm\Client;
use App\Models\Crm\ClientDocument;

/**
 * Share a document with the client portal, or withdraw it — `admin.clients.documents.visibility`,
 * `can:changeVisibility,document` (phase-05 §6.8 `setClientVisibility(ClientDocument, bool, ?string $reason)`,
 * test 68).
 *
 * `visible_to_client` is required and boolean; turning it on notifies the client's portal contacts immediately,
 * which is why the screen confirms first.
 */
final class ChangeDocumentVisibilityRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        $client = $this->boundModel('client', Client::class);
        $document = $this->boundModel('document', ClientDocument::class);

        return $client instanceof Client
            && $document instanceof ClientDocument
            && (int) $document->client_id === (int) $client->getKey()
            && $this->actorCan('changeVisibility', $document);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'visible_to_client' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['reason']);
    }

    public function visible(): bool
    {
        return filter_var($this->validated('visible_to_client'), FILTER_VALIDATE_BOOLEAN);
    }
}
