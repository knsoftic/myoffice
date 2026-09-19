<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Cms\JobApplication;
use App\Models\Cms\JobOpening;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Support\SettingsRegistry;
use App\Support\SettingsRepository;
use finfo;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Candidate CVs — a private artefact (phase-04 §6.8, decision D21).
 *
 *   | check                | rule                                                                                  |
 *   |----------------------|---------------------------------------------------------------------------------------|
 *   | extension allowlist  | `pdf`, `doc`, `docx` intersected with `website.cv_allowed_types`                      |
 *   | real MIME            | the MIME **detected from the file's content**, which must be in the same map and agree |
 *   |                      | with the extension — a `cv.php` renamed `cv.pdf` is refused even if validation was     |
 *   |                      | bypassed                                                                              |
 *   | size                 | `min(website.cv_max_mb, security.max_upload_mb)` MB, and never above PHP's own limit   |
 *   | name                 | stored as `{ulid}.{extension of the detected MIME}`; the original name is sanitised    |
 *   |                      | and used only as the download filename                                                |
 *   | location             | disk `local` (private), `careers/applications/{Y}/{job_opening_id}/` — never `public`  |
 *   | serving              | `download()` only: `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`,|
 *   |                      | the stored MIME, never inline, never a redirect; every download is an audit entry      |
 *
 * Nothing here decides who may download: the route's `can:job_applications.download` and the policy run
 * first, on every request. The CV is never attached to an email.
 */
final class ApplicationCvService
{
    public const DISK = JobApplication::CV_DISK;

    /** @var array<string, string> detected MIME => stored extension */
    public const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    public const DEFAULT_MAX_MB = 5;

    public const MAX_MB_CEILING = 10;

    public function __construct(
        private readonly FilesystemFactory $storage,
        private readonly SettingsRepository $settings,
        private readonly CmsAuditor $auditor,
    ) {}

