<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets;

use App\Dashboard\Concerns\FormatsBytes;
use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * What the application is actually using on disk (phase-02 §3).
 *
 * Measured, not estimated: every local disk in `config('filesystems.disks')` plus the framework's
 * own working directories are walked and their files added up, and the volume's own free space
 * comes from `disk_free_space()`. Nothing here is a guess, and nothing is a database query — this
 * card costs **zero queries**.
 *
 * **Bounded, because a filesystem walk is the one thing on a dashboard that can run away.** Three
 * limits, all stated in the card when they bite:
 *
 *  1. at most 40,000 files are visited across all roots; beyond that the figure is reported as a
 *     floor ("at least …") rather than a total;
 *  2. recursion stops at 12 levels, which no upload tree legitimately exceeds;
 *  3. the whole result is cached for five minutes, so hammering the refresh button cannot turn
 *     into a disk-thrashing loop.
 *
 * Deferred, so the walk happens after the page has painted, behind an `x-ui.skeleton`.
 */
final class StorageUsageWidget extends Widget
{
    use FormatsBytes;

    /** Files visited before the walk gives up and reports a floor. */
    private const FILE_LIMIT = 40_000;

    /** Directory depth ceiling. */
    private const DEPTH_LIMIT = 12;

    /** How long a measurement is reused. */
    private const CACHE_SECONDS = 300;

    private const CACHE_KEY = 'dashboard.widget.storage_usage';

    public function key(): string
    {
        return 'storage_usage';
    }

    public function title(): string
    {
        return 'Storage usage';
    }

    public function icon(): string
    {
        return 'folder';
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
        return 20;
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
        return 'Uploads, logs, cache and free space';
    }

    /**
     * Not range-scoped: disk contents have no period.
     *
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            /** @var array<string, mixed> $measurement */
            $measurement = Cache::remember(
                self::CACHE_KEY,
                self::CACHE_SECONDS,
                fn (): array => $this->measure(),
            );

