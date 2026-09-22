<?php

declare(strict_types=1);

namespace App\Console\Commands\Institute;

use App\Models\Institute\StudentBatchEnrollment;
use App\Services\Institute\AttendanceService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `attendance:recount` — recompute every enrolment's counters and percentage (§10.4, INV-I11).
 *
 * Daily at 02:20. **This is the proof, not the repair.** Every stored percentage is a cache derived
 * from the register rows, and the invariant says it must be re-derivable; a row that changes here is
 * a row that had drifted, so the command reports each one rather than fixing it quietly. A silent
 * repair would let the same bug write a wrong number every night for a year.
 */
#[AsCommand(name: 'attendance:recount')]
final class RecountAttendance extends Command
{
    protected $signature = 'attendance:recount
        {--batch= : Only enrolments of this batch}
        {--dry-run : Report the drift and write nothing}';

    protected $description = "Recompute every enrolment's attendance counters and percentage";

    public function handle(AttendanceService $attendance): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $drifted = [];
        $checked = 0;

        StudentBatchEnrollment::query()
            ->when($this->option('batch') !== null, fn ($q) => $q->where('batch_id', (int) $this->option('batch')))
            ->with('batch:id,code')
            ->chunkById(200, function ($rows) use ($attendance, $dryRun, &$drifted, &$checked): void {
                foreach ($rows as $enrollment) {
                    $checked++;

                    $before = [
                        (int) $enrollment->sessions_expected_count,
                        (int) $enrollment->present_count,
                        (int) $enrollment->absent_count,
                        (int) $enrollment->leave_count,
                        (int) $enrollment->late_count,
                        (string) $enrollment->attendance_percentage,
                    ];

                    $attendance->recountEnrollment($enrollment);

                    $after = [
                        (int) $enrollment->sessions_expected_count,
                        (int) $enrollment->present_count,
                        (int) $enrollment->absent_count,
                        (int) $enrollment->leave_count,
                        (int) $enrollment->late_count,
                        (string) $enrollment->attendance_percentage,
                    ];

                    if ($before === $after) {
                        return;
                    }

                    $drifted[] = sprintf(
                        '#%d (%s): %s%% -> %s%%',
                        $enrollment->getKey(),
                        $enrollment->batch?->code ?? '?',
                        $before[5],
                        $after[5],
                    );

                    if ($dryRun) {
                        // Put the stored values back: --dry-run reports and writes nothing.
                        $enrollment->forceFill([
                            'sessions_expected_count' => $before[0],
                            'present_count' => $before[1],
                            'absent_count' => $before[2],
                            'leave_count' => $before[3],
                            'late_count' => $before[4],
                            'attendance_percentage' => $before[5],
                        ])->save();
                    }
                }
            });

        $this->info(sprintf('%d enrolment%s checked.', $checked, $checked === 1 ? '' : 's'));

        if ($drifted === []) {
            $this->info('Every stored percentage matched its register rows exactly (INV-I11).');

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d row%s had drifted:', count($drifted), count($drifted) === 1 ? '' : 's'));

        foreach (array_slice($drifted, 0, 50) as $line) {
            $this->line('  '.$line);
        }

        if (count($drifted) > 50) {
            $this->line(sprintf('  … and %d more', count($drifted) - 50));
        }

        return self::SUCCESS;
    }
}
