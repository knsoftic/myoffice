<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use SplFileInfo;
use Throwable;

/**
 * `ops:prune-logs` — delete daily log files past their retention (phase-24-25 §10.4).
 *
 * **Laravel's `daily` channel already has a `days` option, and it is not enough on its own.** It
 * prunes only when a log is written, so a quiet week leaves last month's files in place; and the
 * value it uses is baked into the channel at boot, while the number an operator can actually change
 * is `ops.log_retention_days`. `ConfigureFromSettings` mirrors the setting into the channel, and this
 * command is what makes the number true for files the channel will never touch again.
 *
 * **It never deletes today's file, whatever the arithmetic says.** A retention of 1 day with a
 * boundary bug would otherwise delete the log of the request that is running — and the first thing
 * anybody does after an incident is read today's log.
 *
 * **It only ever deletes files it can recognise.** The pattern is Laravel's own
 * `laravel-YYYY-MM-DD.log`; anything else in `storage/logs` is left alone, because an operator who
 * put a file there had a reason and a pruner that sweeps a directory is a pruner that eventually
 * deletes something that mattered.
 *
 * Unlike `ops:prune-integrity-runs`, this command really does delete — a log file is not evidence in
 * the sense §2.3 means. The audit trail lives in `activity_log` and the integrity runs live in their
 * own table; both are append-only and neither is touched here.
 */
final class OpsPruneLogs extends Command
{
    protected $signature = 'ops:prune-logs
                            {--dry-run : List what would go and delete nothing}
                            {--json : Machine-readable output}';

    protected $description = 'Delete daily log files older than ops.log_retention_days.';

    /**
     * Laravel's daily channel filename. Anything that does not match is not ours to delete.
     */
    private const PATTERN = '/^laravel-(\d{4})-(\d{2})-(\d{2})\.log$/';

    public function handle(): int
    {
        $days = $this->retentionDays();
        $today = Carbon::today()->toDateString();
        $cutoff = Carbon::today()->subDays($days);

        $directory = storage_path('logs');

        if (! File::isDirectory($directory)) {
            $this->info('ops:prune-logs — no log directory yet, nothing to do.');

            return 0;
        }

        $pruned = [];
        $kept = 0;
        $bytes = 0;
        $failed = [];

        foreach (File::files($directory) as $file) {
            $date = $this->dateOf($file);

            if ($date === null) {
                // Not a daily log. Somebody put it here on purpose.
                $kept++;

                continue;
            }

            // Today's log is never a candidate. See the class note.
            if ($date === $today || Carbon::parse($date)->greaterThanOrEqualTo($cutoff)) {
                $kept++;

                continue;
            }

            $size = $file->getSize();

            if ($this->option('dry-run')) {
                $pruned[] = ['file' => $file->getFilename(), 'date' => $date, 'bytes' => $size];

                continue;
            }

            try {
                File::delete($file->getPathname());

                $pruned[] = ['file' => $file->getFilename(), 'date' => $date, 'bytes' => $size];
                $bytes += $size;
            } catch (Throwable $exception) {
                // A locked file on Windows is the common case, and it is not a failure worth a
                // non-zero exit: the next run gets it.
                $failed[] = $file->getFilename().' — '.$exception->getMessage();
            }
        }

        return $this->report($days, $pruned, $kept, $bytes, $failed);
    }

    /**
     * The retention, clamped.
     *
     * The clamp is the guard rather than the form rule: a value written by raw SQL must not be able
     * to ask for a retention of zero days, which would mean "delete everything but today".
     */
    private function retentionDays(): int
    {
        try {
            $value = setting('ops.log_retention_days', 14);
        } catch (Throwable) {
            return 14;
        }

        return is_numeric($value) ? max(1, min(365, (int) $value)) : 14;
    }

    /**
     * The date a daily log file is for, or null when the name is not one of ours.
     */
    private function dateOf(SplFileInfo $file): ?string
    {
        if (preg_match(self::PATTERN, $file->getFilename(), $matches) !== 1) {
            return null;
        }

        return sprintf('%s-%s-%s', $matches[1], $matches[2], $matches[3]);
    }

    /**
     * @param  list<array{file: string, date: string, bytes: int}>  $pruned
     * @param  list<string>  $failed
     */
    private function report(int $days, array $pruned, int $kept, int $bytes, array $failed): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'retention_days' => $days,
                'dry_run' => (bool) $this->option('dry-run'),
                'pruned' => $pruned,
                'kept' => $kept,
                'bytes_reclaimed' => $bytes,
                'failed' => $failed,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed === [] ? 0 : 1;
        }

        if ($pruned !== []) {
            $this->table(
                ['file', 'for', 'size'],
                array_map(static fn (array $row): array => [
                    $row['file'],
                    $row['date'],
                    sprintf('%.1f KB', $row['bytes'] / 1024),
                ], $pruned),
            );
        }

        foreach ($failed as $failure) {
            $this->warn('could not delete '.$failure);
        }

        $this->newLine();
        $this->info(sprintf(
            'ops:prune-logs — %s %d file(s) older than %d day(s); %d kept%s.',
            $this->option('dry-run') ? 'would delete' : 'deleted',
            count($pruned),
            $days,
            $kept,
            $bytes === 0 ? '' : sprintf(', %.1f MB reclaimed', $bytes / 1048576),
        ));

        // A locked file is not a failure worth failing the schedule over — the next run gets it.
        return 0;
    }
}
