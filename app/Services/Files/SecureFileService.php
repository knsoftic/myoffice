<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\DataObjects\Files\FileRules;
use App\DataObjects\Files\FileTarget;
use App\DataObjects\Files\StoredFile;
use App\DataObjects\Files\StreamOptions;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Files\Exceptions\FileRuleException;
use App\Support\SettingsRegistry;
use finfo;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The only write path for a private file in phases 19–23 (§6.1, §6.4, D21, INV-19-2).
 *
 * Phase 5 wrote these rules once for client documents and proved them; this is that logic generalised so
 * the system ends up with **two** upload code paths rather than six. What differs per field is a
 * `FileRules`; where it lands is a `FileTarget`; the gate below never differs.
 *
 * **The gate, in order — every step fails closed:**
 *
 *   1. the upload arrived intact (`isValid()`)
 *   2. the client's name is reduced to a basename with control characters stripped
 *   3. an extension exists and is not hard-refused — §6.1 step 5's list, which `ALWAYS_REFUSED` and
 *      `SettingsRegistry::NEVER_UPLOADABLE_EXTENSIONS` cover between them
 *   4. no inner segment is an extension: `cv.pdf.php` and `scan.pdf.docx` are both refused
 *   5. the extension is in this field's list, **intersected** with `security.allowed_file_types` —
 *      a rule narrows, never widens
 *   6. the file is not empty
 *   7. the size is within the smallest of the field's cap, the setting's and PHP's
 *   8. the MIME is **sniffed from the bytes** with `finfo`, never read from the request
 *   9. the sniffed MIME is one the extension permits — a PDF renamed `.docx` is refused, naming both
 *  10. it is written as `{ulid}.{extension}` under the target's directory, never under the client's
 *      name, with a sha256 streamed rather than loaded, and one `activity_log` row
 *
 * A `.zip` is accepted as bytes and **never** inspected or expanded (§6.1 step 6) — there is no archive
 * reader here to be a vulnerability.
 *
 * **The private disk is `private`, rooted at `storage/app/private`** — §6.3, literally. It is the same
 * directory Laravel 12 roots `local` at, declared under its own name so that `serve` can be off: nothing
 * but a controller that re-runs §6.4's chain is able to hand one of these files out.
 *
 * **Reading is a controller's job.** `stream()` takes an already-authorised file, because §6.2 [D-19-4]
 * puts the authorisation decision at the moment the bytes are served, not the moment a link was minted.
 * It never issues a signed URL and never calls `Storage::url()` or `temporaryUrl()`.
 */
final class SecureFileService
{
    use WritesAuditTrail;

    /** §6.3's private disk — root `storage/app/private`, never served by a route. */
    public const DISK = FileRules::DISK_PRIVATE;

    private const MODULE = 'files';

    public function __construct(
        private readonly FilesystemFactory $storage,
    ) {}

    /**
     * Steps 1–10. Returns what the **server** decided; the caller writes those columns on its own row.
     *
     * When `$target->owner` is null — the row does not exist yet because its path column is NOT NULL —
     * the step-10 entry cannot name a subject, so the caller writes it with {@see logStored()} once the
     * row is saved. Every caller does one or the other; a stored file with no entry is a defect.
     *
     * @param  string  $field  the request field a refusal message lands on
     */
    public function store(UploadedFile $file, FileTarget $target, FileRules $rules, string $field = 'file'): StoredFile
    {
        $inspected = $this->inspect($file, $rules, $field);

        $name = Str::lower((string) Str::ulid()).'.'.$inspected['extension'];
        $path = $this->storage->disk($rules->disk)->putFileAs($target->directory, $file, $name);

        if (! is_string($path) || $path === '') {
            throw FileRuleException::refuse($field, 'The file could not be saved. Try again.');
        }

        $stored = new StoredFile(
            disk: $rules->disk,
            path: $path,
            originalName: $inspected['originalName'],
            extension: $inspected['extension'],
            mimeType: $inspected['mime'],
            sizeBytes: $inspected['size'],
            checksumSha256: $inspected['checksum'],
        );

        if ($target->owner !== null) {
            $this->logStored($stored, $target);
        }

        return $stored;
    }

