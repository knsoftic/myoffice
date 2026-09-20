<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\AttachmentVisibility;
use App\Events\Project\AttachmentDeleted;
use App\Events\Project\AttachmentDownloaded;
use App\Events\Project\AttachmentUploaded;
use App\Models\Project\Attachment;
use App\Models\Project\Task;
use App\Models\User;
use App\Services\Project\Exceptions\ProjectRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Uploading, serving and removing attachments (phase-06 §6.1, §2.9, requirement §96).
 *
 * **Nothing lands on the public disk** (D21). Files go to the private disk under a ULID name, and the
 * only way back out is {@see download()} behind the permission-checked controller — so a leaked path is
 * not a leaked file, and a guessed URL is a 404 rather than a document.
 *
 * **Two checks, not one** (§6.1). The extension must be in `security.allowed_file_types` **and** the
 * sniffed MIME must agree with it. A `.php` renamed `.png` passes the first and fails the second, which
 * is the whole point: the browser's word for what a file is has never been evidence.
 *
 * The blob outlives the row by 30 days so an accidental delete is recoverable; `attachments:prune-deleted`
 * removes it afterwards.
 */
final readonly class AttachmentService
{
    /** The private disk — never `public` (D21). */
    private const DISK = 'local';

    public function __construct(private TaskCacheService $caches) {}

    public function store(
        Model $attachable,
        UploadedFile $file,
        AttachmentVisibility $visibility,
        User $actor,
    ): Attachment {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();

        $this->assertAllowed($extension, $mime);
        $this->assertSize($file);

        return DB::transaction(function () use ($attachable, $file, $visibility, $actor, $extension, $mime): Attachment {
            $alias = array_search($attachable::class, Attachment::MORPH_ALIASES, true);

            if ($alias === false) {
                throw ProjectRuleException::refuse('file', 'Files cannot be attached to that kind of record.');
            }

            $directory = sprintf('attachments/%s/%d', $alias, $attachable->getKey());
            $name = (string) Str::ulid().'.'.$extension;

            // A hashed name: the human filename is shown, never used as a path.
            $path = $file->storeAs($directory, $name, ['disk' => self::DISK]);

            $attachment = new Attachment;
            $attachment->forceFill([
                'attachable_type' => $alias,
                'attachable_id' => $attachable->getKey(),
                'disk' => self::DISK,
                'path' => $path,
                'original_name' => Str::limit((string) $file->getClientOriginalName(), 250, ''),
                'mime_type' => $mime,
                'extension' => $extension,
                'size_bytes' => (int) $file->getSize(),
                'checksum_sha256' => hash_file('sha256', $file->getRealPath()) ?: null,
                'visibility' => $visibility->value,
                'uploaded_by' => $actor->getKey(),
                'uploaded_by_name' => $actor->name,
            ])->save();

            if ($attachable instanceof Task) {
                $this->caches->sync($attachable);
            }

            AttachmentUploaded::dispatch($attachment, $actor->getKey());

            return $attachment->refresh();
        });
    }

    /**
     * Stream the file back under its human name, and record who read it (§60).
     */
    public function download(Attachment $attachment, User $actor, bool $byClient = false): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            throw ProjectRuleException::refuse('file', 'That file is no longer on disk.');
        }

        AttachmentDownloaded::dispatch($attachment, $actor->getKey(), $byClient);

        return $disk->download($attachment->path, $attachment->original_name);
    }

    /**
     * Soft delete only. The blob is pruned after 30 days, so a mis-click is recoverable.
     */
    public function delete(Attachment $attachment, User $actor): void
    {
        DB::transaction(function () use ($attachment, $actor): void {
            $subject = $attachment->attachable;

            $attachment->delete();

            if ($subject instanceof Task) {
                $this->caches->sync($subject);
            }

            AttachmentDeleted::dispatch($attachment, $actor->getKey());
        });
    }

    private function assertAllowed(string $extension, string $mime): void
    {
        $allowed = (array) setting('security.allowed_file_types', ['pdf', 'png', 'jpg', 'jpeg', 'docx', 'xlsx', 'zip']);
        $allowed = array_map(static fn ($value): string => strtolower(trim((string) $value)), $allowed);

        if ($extension === '' || ! in_array($extension, $allowed, true)) {
            throw ProjectRuleException::refuse('file', sprintf('%s files are not allowed here.', strtoupper($extension ?: 'unnamed')));
        }

        // The sniffed type has to agree with the extension: a .php renamed .png passes the list above.
        if (! $this->mimeMatches($extension, $mime)) {
            throw ProjectRuleException::refuse(
                'file',
                'That file is not what its name says it is, so it was refused.'
            );
        }
    }

    private function assertSize(UploadedFile $file): void
    {
        $maxMb = (int) setting('security.max_upload_mb', 10);

        if ($file->getSize() > $maxMb * 1024 * 1024) {
            throw ProjectRuleException::refuse('file', sprintf('Files have to be %d MB or smaller.', $maxMb));
        }
    }

    /**
     * Does the sniffed MIME belong to the claimed extension?
     *
     * Deliberately an allow-list rather than a "not dangerous" list: a type nobody thought about is
     * refused, which is the safe direction to be wrong in.
     */
    private function mimeMatches(string $extension, string $mime): bool
    {
        $map = [
            'pdf' => ['application/pdf'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'txt' => ['text/plain'],
            'csv' => ['text/plain', 'text/csv', 'application/csv'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'ppt' => ['application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
            'zip' => ['application/zip', 'application/x-zip-compressed'],
        ];

        return in_array($mime, $map[$extension] ?? [], true);
    }
}
