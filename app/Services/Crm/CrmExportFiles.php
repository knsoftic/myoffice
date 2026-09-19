<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Where a queued CRM export is written and how its requester gets it back (phase-05 §6.10, §10.4, D21).
 *
 * Files live on the private `local` disk under `crm/exports/{type}/{user}/`. The download link carries an encrypted
 * reference — not a signed URL and not a guessable path: the route re-runs `auth`, `module:` and `can:*.export`, and
 * `download()` additionally refuses anyone but the user who asked for the export, and any link older than
 * `TTL_DAYS`.
 */
final class CrmExportFiles
{
    public const DISK = 'local';

    public const DIRECTORY = 'crm/exports';

    public const TTL_DAYS = 7;

    public function __construct(
        private readonly FilesystemFactory $storage,
    ) {}

    public function newPath(string $type, int $userId): string
    {
        return sprintf('%s/%s/%d/%s-%s.csv', self::DIRECTORY, $type, $userId, $type, Str::lower((string) Str::ulid()));
    }

    public function absolutePath(string $path): string
    {
        return $this->storage->disk(self::DISK)->path($path);
    }

    public function token(string $type, string $path, int $userId): string
    {
        return Crypt::encryptString((string) json_encode([
            't' => $type,
            'p' => $path,
            'u' => $userId,
            'e' => CarbonImmutable::now()->addDays(self::TTL_DAYS)->getTimestamp(),
        ]));
    }

    public function download(string $type, string $token, User $user): StreamedResponse
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $path = is_array($payload) ? (string) ($payload['p'] ?? '') : '';
        $owner = is_array($payload) ? (int) ($payload['u'] ?? 0) : 0;
        $expires = is_array($payload) ? (int) ($payload['e'] ?? 0) : 0;
        $prefix = sprintf('%s/%s/%d/', self::DIRECTORY, $type, (int) $user->getKey());

        if (($payload['t'] ?? null) !== $type
            || $owner !== (int) $user->getKey()
            || $expires < CarbonImmutable::now()->getTimestamp()
            || ! str_starts_with($path, $prefix)
            || str_contains($path, '..')) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $disk = $this->storage->disk(self::DISK);

        if (! $disk->exists($path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        /** @var StreamedResponse $response */
        $response = $disk->download($path, basename($path), [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);

        return $response;
    }
}
