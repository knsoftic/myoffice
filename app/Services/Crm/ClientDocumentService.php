<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\DataObjects\Crm\DocumentData;
use App\Enums\PanelType;
use App\Events\Crm\ClientDocumentSharedWithClient;
use App\Events\Crm\ClientDocumentUploaded;
use App\Models\Crm\Client;
use App\Models\Crm\ClientDocument;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Crm\Concerns\InteractsWithCrm;
use App\Services\Crm\Exceptions\CrmRuleException;
use App\Support\SettingsRegistry;
use Carbon\CarbonImmutable;
use finfo;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Client documents on the private disk (phase-05 §2.9 [D-P5-10], §6.8, D21, tests 65-70).
 *
 *   | check        | rule                                                                                          |
 *   |--------------|-----------------------------------------------------------------------------------------------|
 *   | extension    | this store's own list narrowed by `security.allowed_file_types`; `.php`, `.phtml`, `.svg`,     |
 *   |              | `.html` and **any double extension** (`invoice.pdf.php`, `scan.pdf.docx`) refused regardless  |
 *   | content      | the MIME is sniffed from the bytes with `finfo` and must be one the extension allows — a PDF    |
 *   |              | renamed `.docx` is refused                                                                    |
 *   | size         | `security.max_upload_mb`, never above PHP's own limit                                          |
 *   | name         | `clients/{client_id}/documents/{ulid}.{extension}` on the `local` disk — never the user's name |
 *   | duplicates   | a same-checksum document on the same client is reported, not refused                           |
 *   | serving      | `download()` only: policy first, `attachment`, `nosniff`, the stored MIME; every download is  |
 *   |              | an audit row naming the actor and whether the actor was the client                             |
 *
 * `visible_to_client` is the **only** gate that exposes a file to the portal; turning it on stamps `shared_at` /
 * `shared_by` the first time and notifies the client's portal logins. It has no effect on staff.
 */
final class ClientDocumentService
{
    use InteractsWithCrm;
    use WritesAuditTrail;

    public const DISK = 'local';

    private const MODULE = 'client_documents';

