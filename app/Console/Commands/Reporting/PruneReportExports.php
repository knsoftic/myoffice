<?php

declare(strict_types=1);

namespace App\Console\Commands\Reporting;

use App\Models\Reporting\ReportExport;
use App\Services\Reporting\ReportExportService;
use Illuminate\Console\Command;

/**
 * Remove the files of exports whose retention has run out (phase-19-23 10.4).
 *
 * **The file goes; the row stays.** 2.26 keeps the record of what left the building for ever, so
 * this marks each row `expired` and clears its path rather than deleting anything. Somebody who
 * bookmarked a download link is then told the file has gone, which is a different and more useful
 * answer than "that export never existed".
 *
 * One activity row is written with the count, so a sweep that removed four hundred files on a
 * Tuesday night is visible afterwards. A prune nobody can see the shape of is a prune nobody can
 * distinguish from a bug.
 */
final class PruneReportExports extends Command
{
    protected $signature = 'reports:prune-exports {--dry-run : List what would be removed and change nothing}';

    protected $description = 'Delete expired report export files, keeping the record of each one.';

    public function handle(ReportExportService $exports): int
    {
        $due = ReportExport::query()->dueForPrune()->count();

        if ($due === 0) {
            $this->components->info('No export files have expired.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->warn(sprintf('%d export file(s) would be removed.', $due));

            ReportExport::query()
                ->dueForPrune()
                ->orderBy('id')
                ->limit(25)
                ->get()
                ->each(fn (ReportExport $export) => $this->line(sprintf(
                    '  %s  %s  expired %s',
                    $export->uuid,
                    $export->report_key,
                    $export->expires_at?->diffForHumans() ?? '',
                )));

            return self::SUCCESS;
        }

        $removed = $exports->prune();

        activity('reports')
            ->withProperties(['removed' => $removed, 'due' => $due])
            ->log(sprintf('Pruned %d expired report export file(s).', $removed));

        $this->components->info(sprintf(
            '%d file(s) removed. %d row(s) kept as the record of them.',
            $removed,
            $due,
        ));

        return self::SUCCESS;
    }
}