    /**
     * §6.1 step 10's entry, for a caller that had to create its row after the bytes landed.
     * Names the path, the size, the MIME and the target, as the contract requires.
     */
    public function logStored(StoredFile $stored, FileTarget $target): void
    {
        if ($target->owner === null) {
            return;
        }

        $this->audit($target->owner, 'File stored', [
            'attributes' => [
                'disk' => $stored->disk,
                'path' => $stored->path,
                'original_name' => $stored->originalName,
                'mime_type' => $stored->mimeType,
                'size_bytes' => $stored->sizeBytes,
                'checksum_sha256' => $stored->checksumSha256,
                'target' => $target->ownerDescription(),
            ],
        ], $target->module ?? self::MODULE);
    }

    /**
     * Steps 1–9 without writing anything — so a Form Request can refuse a batch before a single byte
     * lands, and ten submission files do not leave nine orphans when the tenth turns out to be a `.php`.
     */
    public function validate(UploadedFile $file, FileRules $rules, string $field = 'file'): void
    {
        $this->inspect($file, $rules, $field);
    }

    /**
     * Refuse the whole batch before storing any of it, then store all of it. A failure part-way removes
     * what it already wrote: half a batch on disk with no row pointing at it is litter nobody finds.
     *
     * @param  list<UploadedFile>  $files
     * @return list<StoredFile>
     */
    public function storeMany(array $files, FileTarget $target, FileRules $rules, string $field = 'files'): array
    {
        if (count($files) > $rules->maxFiles) {
            throw FileRuleException::refuse($field, sprintf(
                'Attach at most %d %s.',
                $rules->maxFiles,
                $rules->maxFiles === 1 ? 'file' : 'files',
            ));
        }

        foreach ($files as $file) {
            $this->validate($file, $rules, $field);
        }

        $stored = [];

        try {
            foreach ($files as $file) {
                $stored[] = $this->store($file, $target, $rules, $field);
            }
        } catch (Throwable $e) {
            foreach ($stored as $orphan) {
                $this->delete($orphan);
            }

            throw $e;
        }

        return $stored;
    }

    /**
     * Store the replacement first, hand it back for the caller to swap into its column inside the
     * caller's own transaction, and delete the old bytes **only after that transaction commits**.
     *
     * The order is the whole point: if the swap rolls back, the old file is still there and the new one
     * is merely orphaned. Deleting first would mean a failed swap lost both.
     */
    public function replace(StoredFile $old, UploadedFile $new, FileTarget $target, FileRules $rules, string $field = 'file'): StoredFile
    {
        $stored = $this->store($new, $target, $rules, $field);

        // Runs at commit, or immediately when no transaction is open.
        DB::afterCommit(function () use ($old): void {
            $this->delete($old);
        });

        return $stored;
    }

    /**
     * Send the bytes. **The caller has already run §6.4's chain** — this keeps only the rules that are
     * about the response itself.
     *
     * `inline` is honoured for a PDF or an image and downgraded to `attachment` for everything else, and
     * again for any field whose rules forbid it. The `Content-Type` is the **stored** MIME: never
     * sniffed at read time, never guessed from the URL. A missing file is a 404, not a disk error.
     */
    public function stream(StoredFile $file, StreamOptions $options = new StreamOptions, ?FileRules $rules = null): StreamedResponse
    {
        $disk = $this->storage->disk($file->disk === '' ? self::DISK : $file->disk);

        if (! $file->exists() || ! $disk->exists($file->path)) {
            abort(Response::HTTP_NOT_FOUND, 'The file is no longer available.');
        }

        $inline = $options->inline
            && $file->isInlineSafe()
            && ($rules === null || $rules->allowInline);

        $name = $this->downloadName($options->downloadName ?? $file->originalName, $file->extension);
        $headers = $options->headers($file->mimeType, $this->sizeOnDisk($file));

        /** @var StreamedResponse $response */
        $response = $inline
            ? $disk->response($file->path, $name, $headers, 'inline')
            : $disk->download($file->path, $name, $headers);

        return $response;
    }

    /**
     * Delete the bytes and nothing else — the caller decides whether the row goes. Called from a
     * `forceDeleted` observer, from a prune command, and after a `replace()` commits.
     */
    public function delete(StoredFile $file): void
    {
        if (! $file->exists()) {
            return;
        }

        $disk = $this->storage->disk($file->disk === '' ? self::DISK : $file->disk);

        if ($disk->exists($file->path)) {
            $disk->delete($file->path);
        }
    }

    /** Used by the integrity command: does the row's file actually exist? */
    public function exists(StoredFile $file): bool
    {
        return $file->exists()
            && $this->storage->disk($file->disk === '' ? self::DISK : $file->disk)->exists($file->path);
    }