            return $measurement;
        } catch (Throwable) {
            // No cache store, or an unreadable tree: measure once, uncached, and still answer.
            try {
                return $this->measure();
            } catch (Throwable) {
                return [
                    'available' => false,
                    'areas' => [],
                    'total' => 0,
                    'total_label' => '—',
                    'files' => 0,
                    'truncated' => false,
                    'volume' => null,
                    'measured_at' => null,
                ];
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Measuring
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function measure(): array
    {
        $budget = self::FILE_LIMIT;
        $areas = [];

        foreach ($this->roots() as $area) {
            $result = $this->walk($area['path'], $budget);
            $budget = max(0, $budget - $result['files']);

            $areas[] = [
                'key' => $area['key'],
                'label' => $area['label'],
                'description' => $area['description'],
                'icon' => $area['icon'],
                'color' => $area['color'],
                'exists' => $result['exists'],
                'bytes' => $result['bytes'],
                'size_label' => $result['exists'] ? $this->formatBytes($result['bytes']) : '—',
                'files' => $result['files'],
                'truncated' => $result['truncated'],
            ];
        }

        $total = array_sum(array_column($areas, 'bytes'));
        $files = array_sum(array_column($areas, 'files'));
        $truncated = in_array(true, array_column($areas, 'truncated'), true);

        // Share of the measured total, so the bars add up to 100% of what this card measured —
        // never of the whole volume, which would make every bar invisible.
        foreach ($areas as $index => $area) {
            $areas[$index]['share'] = $total > 0 ? (int) round(($area['bytes'] / $total) * 100) : 0;
        }

        return [
            'available' => true,
            'areas' => $areas,
            'total' => $total,
            'total_label' => ($truncated ? 'at least ' : '').$this->formatBytes($total),
            'files' => $files,
            'truncated' => $truncated,
            'file_limit' => self::FILE_LIMIT,
            'volume' => $this->volume(),
            'measured_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * The directories this card reports on: every *local* filesystem disk, plus the framework's
     * own working directories. A remote disk (s3) has no local path and is skipped rather than
     * reported as empty.
     *
     * @return list<array{key: string, label: string, description: string, icon: string, color: string, path: string}>
     */
    private function roots(): array
    {
        $areas = [];
        $disks = config('filesystems.disks');
        $seen = [];

        if (is_array($disks)) {
            foreach ($disks as $name => $disk) {
                if (! is_array($disk) || ($disk['driver'] ?? null) !== 'local') {
                    continue;
                }

                $path = (string) ($disk['root'] ?? '');

                if ($path === '' || isset($seen[$path])) {
                    continue;
                }

                $seen[$path] = true;
                $isPublic = ($disk['visibility'] ?? null) === 'public';

                $areas[] = [
                    'key' => 'disk:'.$name,
                    'label' => ucfirst((string) $name).' disk',
                    'description' => $isPublic
                        ? 'Logos, avatars and anything served straight from the web root'
                        : 'Private documents served only through a permission check (D21)',
                    'icon' => $isPublic ? 'photo' : 'lock-closed',
                    'color' => $isPublic ? 'brand' : 'violet',
                    'path' => $path,
                ];
            }
        }

        foreach ([
            ['logs', 'Logs', 'laravel.log and anything else the app writes', 'document-text', 'amber', storage_path('logs')],
            ['framework', 'Framework cache', 'Compiled views, sessions and the file cache', 'rectangle-stack', 'sky', storage_path('framework')],
            ['bootstrap', 'Bootstrap cache', 'Cached config, routes and the package manifest', 'cog-6-tooth', 'slate', base_path('bootstrap/cache')],
        ] as [$key, $label, $description, $icon, $color, $path]) {
            if (isset($seen[$path])) {
                continue;
            }

            $seen[$path] = true;

            $areas[] = [
                'key' => $key,
                'label' => $label,
                'description' => $description,
                'icon' => $icon,
                'color' => $color,
                'path' => $path,
            ];
        }

        return $areas;
    }

    /**
     * Add up one tree, stopping at the shared file budget.
     *
     * @return array{exists: bool, bytes: int, files: int, truncated: bool}
     */
    private function walk(string $path, int $budget): array
    {
        if ($path === '' || ! is_dir($path)) {
            return ['exists' => false, 'bytes' => 0, 'files' => 0, 'truncated' => false];
        }

        if ($budget <= 0) {
            return ['exists' => true, 'bytes' => 0, 'files' => 0, 'truncated' => true];
        }

        $bytes = 0;
        $files = 0;
        $truncated = false;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $path,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
                ),
                RecursiveIteratorIterator::LEAVES_ONLY,
                // An unreadable sub-directory must not abort the whole measurement.
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );

            $iterator->setMaxDepth(self::DEPTH_LIMIT);

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                // A symlink could point anywhere, including back into this tree.
                if ($file->isLink()) {
                    continue;
                }

                $files++;

                try {
                    $bytes += (int) $file->getSize();
                } catch (Throwable) {
                    // Removed between the listing and the stat: it is simply not there.
                }

                if ($files >= $budget) {
                    $truncated = true;

                    break;
                }
            }
        } catch (Throwable) {
            return ['exists' => true, 'bytes' => $bytes, 'files' => $files, 'truncated' => true];
        }

        return ['exists' => true, 'bytes' => $bytes, 'files' => $files, 'truncated' => $truncated];
    }

    /**
     * Free and total space on the volume the application lives on.
     *
     * @return array{free: int, total: int, used: int, used_share: int, free_label: string, total_label: string}|null
     */
    private function volume(): ?array
    {
        try {
            $free = @disk_free_space(base_path());
            $total = @disk_total_space(base_path());
        } catch (Throwable) {
            return null;
        }

        if ($free === false || $total === false || $total <= 0) {
            return null;
        }

        $used = (int) max(0, $total - $free);

        return [
            'free' => (int) $free,
            'total' => (int) $total,
            'used' => $used,
            'used_share' => (int) round(($used / $total) * 100),
            'free_label' => $this->formatBytes($free),
            'total_label' => $this->formatBytes($total),
        ];
    }

    public function emptyMessage(): ?string
    {
        return 'Nothing has been uploaded yet.';
    }
}
