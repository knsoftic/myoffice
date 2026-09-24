<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Enums\BackupType;
use App\Models\Ops\BackupRun;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Where an archive lives, on which disk, and under what name (phase-24-25 §6.2, §6.10.1).
 *
 * **It exists so that four classes cannot each invent a slightly different answer.** The service
 * writes the archive, the verification service re-opens it, the retention service deletes it and a
 * restore reads it — and the day those four disagree about a path is the day a prune deletes a file
 * a restore was about to need, or a verification reports "missing" for an archive that is sitting on
 * disk under a name nobody looked up. One resolver, one answer.
 *
 * **The disk is never `public`.** `backup.disk` is a select whose options already exclude it
 * (`SettingsRegistry::backupDiskOptions()`), and this class refuses it again anyway: a database dump
 * reachable at a guessable URL is not a backup, it is a breach with a schedule, and a settings row
 * written by a seeder or a fixture does not pass through the select. The default disk is `backups`
 * (private, `serve => false`, `throw => true`, rooted at `storage/app/backups`), and that directory
 * is on `backup.exclude_paths` so a file archive never contains the archives.
 *
 * **The working directory is always local, whatever the archive disk is.** `mysqldump` writes to a
 * filesystem path, not to a Flysystem adapter, and a zip is built with `ZipArchive` against a real
 * file. So the build happens under `storage/app/backups/work` and only the finished archive is
 * handed to the disk. That directory sits inside `storage/app/backups`, which is excluded from file
 * backups — a half-built dump must never be swept into the archive being built.
 */
final class BackupPathResolver
{
    /**
     * The disk used when `backup.disk` is unset, unknown, or names the public disk.
     */
    public const DEFAULT_DISK = 'backups';

    /**
     * Where an archive is assembled before it is handed to its disk. Relative to the local
     * `storage/app/backups` root, and named in `backup.exclude_paths` by way of its parent.
     */
    public const WORKING_DIRECTORY = 'work';

    /**
     * The dump's path **inside** the archive.
     *
     * Kept identical to the path the restore runbook types by hand (§6.10.5 step 7), so an operator
     * following the runbook and the service executing it are reading the same file.
     */
    public const DUMP_ENTRY_FORMAT = 'db-dumps/mysql-%s.sql';

    /**
     * Where the file tree lands inside the archive, and where `.env` lands when it is included.
     */
    public const FILES_ENTRY_PREFIX = 'files/';

    public const ENV_ENTRY = 'env/.env';

    public const MANIFEST_ENTRY = 'manifest.json';

    /**
     * The disk archives are written to.
     *
     * Falls back rather than throwing: a backup that refuses to run because a settings row is
     * malformed is a backup that does not exist, and the fallback is the private disk the contract
     * names. The refusal of `public` is the one case that is not a fallback but a rule.
     */
    public function diskName(): string
    {
        $configured = setting('backup.disk', self::DEFAULT_DISK);
        $name = is_string($configured) ? trim($configured) : '';

        if ($name === '' || $name === 'public') {
            return self::DEFAULT_DISK;
        }

        return $this->isConfigured($name) ? $name : self::DEFAULT_DISK;
    }

    /**
     * The offsite disk, or null when no second copy is configured.
     *
     * Null is a real answer and the go-live checklist reads it as one: a backup that lives on the
     * machine it backs up survives everything except the thing it was taken for.
     */
    public function offsiteDiskName(): ?string
    {
        $configured = setting('backup.offsite_disk');
        $name = is_string($configured) ? trim($configured) : '';

        if ($name === '' || $name === 'public' || ! $this->isConfigured($name)) {
            return null;
        }

        return $name;
    }

    public function disk(?string $name = null): Filesystem
    {
        return Storage::disk($name ?? $this->diskName());
    }

    /**
     * Whether a disk is a plain local directory, in which case an archive can be moved into place
     * instead of streamed through the adapter.
     */
    public function isLocal(?string $name = null): bool
    {
        return config('filesystems.disks.'.($name ?? $this->diskName()).'.driver') === 'local';
    }

    /**
     * The local filesystem root of a disk, or null when the disk is not local.
     */
    public function root(?string $name = null): ?string
    {
        $name ??= $this->diskName();

        if (! $this->isLocal($name)) {
            return null;
        }

        $root = config('filesystems.disks.'.$name.'.root');

        return is_string($root) && $root !== '' ? rtrim($root, '\\/') : null;
    }

