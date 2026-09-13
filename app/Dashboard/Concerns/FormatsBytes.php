<?php

declare(strict_types=1);

namespace App\Dashboard\Concerns;

/**
 * Byte counts as something a human reads — used by the system-health and storage cards.
 *
 * Binary units (1024), labelled the way disks are actually reported on the machines this runs
 * on. A negative or unknown size returns a dash rather than "-1 B", because the health card must
 * never present a failed measurement as a measurement.
 */
trait FormatsBytes
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    protected function formatBytes(int|float|null $bytes, int $decimals = 1): string
    {
        if ($bytes === null || $bytes < 0) {
            return '—';
        }

        $bytes = (float) $bytes;

        if ($bytes < 1024) {
            return number_format($bytes).' B';
        }

        $power = (int) min(floor(log($bytes, 1024)), count(self::UNITS) - 1);
        $value = $bytes / (1024 ** $power);

        // A whole number of gigabytes does not need a decimal point.
        $decimals = $value >= 100 ? 0 : $decimals;

        return number_format($value, $decimals).' '.self::UNITS[$power];
    }
}
