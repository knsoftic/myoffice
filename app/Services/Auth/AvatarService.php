<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Profile photo storage (phase-01 §7).
 *
 * Files live on the `public` disk under `avatars/`, with a random file name — never the name the
 * browser sent. The MIME type is validated by content in UpdateAvatarRequest (the `image` and
 * `mimetypes` rules both read the file, not the extension), so by the time a file reaches this
 * service it is known to be a real image.
 *
 * Replacing or removing a photo always deletes the previous file: no orphans on disk.
 */
final class AvatarService
{
    /**
     * Directory on the `public` disk that holds every avatar.
     */
    public const DIRECTORY = 'avatars';

    /**
     * Store the upload, point the user row at it, delete whatever it replaced.
     *
     * @return string the stored path, relative to the `public` disk
     */
    public function store(User $user, UploadedFile $file): string
    {
        $previous = $this->currentPath($user);

        $path = $file->store(self::DIRECTORY, 'public');

        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('The avatar could not be written to the public disk.');
        }

        $user->forceFill(['avatar_path' => $path])->save();

        $this->deleteFile($previous);

        return $path;
    }

    /**
     * Remove the current photo and fall back to the generated initials avatar.
     */
    public function delete(User $user): void
    {
        $previous = $this->currentPath($user);

        if ($previous === null) {
            return;
        }

        $user->forceFill(['avatar_path' => null])->save();

        $this->deleteFile($previous);
    }

    public function has(User $user): bool
    {
        return $this->currentPath($user) !== null;
    }

    /**
     * The stored path, but only when it is something this service owns — an absolute URL or an
     * externally managed path is left alone.
     */
    private function currentPath(User $user): ?string
    {
        $path = trim((string) $user->avatar_path);

        if ($path === '' || str_starts_with($path, 'http') || str_starts_with($path, '/')) {
            return null;
        }

        return $path;
    }

    private function deleteFile(?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            $disk = Storage::disk('public');

            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        } catch (Throwable $exception) {
            // A missing or unwritable file must not fail the request — the row is already
            // pointing somewhere else.
            report($exception);
        }
    }
}