    /**
     * `my_office-database-2026-09-12-023001.zip`.
     *
     * The database name leads, because an operator holding two archives from two installations has
     * nothing else to tell them apart, and the timestamp is second-resolution so two runs in the
     * same minute cannot collide onto one name — the `(disk, path)` unique index would reject the
     * second row, and the run that lost the race would be recorded as a failure it did not have.
     */
    public function filename(BackupType $type, ?CarbonInterface $at = null, ?string $database = null): string
    {
        $at = $at === null ? Carbon::now() : Carbon::instance($at);

        return sprintf(
            '%s-%s-%s.zip',
            $this->slug($database ?? $this->databaseName()),
            $type->value,
            $at->format('Y-m-d-His'),
        );
    }

    /**
     * `database/2026-09/my_office-database-2026-09-12-023001.zip`.
     *
     * One directory per type per month: a flat directory holding two years of daily archives is a
     * directory nobody can list on a shell over a slow link, and the type prefix means "delete the
     * file archives, keep the database ones" is a thing an operator can do without reading names.
     */
    public function relativePath(BackupType $type, string $filename, ?CarbonInterface $at = null): string
    {
        $at = $at === null ? Carbon::now() : Carbon::instance($at);

        return $type->value.'/'.$at->format('Y-m').'/'.$filename;
    }

    /**
     * The absolute path of a stored archive, or null when the disk is not local.
     *
     * Null is not an error: an S3 or SFTP archive has no path a process can open, which is exactly
     * why the deep verification streams it into the working directory first.
     */
    public function absolutePath(string $relativePath, ?string $name = null): ?string
    {
        $root = $this->root($name);

        return $root === null ? null : $root.'/'.ltrim($relativePath, '\\/');
    }

    /**
     * The archive of a run, as an absolute local path, or null when there is nothing to open.
     */
    public function locate(BackupRun $run): ?string
    {
        if (! $this->exists($run)) {
            return null;
        }

        return $this->absolutePath((string) $run->path, $run->disk);
    }

    /**
     * A local path a process can open, for an archive that may live anywhere.
     *
     * On a local disk that is the archive itself and nothing is copied. On any other disk — an
     * offsite S3 bucket, an SFTP share — the archive is streamed into the given workspace first,
     * because `hash_file`, `ZipArchive` and the `mysql` client all take a filename and none of them
     * takes a Flysystem path. Streamed rather than read into a string: an archive is measured in
     * hundreds of megabytes.
     *
     * Returns null when there is no archive to open at all.
     */
    public function materialize(BackupRun $run, string $workspace): ?string
    {
        if (! $this->exists($run)) {
            return null;
        }

        $local = $this->absolutePath((string) $run->path, $run->disk);

        if ($local !== null) {
            return $local;
        }

        $target = rtrim($workspace, '\\/').'/'.basename((string) $run->path);
        $source = $this->disk($run->disk)->readStream((string) $run->path);

        if (! is_resource($source)) {
            return null;
        }

        $destination = fopen($target, 'wb');

        if ($destination === false) {
            fclose($source);

            return null;
        }

        try {
            stream_copy_to_stream($source, $destination);
        } finally {
            fclose($source);
            fclose($destination);
        }

        return is_file($target) ? $target : null;
    }

    public function exists(BackupRun $run): bool
    {
        if ($run->path === null || $run->path === '') {
            return false;
        }

        try {
            return $this->disk($run->disk)->exists($run->path);
        } catch (Throwable) {
            // A disk that is unreachable is not a disk that says "no file": the caller is told the
            // archive is missing either way, and the distinction is recorded in the verification
            // notes rather than thrown from a path lookup.
            return false;
        }
    }

