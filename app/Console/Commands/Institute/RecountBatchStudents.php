<?php

declare(strict_types=1);

namespace App\Console\Commands\Institute;

use App\Models\Institute\Batch;
use App\Services\Institute\BatchService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `batches:recount-students` — INV-I7's proof (§10.4, D48).
 *
 * Daily at 01:40. `batches.current_students` is a cache equal to the count of active enrolments, and
 * the invariant says no screen, report or capacity check may treat it as truth. This re-derives every
 * one and **reports each row that differed**, because a cache that drifted means something wrote it
 * that should not have — and fixing that quietly every night would hide the bug for good.
 */
#[AsCommand(name: 'batches:recount-students')]
final class RecountBatchStudents extends Command
{
    protected $signature = 'batches:recount-students {--batch= : Only this batch id}';

    protected $description = 'Recount every batch\'s student and session caches, reporting any drift';

    public function handle(BatchService $batches): int
    {
        $drifted = [];
        $checked = 0;

        Batch::query()
            ->when($this->option('batch') !== null, fn ($q) => $q->where('id', (int) $this->option('batch')))
            ->chunkById(200, function ($rows) use ($batches, &$drifted, &$checked): void {
                foreach ($rows as $batch) {
                    $checked++;

                    $storedStudents = (int) $batch->current_students;
                    $storedSessions = (int) $batch->sessions_held_count;

                    $students = $batches->recountStudents($batch);
                    $batches->recountSessions($batch);

                    if ($storedStudents !== $students) {
                        $drifted[] = sprintf('%s: students %d -> %d', $batch->code, $storedStudents, $students);
                    }

                    $sessions = (int) $batch->refresh()->sessions_held_count;

                    if ($storedSessions !== $sessions) {
                        $drifted[] = sprintf('%s: classes held %d -> %d', $batch->code, $storedSessions, $sessions);
                    }
                }
            });

        $this->info(sprintf('%d batch%s checked.', $checked, $checked === 1 ? '' : 'es'));

        if ($drifted === []) {
            $this->info('Every cache matched the rows it is derived from (INV-I7).');

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d cache%s had drifted:', count($drifted), count($drifted) === 1 ? '' : 's'));

        foreach ($drifted as $line) {
            $this->line('  '.$line);
        }

        return self::SUCCESS;
    }
}
