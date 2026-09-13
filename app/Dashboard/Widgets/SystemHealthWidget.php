<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets;

use App\Dashboard\Concerns\FormatsBytes;
use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Facts about the running installation (phase-02 §3).
 *
 * **This key belongs to Phase 2 for the life of the system** (F-8.3). phase-24-25 does not
 * re-register `system_health`: it refactors the body of this class to read its
 * `SystemHealthService` and ships its own four new keys (`backup_status`, `integrity_status`,
 * `failed_jobs`, `queue_health`). Each reading below is therefore a small private method with one
 * responsibility, so that refactor is a delegation and not a rewrite.
 *
 * Everything here is measured, never configured: the version from the runtime, the database name
 * and size from the connection, the last migration from the `migrations` table, the storage link
 * from the filesystem. A reading that cannot be taken reports `null` and the view shows a dash —
 * a health card that prints a plausible zero for a failed probe is worse than one that admits it.
 *
 * Deferred: the `information_schema` aggregate is the one genuinely slow probe on a large
 * database, so the card is fetched after the page paints, behind an `x-ui.skeleton`. Three
 * queries, and they are off the critical path.
 */
final class SystemHealthWidget extends Widget
{
    use FormatsBytes;

    public function key(): string
    {
        return 'system_health';
    }

    public function title(): string
    {
        return 'System health';
    }

    public function icon(): string
    {
        return 'server-stack';
    }

    public function permission(): ?string
    {
        return 'settings.view_any';
    }

    public function module(): ?string
    {
        return 'settings';
    }

    public function span(): int
    {
        return 6;
    }

    public function group(): string
    {
        return WidgetGroup::SYSTEM;
    }

    public function sort(): int
    {
        return 10;
    }

    public function deferred(): bool
    {
        return true;
    }

    public function skeleton(): string
    {
        return 'text';
    }

    public function minHeight(): ?int
    {
        return 320;
    }

    public function subtitle(): ?string
    {
        return 'This installation, right now';
    }

    /**
     * Not range-scoped — "right now" is the whole point of a health card.
     *
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $database = $this->database();
        $migration = $this->lastMigration();
        $storage = $this->storageLink();
        $failedJobs = $this->failedJobs();

        return [
            'available' => true,
            'environment' => (string) config('app.env', 'production'),
            'debug' => (bool) config('app.debug', false),
            'rows' => [
                [
                    'label' => 'PHP',
                    'value' => PHP_VERSION,
                    'meta' => PHP_OS_FAMILY.' · '.PHP_SAPI,
                    'icon' => 'server-stack',
                    'state' => 'ok',
                ],
                [
                    'label' => 'Laravel',
                    'value' => app()->version(),
                    'meta' => 'environment: '.(string) config('app.env', 'production'),
                    'icon' => 'sparkles',
                    'state' => 'ok',
                ],
                [
                    'label' => 'Database',
                    'value' => $database['name'] ?? '—',
                    'meta' => $database['size'] === null
                        ? $database['driver']
                        : $database['driver'].' · '.$this->formatBytes($database['size']).' in '.number_format((float) ($database['tables'] ?? 0)).' tables',
                    'icon' => 'table-cells',
                    'state' => $database['name'] === null ? 'error' : 'ok',
                ],
                [
                    'label' => 'Queue driver',
                    'value' => (string) config('queue.default', 'sync'),
                    'meta' => $failedJobs === null
                        ? 'failed jobs: unavailable'
                        : number_format((float) $failedJobs).' failed '.($failedJobs === 1 ? 'job' : 'jobs'),
                    'icon' => 'queue-list',
                    'state' => match (true) {
                        $failedJobs === null => 'warn',
                        $failedJobs > 0 => 'error',
                        default => 'ok',
                    },
                ],
                [
                    'label' => 'Cache driver',
                    'value' => (string) config('cache.default', 'file'),
                    'meta' => 'sessions: '.(string) config('session.driver', 'file'),
                    'icon' => 'rectangle-stack',
                    'state' => 'ok',
                ],
                [
                    'label' => 'Last migration',
                    'value' => $migration['stamp'] ?? '—',
                    'meta' => $migration['name'],
                    'icon' => 'clock',
                    'state' => $migration['name'] === null ? 'warn' : 'ok',
                ],
                [
                    'label' => 'Storage link',
                    'value' => $storage['linked'] ? 'Linked' : 'Missing',
                    'meta' => $storage['detail'],
                    'icon' => $storage['linked'] ? 'check-circle' : 'exclamation-triangle',
                    'state' => $storage['linked'] ? 'ok' : 'error',
                ],
                [
                    'label' => 'Timezone',
                    'value' => (string) config('app.timezone', 'UTC'),
                    'meta' => 'locale: '.(string) config('app.locale', 'en'),
                    'icon' => 'globe-alt',
                    'state' => 'ok',
                ],
            ],
            'failed_jobs' => $failedJobs,
            'checked_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Probes — one reading each, each safe to fail
    |--------------------------------------------------------------------------
    */