    /** The document store's own extension => the MIME types its content may sniff as. */
    public const TYPES = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/cdfv2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/cdfv2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/cdfv2'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'txt' => ['text/plain'],
        'rtf' => ['text/rtf', 'application/rtf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    /** Refused whatever the whitelist says (§6.8). */
    private const ALWAYS_REFUSED = ['php', 'phtml', 'svg', 'svgz', 'html', 'htm', 'xhtml'];

    /** The store's own ceiling; the security setting can only narrow it. */
    private const OWN_MAX_KILOBYTES = 51200;

    public function __construct(
        private readonly FilesystemFactory $storage,
    ) {}

    public function upload(Client $client, UploadedFile $file, DocumentData $data): ClientDocument
    {
        [$extension, $mime, $size, $originalName] = $this->inspect($file);

        $checksum = hash_file('sha256', (string) $file->getRealPath()) ?: null;
        $directory = sprintf('clients/%d/documents', (int) $client->getKey());
        $name = Str::lower((string) Str::ulid()).'.'.$extension;
        $disk = $this->storage->disk(self::DISK);

        $path = $disk->putFileAs($directory, $file, $name);

        if (! is_string($path) || $path === '') {
            throw CrmRuleException::refuse('file', 'The document could not be saved. Try again.');
        }

        try {
            return DB::transaction(function () use ($client, $data, $extension, $mime, $size, $originalName, $checksum, $path): ClientDocument {
                $actorId = $this->actorId();
                $visible = $data->visibleToClient ?? ($this->crmBool('client_visible_documents_default', false) || $data->category->defaultVisibleToClient());
                $now = CarbonImmutable::now();

                $duplicate = $checksum !== null && ClientDocument::query()
                    ->where('client_id', $client->getKey())
                    ->where('checksum', $checksum)
                    ->exists();

                $document = new ClientDocument;
                $document->forceFill([
                    'client_id' => (int) $client->getKey(),
                    'title' => $this->cleanText($data->title, 150) ?? mb_substr(pathinfo($originalName, PATHINFO_FILENAME), 0, 150),
                    'category' => $data->category,
                    'description' => $this->cleanText($data->description),
                    'disk' => self::DISK,
                    'path' => $path,
                    'original_name' => $originalName,
                    'mime_type' => $mime,
                    'extension' => $extension,
                    'size_bytes' => $size,
                    'checksum' => $checksum,
                    'visible_to_client' => $visible,
                    'shared_at' => $visible ? $now : null,
                    'shared_by' => $visible ? $actorId : null,
                    'valid_from' => $data->validFrom,
                    'expires_at' => $data->expiresAt,
                ]);

                $this->withoutModelLogging(static fn (): bool => $document->save());

                $this->audit($document, 'Client document uploaded', [
                    'attributes' => [
                        'client_id' => (int) $client->getKey(),
                        'title' => $document->getAttribute('title'),
                        'category' => $data->category->value,
                        'original_name' => $originalName,
                        'mime_type' => $mime,
                        'size_bytes' => $size,
                        'visible_to_client' => $visible,
                        'duplicate_checksum' => $duplicate,
                    ],
                ], self::MODULE);

                event(new ClientDocumentUploaded($document, $duplicate));

                if ($visible) {
                    event(new ClientDocumentSharedWithClient($document, $actorId));
                }

                return $document;
            });
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }
    }

    /**
     * Another live document of the same client with the same content, for the re-upload warning.
     */
    public function duplicateOf(ClientDocument $document): ?ClientDocument
    {
        $checksum = $document->getAttribute('checksum');

        if ($checksum === null || $checksum === '') {
            return null;
        }

        return ClientDocument::query()
            ->where('client_id', $document->getAttribute('client_id'))
            ->where('checksum', $checksum)
            ->whereKeyNot($document->getKey())
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Edit a document's metadata. Visibility has its own method, so the confirm dialog cannot be skipped.
     */
    public function update(ClientDocument $document, DocumentData $data): ClientDocument
    {
        return DB::transaction(function () use ($document, $data): ClientDocument {
            $locked = $this->lock($document);
            $keys = ['title', 'category', 'description', 'valid_from', 'expires_at'];
            $before = $this->snapshot($locked, $keys);

            $locked->forceFill([
                'title' => $this->cleanText($data->title, 150) ?? $locked->getAttribute('title'),
                'category' => $data->category,
                'description' => $this->cleanText($data->description),
                'valid_from' => $data->validFrom,
                'expires_at' => $data->expiresAt,
            ]);

            $changes = $this->changes($before, $this->snapshot($locked, $keys));

            if ($changes['attributes'] !== []) {
                $this->withoutModelLogging(static fn (): bool => $locked->save());
                $this->audit($locked, 'Client document updated', $changes, self::MODULE);
            }

            $document->setRawAttributes($locked->getAttributes(), true);

            return $document;
        });
    }

    public function setClientVisibility(ClientDocument $document, bool $visible, ?string $reason): ClientDocument
    {
        $reason = $this->cleanText($reason, 500);

        return DB::transaction(function () use ($document, $visible, $reason): ClientDocument {
            $locked = $this->lock($document);
            $before = $this->snapshot($locked, ['visible_to_client', 'shared_at', 'shared_by']);

            if ((bool) $locked->getAttribute('visible_to_client') === $visible) {
                $document->setRawAttributes($locked->getAttributes(), true);

                return $document;
            }

            $actorId = $this->actorId();
            $locked->forceFill(['visible_to_client' => $visible]);

            if ($visible && $locked->getAttribute('shared_at') === null) {
                $locked->forceFill(['shared_at' => CarbonImmutable::now(), 'shared_by' => $actorId]);
            }

            $this->withoutModelLogging(static fn (): bool => $locked->save());

            $this->audit(
                $locked,
                $visible ? 'Client document shared with the client' : 'Client document hidden from the client',
                $this->changes($before, $this->snapshot($locked, ['visible_to_client', 'shared_at', 'shared_by'])),
                self::MODULE,
                $reason,
            );

            if ($visible) {
                event(new ClientDocumentSharedWithClient($locked, $actorId));
            }

            $document->setRawAttributes($locked->getAttributes(), true);

            return $document;
        });
    }

    /**
     * Stream a document to staff (`ClientDocumentPolicy::download`) or to a portal user
     * (`ClientDocumentPolicy::downloadAsClient`, which also requires `visible_to_client`).
     */
    public function download(ClientDocument $document, User $user): StreamedResponse
    {
        $asClient = $this->isPortalUser($user);

        Gate::forUser($user)->authorize($asClient ? 'downloadAsClient' : 'download', $document);

        if ($asClient && ! (bool) $document->getAttribute('visible_to_client')) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $disk = $this->storage->disk((string) ($document->getAttribute('disk') ?: self::DISK));
        $path = (string) $document->getAttribute('path');

        if ($path === '' || ! $disk->exists($path)) {
            abort(Response::HTTP_NOT_FOUND, 'The document file is not available.');
        }

        $extension = strtolower((string) $document->getAttribute('extension'));
        $mime = (string) $document->getAttribute('mime_type');
        $mime = $mime === '' ? 'application/octet-stream' : $mime;

        $this->audit($document, $asClient ? 'Client document downloaded by the client' : 'Client document downloaded', [
            'attributes' => [
                'client_id' => (int) $document->getAttribute('client_id'),
                'downloaded_by' => (int) $user->getKey(),
                'as_client' => $asClient,
                'original_name' => $document->getAttribute('original_name'),
                'size_bytes' => (int) $document->getAttribute('size_bytes'),
            ],
        ], self::MODULE);

        /** @var StreamedResponse $response */
        $response = $disk->download($path, $this->downloadName((string) $document->getAttribute('original_name'), $extension), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);

        return $response;
    }

    /**
     * Soft delete: the file stays on disk so a restore works.
     */
    public function delete(ClientDocument $document, ?string $reason = null): void
    {
        DB::transaction(function () use ($document, $reason): void {
            $locked = $this->lock($document);
            $reason = $this->cleanText($reason, 500);

            if ($reason !== null) {
                $locked->withReason($reason);
            }

            $locked->delete();

            $document->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function restore(ClientDocument $document): ClientDocument
    {
        return DB::transaction(function () use ($document): ClientDocument {
            $locked = $this->lock($document);

            if ($locked->getAttribute('deleted_at') !== null) {
                $locked->restore();
            }

            $document->setRawAttributes($locked->getAttributes(), true);

            return $document;
        });
    }

    /**
     * Permanent removal — Super Admin only (the policy); removes the file and logs it.
     */
    public function forceDelete(ClientDocument $document): void
    {
        $disk = $this->storage->disk((string) ($document->getAttribute('disk') ?: self::DISK));
        $path = (string) $document->getAttribute('path');

        DB::transaction(function () use ($document): void {
            $locked = $this->lock($document);

            $this->audit($locked, 'Client document permanently deleted', [
                'old' => $this->snapshot($locked, ['client_id', 'title', 'category', 'original_name', 'checksum', 'size_bytes']),
                'attributes' => [],
            ], self::MODULE);

            $locked->forceDelete();
        });

        if ($path !== '' && $disk->exists($path)) {
            $disk->delete($path);
        }
    }

    /**
     * The extensions this store accepts right now.
     *
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        $own = array_values(array_diff(array_keys(self::TYPES), self::ALWAYS_REFUSED));

        return SettingsRegistry::uploadExtensions($own, settings_repo()->get('security.allowed_file_types'));
    }

    public function maxKilobytes(): int
    {
        return SettingsRegistry::uploadKilobytes(self::OWN_MAX_KILOBYTES, settings_repo()->get('security.max_upload_mb'));
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: string}
     */
    private function inspect(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw CrmRuleException::refuse('file', 'The document could not be uploaded. Try again.');
        }

        $originalName = $this->safeOriginalName((string) $file->getClientOriginalName());
        $segments = array_map('strtolower', explode('.', $originalName));
        $extension = count($segments) > 1 ? (string) end($segments) : '';

        if ($extension === '' || in_array($extension, self::ALWAYS_REFUSED, true) || preg_match('/^php\d*$/', $extension) === 1) {
            throw CrmRuleException::refuse('file', 'This type of file cannot be uploaded.');
        }

        $known = [...array_keys(self::TYPES), ...self::ALWAYS_REFUSED, ...SettingsRegistry::NEVER_UPLOADABLE_EXTENSIONS];

        foreach (array_slice($segments, 1, -1) as $inner) {
            if (in_array($inner, $known, true) || preg_match('/^php\d*$/', $inner) === 1) {
                throw CrmRuleException::refuse('file', 'A file name with two extensions cannot be uploaded. Rename the file and try again.');
            }
        }

        if (! in_array($extension, $this->allowedExtensions(), true)) {
            throw CrmRuleException::refuse('file', sprintf('Upload one of: %s.', implode(', ', $this->allowedExtensions())));
        }

        $size = (int) $file->getSize();

        if ($size <= 0) {
            throw CrmRuleException::refuse('file', 'The document is empty.');
        }

        if ($size > $this->maxKilobytes() * 1024) {
            throw CrmRuleException::refuse('file', sprintf('The document may be at most %d KB.', $this->maxKilobytes()));
        }

        $path = (string) $file->getRealPath();
        $mime = $path === '' ? '' : strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->file($path));

        if (! in_array($mime, self::TYPES[$extension] ?? [], true)) {
            throw CrmRuleException::refuse('file', sprintf('The file content does not match its .%s extension.', $extension));
        }

        return [$extension, $mime, $size, $originalName];
    }

    private function isPortalUser(User $user): bool
    {
        try {
            return $user->canAccessPanel(PanelType::Client) && ! $user->canAccessPanel(PanelType::Admin);
        } catch (Throwable) {
            return false;
        }
    }

    private function downloadName(string $originalName, string $extension): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $base = (string) preg_replace('/[^A-Za-z0-9 ._-]+/', '-', $base);
        $base = trim($base, ' .-');

        return ($base === '' ? 'document' : mb_substr($base, 0, 120)).'.'.($extension === '' ? 'bin' : $extension);
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);

        return mb_substr(trim($name) === '' ? 'document' : trim($name), 0, 255);
    }

    private function lock(ClientDocument $document): ClientDocument
    {
        /** @var ClientDocument $locked */
        $locked = ClientDocument::query()->withTrashed()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }
}
