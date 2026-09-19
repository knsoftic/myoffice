<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\DataObjects\Crm\ImportOptions;
use App\Http\Requests\Crm\Concerns\ValidatesImportOptions;
use Illuminate\Http\UploadedFile;

/**
 * Stage a CSV for import — `admin.leads.import.store`, `can:leads.import` (phase-05 §6.6 `stage()`, §8.6 step 1,
 * test 38).
 *
 * The first gate only: a `.csv` / `.txt` name whose **content** is text (`mimetypes` reads the bytes, so a PHP file
 * renamed `.csv` is refused here), within `security.max_upload_mb`. The service sniffs the MIME type again, the
 * delimiter and encoding, strips the BOM and refuses a file above `crm.import_max_rows` — with nothing written.
 */
final class StoreLeadImportRequest extends CrmFormRequest
{
    use ValidatesImportOptions;

    /** The importer's own ceiling; the settings and PHP can only lower it. */
    public const MAX_KILOBYTES = 20480;

    public const MIME_TYPES = ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'text/comma-separated-values'];

    public function authorize(): bool
    {
        return $this->actorCan('leads.import');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'file' => [
                'required',
                'file',
                'extensions:csv,txt',
                'mimetypes:'.implode(',', self::MIME_TYPES),
                'max:'.$this->uploadKilobytes(self::MAX_KILOBYTES),
            ],
        ], $this->importOptionRules());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a CSV file to import.',
            'file.extensions' => 'Upload a .csv file.',
            'file.mimetypes' => 'That file is not a plain CSV file.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings($this->importOptionNames());
    }

    public function csv(): UploadedFile
    {
        $file = $this->file('file');

        abort_unless($file instanceof UploadedFile, 422);

        return $file;
    }

    public function toOptions(): ImportOptions
    {
        $data = $this->validated();
        unset($data['file']);

        return ImportOptions::fromArray($this->resolvedImportOptions($data));
    }
}