    /** The size on disk right now, for `Content-Length` and for a verifier checking a row against its bytes. */
    public function sizeOnDisk(StoredFile $file): ?int
    {
        return $this->exists($file)
            ? (int) $this->storage->disk($file->disk === '' ? self::DISK : $file->disk)->size($file->path)
            : null;
    }

    /**
     * Steps 1–9. Pure inspection: nothing is written, and every refusal names the file field.
     *
     * @return array{extension: string, mime: string, size: int, originalName: string, checksum: string|null}
     */
    private function inspect(UploadedFile $file, FileRules $rules, string $field): array
    {
        // 1 — the upload arrived intact.
        if (! $file->isValid()) {
            throw FileRuleException::refuse($field, 'The file could not be uploaded. Try again.');
        }

        // 2 — the client's name is reduced to something that cannot be a path.
        $originalName = $this->safeOriginalName((string) $file->getClientOriginalName());
        $segments = array_map('strtolower', explode('.', $originalName));
        $extension = count($segments) > 1 ? (string) end($segments) : '';

        // 3 — hard refusal, whatever the whitelist says.
        $refused = [...FileRules::ALWAYS_REFUSED, ...SettingsRegistry::NEVER_UPLOADABLE_EXTENSIONS];

        if ($extension === '' || in_array($extension, $refused, true) || preg_match('/^php\d*$/', $extension) === 1) {
            throw FileRuleException::refuse($field, 'This type of file cannot be uploaded.');
        }

        // 4 — no inner segment is an extension.
        $known = [...array_keys($rules->mimes), ...$refused];

        foreach (array_slice($segments, 1, -1) as $inner) {
            if (in_array($inner, $known, true) || preg_match('/^php\d*$/', $inner) === 1) {
                throw FileRuleException::refuse($field, 'A file name with two extensions cannot be uploaded. Rename the file and try again.');
            }
        }

        // 5 — the extension is one this field accepts, after the setting has had its say.
        $allowed = $rules->allowedExtensions();

        if (! in_array($extension, $allowed, true)) {
            throw FileRuleException::refuse($field, $allowed === []
                ? 'No file types are currently allowed. Ask an administrator to check the upload settings.'
                : sprintf('Upload one of: %s.', implode(', ', $allowed)));
        }

        // 6, 7 — not empty, not over the smallest applicable cap.
        $size = (int) $file->getSize();

        if ($size <= 0) {
            throw FileRuleException::refuse($field, 'The file is empty.');
        }

        $maxKilobytes = $rules->maxKilobytes();

        if ($size > $maxKilobytes * 1024) {
            throw FileRuleException::refuse($field, $maxKilobytes >= 1024
                ? sprintf('The file may be at most %s MB.', app_number($maxKilobytes / 1024, 0))
                : sprintf('The file may be at most %s KB.', app_number($maxKilobytes, 0)));
        }

        // 8 — the MIME comes from the bytes, never from the request.
        $realPath = (string) $file->getRealPath();
        $mime = $realPath === '' ? '' : strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->file($realPath));

        // 9 — and it must be one this extension permits. The message names both, per §6.1 step 4.
        if (! in_array($mime, $rules->mimesFor($extension), true)) {
            throw FileRuleException::refuse($field, sprintf(
                'The file content is %s, which does not match its .%s extension.',
                $mime === '' ? 'unreadable' : $mime,
                $extension,
            ));
        }

        return [
            'extension' => $extension,
            'mime' => $mime,
            'size' => $size,
            'originalName' => $originalName,
            // `hash_file` streams; the file is never loaded into memory (§6.1 step 9).
            'checksum' => $realPath === '' ? null : (hash_file('sha256', $realPath) ?: null),
        ];
    }

    /**
     * The name the browser is offered. Built from the original so it is recognisable, stripped so it
     * cannot carry a quote into the `Content-Disposition` header, and re-suffixed with the **server's**
     * extension so what the header promises is what the bytes are.
     */
    private function downloadName(string $originalName, string $extension): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $base = (string) preg_replace('/[^A-Za-z0-9 ._-]+/', '-', $base);
        $base = trim($base, ' .-');

        return ($base === '' ? 'file' : mb_substr($base, 0, 120)).'.'.($extension === '' ? 'bin' : $extension);
    }

    /** A basename, no control characters, never empty, never longer than the column. */
    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);

        return mb_substr(trim($name) === '' ? 'file' : trim($name), 0, 255);
    }
}