    /**
     * The size on disk now, which is not the same claim as the `size_bytes` written at dump time —
     * a truncated archive differs from its row, and that difference is the finding.
     */
    public function sizeOf(BackupRun $run): ?int
    {
        if (! $this->exists($run)) {
            return null;
        }

        try {
            return (int) $this->disk($run->disk)->size((string) $run->path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Put a finished archive on its disk and return the bytes that landed.
     *
     * A local disk gets a move, which is atomic within a volume and does not read the file a second
     * time; anything else gets a stream, so a 2 GB archive never becomes a 2 GB string. The returned
     * size is read back **from the destination**, not from the source: the number recorded on the
     * row has to be the number an operator will see in a directory listing.
     */
    public function store(string $localFile, string $relativePath, ?string $name = null): int
    {
        $name ??= $this->diskName();

        if (! is_file($localFile)) {
            throw new RuntimeException(sprintf('There is no archive at %s to store.', $localFile));
        }

        if ($this->isLocal($name)) {
            $destination = (string) $this->absolutePath($relativePath, $name);

            File::ensureDirectoryExists(dirname($destination), 0755);

            if (! @rename($localFile, $destination) && ! File::move($localFile, $destination)) {
                throw new RuntimeException(sprintf('The archive could not be moved to %s.', $destination));
            }

            // A no-op on Windows and the right thing everywhere else. An archive is readable by its
            // owner and by nobody else; the disk is private, and the file agrees.
            @chmod($destination, 0600);

            clearstatcache(true, $destination);

            return (int) filesize($destination);
        }

        $handle = fopen($localFile, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('The archive at %s could not be opened for upload.', $localFile));
        }

        try {
            $this->disk($name)->writeStream($relativePath, $handle);
        } finally {
            fclose($handle);
        }

        return (int) $this->disk($name)->size($relativePath);
    }

    /**
     * Delete the **file** of a run. The row is never touched here, and never deleted anywhere
     * (§6.10.3 rule 4, D19): retention removes bytes, not history.
     */
    public function deleteFile(BackupRun $run): bool
    {
        if ($run->path === null || $run->path === '') {
            return false;
        }

        try {
            return $this->disk($run->disk)->delete($run->path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A private scratch directory for one run, created 0700.
     *
     * Returned as an absolute path. Everything a run writes before the archive is sealed — the dump,
     * the `--defaults-extra-file`, the half-built zip — lives here and is discarded in a `finally`.
     */
    public function workspace(?string $token = null): string
    {
        $token = $this->slug($token ?? (string) Str::ulid());
        $path = $this->workingRoot().'/'.$token;

        File::ensureDirectoryExists($path, 0700);

        return $path;
    }

    /**
     * Throw away a workspace.
     *
     * **The containment check is the point of this method.** A token that arrived from a caller,
     * a path built from a setting, a `..` that survived a concatenation — any of them would turn a
     * recursive delete into a recursive delete of `storage/app`. So the path is resolved and
     * refused unless it is genuinely underneath the working root, and a refusal is silent because a
     * leftover scratch directory is a nuisance while a deleted `storage/app` is an incident.
     */
    public function discardWorkspace(string $path): void
    {
        $root = realpath($this->workingRoot());
        $target = realpath($path);

        if ($root === false || $target === false) {
            return;
        }

        $root = rtrim(str_replace('\\', '/', $root), '/');
        $target = rtrim(str_replace('\\', '/', $target), '/');

        if ($target === $root || ! str_starts_with($target.'/', $root.'/')) {
            return;
        }

        try {
            File::deleteDirectory($target);
        } catch (Throwable) {
            // Windows holds a handle open a moment longer than the process that wrote the file. A
            // scratch directory that survives one run is swept by the next; a backup that failed
            // because it could not tidy up would be a backup lost to housekeeping.
        }
    }

    /**
     * Bytes currently occupied by archives on a disk, as an integer string.
     *
     * A string because the caller compares it against `backup.max_storage_gb`, which arrives as a
     * decimal setting, and `20.00 × 1024³` is exactly the arithmetic a float rounds. The working
     * directory is excluded: a dump in flight is not stored history and must not be able to push
     * the ceiling check over the edge while it is being written.
     */
    public function usedBytes(?string $name = null): string
    {
        $name ??= $this->diskName();

        $total = '0';

        try {
            $disk = $this->disk($name);

            foreach ($disk->allFiles() as $file) {
                if (str_starts_with($file, self::WORKING_DIRECTORY.'/')) {
                    continue;
                }

                $total = bcadd($total, (string) $disk->size($file), 0);
            }
        } catch (Throwable) {
            return $total;
        }

        return $total;
    }

    /**
     * The live database name, used in filenames and as the dump's entry name.
     */
    public function databaseName(?string $connection = null): string
    {
        $connection ??= (string) config('database.default');

        return (string) config('database.connections.'.$connection.'.database', 'database');
    }

    /**
     * The dump's entry name inside an archive, for a given database.
     */
    public function dumpEntry(string $database): string
    {
        return sprintf(self::DUMP_ENTRY_FORMAT, $this->slug($database));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Where workspaces live: always local, always inside `storage/app/backups`, whatever
     * `backup.disk` says. See the class note.
     */
    private function workingRoot(): string
    {
        return storage_path('app/'.self::DEFAULT_DISK.'/'.self::WORKING_DIRECTORY);
    }

    private function isConfigured(string $name): bool
    {
        return is_array(config('filesystems.disks.'.$name));
    }

    /**
     * Reduce a value to what is safe in a filename on every filesystem this ships to.
     */
    private function slug(string $value): string
    {
        $slug = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $value);

        return trim($slug, '._-') === '' ? 'backup' : trim($slug, '._-');
    }
}
