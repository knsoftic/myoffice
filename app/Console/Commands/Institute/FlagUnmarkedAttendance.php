<?php

declare(strict_types=1);

namespace App\Console\Commands\Institute;

use App\Services\Institute\AttendanceReportService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `attendance:flag-unmarked` — the evening nudge (§10.4).
 *
 * Daily at 20:00. **It never marks anything.** Who was in the room is a fact only a person in the
 * room has, and a command that filled in a register would be inventing attendance. It lists the
 * classes that finished without one, so a teacher can be reminded and a coordinator can see the
 * backlog — and that is the whole of its job.
 *
 * `institute.attendance_auto_absent_on_close` is a different thing: that fills unmarked students as
 * absent when a person closes a class, and stamps those rows `system` so the difference stays
 * visible. This command closes nothing.
 */
#[AsCommand(name: 'attendance:flag-unmarked')]
final class FlagUnmarkedAttendance extends Command
{
    protected $signature = 'attendance:flag-unmarked {--teacher= : Only this teacher id}';

    protected $description = 'List classes that finished with no register (marks nothing)';

    public function handle(AttendanceReportService $reports): int
    {
        $filters = [];

        if ($this->option('teacher') !== null) {
            $filters['teacher_id'] = (int) $this->option('teacher');
        }

        $sessions = $reports->unmarked(null, $filters);

        if ($sessions->isEmpty()) {
            $this->info('Every class that has finished has a register.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            '%d class%s finished without a register:',
            $sessions->count(),
            $sessions->count() === 1 ? '' : 'es',
        ));

        foreach ($sessions->take(50) as $session) {
            $this->line(sprintf(
                '  #%d  %s %s  %s  %s',
                $session->id,
                $session->session_date,
                $session->start_time,
                str_pad((string) ($session->batch_code ?? '?'), 14),
                $session->teacher_name ?? 'no teacher',
            ));
        }

        if ($sessions->count() > 50) {
            $this->line(sprintf('  … and %d more', $sessions->count() - 50));
        }

        $this->newLine();
        $this->line('Nothing was marked. An attendance row is a person\'s observation, not a schedule\'s.');

        return self::SUCCESS;
    }
}
