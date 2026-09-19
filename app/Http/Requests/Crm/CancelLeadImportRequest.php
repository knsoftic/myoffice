<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Crm\LeadImport;

/**
 * Cancel an import — `admin.leads.import.cancel`, `can:leads.import` (phase-05 §6.6 `cancel(LeadImport, string
 * $reason)`, test 42). Already-created leads are kept; the reason is mandatory.
 */
final class CancelLeadImportRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        $import = $this->boundModel('import', LeadImport::class);

        return $import instanceof LeadImport && $this->actorCan('leads.import') && $this->actorCan('cancel', $import);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the import is being cancelled.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['reason']);
    }

    public function reasonText(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
