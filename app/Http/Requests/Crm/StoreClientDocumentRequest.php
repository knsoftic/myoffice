<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\DataObjects\Crm\DocumentData;
use App\Enums\ClientDocumentCategory;
use App\Models\Crm\Client;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Upload a private client document — `admin.clients.documents.store`, `can:client_documents.upload`
 * (phase-05 §2.9, §6.8 `upload()`, §8.9, tests 65, 66).
 *
 * The first line of the §6.8 defence; the service repeats every check and sniffs the MIME type from the content:
 *
 *   · the extension must be in `security.allowed_file_types` (never a script or executable type, whatever the
 *     setting says) and the size within `security.max_upload_mb`;
 *   · `.php`, `.phtml`, `.svg`, `.html` and any double extension (`invoice.pdf.php`) are refused outright;
 *   · `mimes` compares the extension with the type guessed from the bytes, so a PDF renamed `.docx` fails here.
 *
 * `visible_to_client = true` exposes the file to the portal on upload, which is the "share" act of
 * `client_documents.change_status`; without that ability the toggle may only be left off.
 */
class StoreClientDocumentRequest extends CrmFormRequest
{
    /** The document types this store can ever hold, before `security.allowed_file_types` narrows them. */
    public const DOCUMENT_TYPES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'png', 'jpg', 'jpeg', 'webp', 'zip'];

    /** Refused regardless of the whitelist (§6.8). */
    public const REFUSED_EXTENSIONS = ['php', 'phtml', 'phar', 'svg', 'svgz', 'html', 'htm', 'xhtml', 'js', 'exe', 'sh', 'bat'];

    /** The store's own ceiling; `security.max_upload_mb` and PHP's limits can only lower it. */
    public const MAX_KILOBYTES = 51200;

    public function authorize(): bool
    {
        $client = $this->boundModel('client', Client::class);

        return $client instanceof Client && $this->actorCan('client_documents.upload');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $extensions = $this->uploadExtensions(self::DOCUMENT_TYPES);

        return array_merge([
            'file' => [
                'required',
                'file',
                $this->singleSafeExtension(),
                'extensions:'.implode(',', $extensions === [] ? ['pdf'] : $extensions),
                'mimes:'.implode(',', $extensions === [] ? ['pdf'] : $extensions),
                'max:'.$this->uploadKilobytes(self::MAX_KILOBYTES),
            ],
        ], $this->metadataRules(), [
            'visible_to_client' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->boolean('visible_to_client') && ! $this->actorCan('client_documents.change_status')) {
                    $validator->errors()->add('visible_to_client', 'Only someone who can share documents may make this one visible to the client.');
                }
            },
        ];
    }

    /**
     * The editable description of a document (`DocumentData` keys).
     *
     * @return array<string, list<mixed>>
     */
    protected function metadataRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', Rule::enum(ClientDocumentCategory::class)],
            'description' => ['nullable', 'string', 'max:255'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', $this->notBeforeValidFrom()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.extensions' => 'That file type is not allowed.',
            'file.mimes' => 'The file content does not match its type.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['title', 'category', 'description', 'valid_from', 'expires_at']);
    }

    public function toData(): DocumentData
    {
        $data = $this->validated();
        unset($data['file']);

        return DocumentData::fromArray($data);
    }

    public function document(): UploadedFile
    {
        $file = $this->file('file');

        abort_unless($file instanceof UploadedFile, 422);

        return $file;
    }

    /**
     * `expires_at` is not earlier than `valid_from` — checked only when both are given (a bare `after_or_equal`
     * rule fails whenever `valid_from` is blank).
     */
    protected function notBeforeValidFrom(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $from = $this->input('valid_from');

            if (! is_string($value) || ! is_string($from) || $value === '' || $from === '') {
                return;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 && strcmp($value, $from) < 0) {
                $fail('The expiry date cannot be before the valid-from date.');
            }
        };
    }

    /**
     * No refused extension anywhere in the original name — `invoice.pdf.php` and `report.php.pdf` both fail.
     */
    private function singleSafeExtension(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $name = mb_strtolower($value->getClientOriginalName());
            $parts = explode('.', $name);
            array_shift($parts);

            foreach ($parts as $part) {
                if (in_array(trim($part), self::REFUSED_EXTENSIONS, true) || preg_match('/^php\d*$/', trim($part)) === 1) {
                    $fail('That file type is not allowed.');

                    return;
                }
            }

            if (count($parts) > 1) {
                $fail('Rename the file so it has a single extension.');
            }
        };
    }
}
