<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;

/**
 * Collect a queued CRM export — `admin.leads.export.download` (`can:leads.export`) and
 * `admin.clients.export.download` (`can:clients.export`) (phase-05 §6.10, §10.4 `BuildCrmExport`, test 84, D21).
 *
 * The link in the "your export is ready" notification carries an encrypted `token`; `CrmExportFiles::download()`
 * decrypts it, refuses anyone but the user who asked for the export and any link past its lifetime, and streams the
 * file from the private disk. This request only checks the token's shape; a hostile `?token[]=x` is a 422.
 */
final class DownloadCrmExportRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        return match ($this->route()?->getName()) {
            'admin.leads.export.download' => $this->actorCan('leads.export'),
            'admin.clients.export.download' => $this->actorCan('clients.export'),
            default => false,
        };
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:4096'],
        ];
    }

    /**
     * A broken or tampered link is a 404, not a redirect back to wherever the referrer points.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        abort(404);
    }

    public function token(): string
    {
        return (string) $this->validated('token');
    }
}