    /**
     * Connection name, driver, and the size MariaDB reports for this schema.
     *
     * The size query is MySQL/MariaDB-specific; on any other driver the size is simply not
     * reported rather than guessed. `information_schema.tables` under-reports a freshly written
     * InnoDB table until it is analysed, which is why the card labels this "reported size".
     *
     * @return array{name: string|null, driver: string, size: int|null, tables: int|null}
     */
    private function database(): array
    {
        try {
            $connection = DB::connection();
            $name = (string) $connection->getDatabaseName();
            $driver = (string) $connection->getDriverName();
        } catch (Throwable) {
            return ['name' => null, 'driver' => 'unknown', 'size' => null, 'tables' => null];
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return ['name' => $name, 'driver' => $driver, 'size' => null, 'tables' => null];
        }

        try {
            $row = DB::selectOne(
                'select sum(data_length + index_length) as bytes, count(*) as table_count '
                .'from information_schema.tables where table_schema = ?',
                [$name],
            );
        } catch (Throwable) {
            return ['name' => $name, 'driver' => $driver, 'size' => null, 'tables' => null];
        }

        return [
            'name' => $name,
            'driver' => $driver,
            'size' => $row === null || $row->bytes === null ? null : (int) $row->bytes,
            'tables' => $row === null ? null : (int) $row->table_count,
        ];
    }

    /**
     * The newest row in `migrations`.
     *
     * The table stores no timestamp, so the stamp is read from the filename prefix — which is
     * exactly the migration's own version, and the only honest answer available.
     *
     * @return array{stamp: string|null, name: string|null, batch: int|null}
     */
    private function lastMigration(): array
    {
        try {
            $row = DB::table('migrations')->orderByDesc('id')->first();
        } catch (Throwable) {
            return ['stamp' => null, 'name' => null, 'batch' => null];
        }

        if ($row === null) {
            return ['stamp' => null, 'name' => 'none recorded', 'batch' => null];
        }

        $name = (string) ($row->migration ?? '');
        $batch = isset($row->batch) ? (int) $row->batch : null;
        $stamp = null;

        if (preg_match('/^(\d{4})_(\d{2})_(\d{2})_(\d{6})_/', $name, $matches) === 1) {
            try {
                $stamp = CarbonImmutable::createFromFormat(
                    'Y_m_d_His',
                    $matches[1].'_'.$matches[2].'_'.$matches[3].'_'.$matches[4],
                    (string) config('app.timezone', 'UTC'),
                )->format('d M Y H:i');
            } catch (Throwable) {
                $stamp = null;
            }
        }

        return [
            'stamp' => $stamp,
            'name' => $batch === null ? $name : 'batch '.$batch.' · '.$name,
            'batch' => $batch,
        ];
    }

    /**
     * Is `public/storage` actually wired to `storage/app/public`?
     *
     * A symlink on Linux, a junction or a plain directory on Windows — all three are legitimate
     * results of `storage:link`, so the probe tests reachability rather than link type: it reads
     * back a path it knows should resolve.
     *
     * @return array{linked: bool, detail: string}
     */
    private function storageLink(): array
    {
        $link = public_path('storage');
        $target = storage_path('app'.DIRECTORY_SEPARATOR.'public');

        if (! file_exists($link)) {
            return ['linked' => false, 'detail' => 'run php artisan storage:link'];
        }

        if (is_link($link)) {
            $resolved = @readlink($link);

            return [
                'linked' => true,
                'detail' => $resolved === false ? 'symlink' : 'symlink → '.$this->shortPath($resolved),
            ];
        }

        if (is_dir($link)) {
            // A junction (Windows) or a copied directory. Both work; say which it is.
            $same = @realpath($link) !== false && @realpath($link) === @realpath($target);

            return [
                'linked' => true,
                'detail' => $same ? 'junction → '.$this->shortPath($target) : 'directory (not the storage target)',
            ];
        }

        return ['linked' => false, 'detail' => 'public/storage exists but is a file'];
    }

    /**
     * Rows in `failed_jobs`, or null when the table is not there.
     */
    private function failedJobs(): ?int
    {
        try {
            return (int) DB::table('failed_jobs')->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A path the card can show without spilling the whole server layout into the page.
     */
    private function shortPath(string $path): string
    {
        $base = base_path();

        if (str_starts_with($path, $base)) {
            return '.'.str_replace('\\', '/', substr($path, strlen($base)));
        }

        return basename($path);
    }

    public function emptyMessage(): ?string
    {
        return null;
    }
}