    /**
     * Validate and store an uploaded CV.
     *
     * @return array{path: string, original_name: string, mime: string, size: int}
     */
    public function store(UploadedFile $file, JobOpening $job): array
    {
        if (! $file->isValid()) {
            throw ContentRuleException::uploadRefused('cv', 'The CV could not be uploaded. Try again.');
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $allowed = $this->allowedExtensions();

        if (! in_array($extension, $allowed, true)) {
            throw ContentRuleException::uploadRefused('cv', sprintf('Upload your CV as %s.', $this->describe($allowed)));
        }

        $mime = $this->detectMime($file);
        $detectedExtension = self::MIME_EXTENSIONS[$mime] ?? null;

        // The second gate: the content must be what the name claims (§6.8).
        if ($detectedExtension === null || $detectedExtension !== $extension) {
            throw ContentRuleException::uploadRefused('cv', 'The file is not a real PDF or Word document.');
        }

        $size = (int) $file->getSize();

        if ($size <= 0) {
            throw ContentRuleException::uploadRefused('cv', 'The CV file is empty.');
        }

        if ($size > $this->maxKilobytes() * 1024) {
            throw ContentRuleException::uploadRefused('cv', sprintf('The CV may be at most %s.', $this->maxLabel()));
        }

        $directory = sprintf('careers/applications/%s/%d', Carbon::now()->format('Y'), (int) $job->getKey());
        $name = Str::lower((string) Str::ulid()).'.'.$detectedExtension;

        $path = $this->storage->disk(self::DISK)->putFileAs($directory, $file, $name);

        if (! is_string($path) || $path === '') {
            throw ContentRuleException::uploadRefused('cv', 'The CV could not be saved. Try again.');
        }

        return [
            'path' => $path,
            'original_name' => $this->originalName($file, $detectedExtension),
            'mime' => $mime,
            'size' => $size,
        ];
    }

    /**
     * Stream the CV as an attachment and record the sensitive access (§10.5, §107).
     */
    public function download(JobApplication $a): StreamedResponse
    {
        $path = trim((string) $a->getAttribute('cv_path'));
        $disk = $this->storage->disk(self::DISK);

        if ($path === '' || ! $disk->exists($path)) {
            abort(Response::HTTP_NOT_FOUND, 'The CV file is not available.');
        }

        $mime = (string) $a->getAttribute('cv_mime');
        $mime = array_key_exists($mime, self::MIME_EXTENSIONS) ? $mime : 'application/octet-stream';
        $name = $this->safeDownloadName((string) $a->getAttribute('cv_original_name'), self::MIME_EXTENSIONS[$mime] ?? 'bin');

        $this->auditor->record(
            module: 'job_applications',
            description: sprintf('CV of application #%d downloaded', (int) $a->getKey()),
            subject: $a,
            properties: [
                'job_application_id' => (int) $a->getKey(),
                'job_opening_id' => (int) $a->getAttribute('job_opening_id'),
                'file_name' => $name,
                'size' => (int) $a->getAttribute('cv_size'),
                'sensitive' => true,
            ],
            event: 'cv_downloaded',
        );

        /** @var StreamedResponse $response */
        $response = $disk->download($path, $name, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);

        return $response;
    }

    /**
     * Remove the application's CV from the private disk (idempotent). The row is not touched.
     */
    public function delete(JobApplication $a): void
    {
        $this->deletePath((string) $a->getAttribute('cv_path'));
    }

    /**
     * Remove a stored CV by path — for a rolled-back application or a purge after commit.
     */
    public function deletePath(?string $path): void
    {
        $path = trim((string) $path);

        if ($path === '' || str_contains($path, '..')) {
            return;
        }

        try {
            $this->storage->disk(self::DISK)->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * `pdf` / `doc` / `docx`, narrowed by `website.cv_allowed_types` (an empty or unreadable setting
     * narrows nothing).
     *
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        $all = array_values(self::MIME_EXTENSIONS);
        $setting = $this->settings->get('website.cv_allowed_types', $all);

        if (is_string($setting)) {
            $decoded = json_decode($setting, true);
            $setting = is_array($decoded) ? $decoded : explode(',', $setting);
        }

        if (! is_array($setting)) {
            return $all;
        }

        $chosen = array_values(array_intersect($all, array_map(static fn ($value): string => strtolower(trim((string) $value)), $setting)));

        return $chosen === [] ? $all : $chosen;
    }

    /**
     * The effective CV limit in KB: `min(website.cv_max_mb, security.max_upload_mb)`, capped by PHP.
     */
    public function maxKilobytes(): int
    {
        $own = $this->settings->get('website.cv_max_mb', self::DEFAULT_MAX_MB);
        $own = is_numeric($own) ? max(1, min(self::MAX_MB_CEILING, (int) $own)) : self::DEFAULT_MAX_MB;

        return SettingsRegistry::uploadKilobytes($own * 1024, $this->settings->get('security.max_upload_mb'));
    }

    /**
     * "5 MB" — for the form hint ("PDF or Word, max N MB").
     */
    public function maxLabel(): string
    {
        $kilobytes = $this->maxKilobytes();

        return $kilobytes % 1024 === 0 ? intdiv($kilobytes, 1024).' MB' : $kilobytes.' KB';
    }

    /**
     * @param  list<string>  $extensions
     */
    private function describe(array $extensions): string
    {
        $labels = array_map(static fn (string $extension): string => strtoupper($extension), $extensions);

        return count($labels) > 1 ? implode(', ', array_slice($labels, 0, -1)).' or '.end($labels) : ($labels[0] ?? 'PDF');
    }

    /**
     * The MIME read with `finfo` from the uploaded bytes themselves — the same rule as Phase 3's
     * `MediaService` (INV-11). `UploadedFile::getMimeType()` is only the fallback: an upload object may
     * report a type that does not come from the content, and this gate exists precisely for the case where
     * the transport rules were bypassed.
     */
    private function detectMime(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if (is_string($path) && $path !== '' && is_file($path)) {
            try {
                $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);

                if (is_string($detected) && $detected !== '') {
                    return strtolower($detected);
                }
            } catch (Throwable) {
                // fall through to the upload's own report
            }
        }

        return strtolower((string) $file->getMimeType());
    }

    private function originalName(UploadedFile $file, string $extension): string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));

        return $this->safeDownloadName($name, $extension);
    }

    private function safeDownloadName(string $name, string $extension): string
    {
        $name = (string) preg_replace('~[\x00-\x1F\x7F"\\\\/]~u', '', basename(str_replace('\\', '/', $name)));
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'cv.'.$extension;
        }

        return mb_substr($name, 0, 255);
    }
}
