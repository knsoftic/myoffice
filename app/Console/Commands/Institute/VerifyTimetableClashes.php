<?php

declare(strict_types=1);

namespace App\Console\Commands\Institute;

use App\DataObjects\Institute\SlotCandidate;
use App\Models\Institute\ClassSession;
use App\Models\Institute\DemoClass;
use App\Models\Institute\TimetableEntry;
use App\Services\Institute\ScheduleClashDetector;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `timetable:verify-clashes` — the brute-force scan behind INV-I8 (§10.4, §6.7).
 *
 * Daily at 02:50. `ScheduleClashDetector` stops a clash being *written* through the application, and
 * the unique indexes stop the identical duplicate. Neither can stop a seeder, an import or raw SQL,
 * and neither existed before Phase 16 — so this walks every live booking and asks the detector about
 * it, which is the only way to find out whether the invariant actually holds in the data.
 *
 * It reports; it never moves anybody. Which of two colliding classes should give way is a decision
 * with a timetable, a teacher and thirty students attached to it.
 */
#[AsCommand(name: 'timetable:verify-clashes')]
final class VerifyTimetableClashes extends Command
{
    protected $signature = 'timetable:verify-clashes
        {--limit=2000 : Maximum bookings examined}
        {--type= : Only timetable_entry | class_session | demo_class}';

    protected $description = 'Scan every live booking for an overlap that slipped past the detector';

    public function handle(ScheduleClashDetector $detector): int
    {
        $limit = max(1, min(20000, (int) $this->option('limit')));
        $only = $this->option('type');
        $found = [];
        $examined = 0;

        if ($only === null || $only === SlotCandidate::TYPE_TIMETABLE_ENTRY) {
            TimetableEntry::query()
                ->active()
                ->with('batch:id,code')
                ->limit($limit)
                ->each(function (TimetableEntry $entry) use ($detector, &$found, &$examined): void {
                    $examined++;
                    $report = $detector->check(SlotCandidate::forTimetableEntry($entry));

                    foreach ($report->conflicts as $conflict) {
                        $found[] = sprintf(
                            'slot #%d (%s %s) clashes on %s with %s %s',
                            $entry->getKey(),
                            $entry->batch?->code ?? '?',
                            $entry->slotLabel(),
                            $conflict->dimension,
                            $conflict->type,
                            '#'.$conflict->id,
                        );
                    }
                });
        }

        if ($only === null || $only === SlotCandidate::TYPE_CLASS_SESSION) {
            ClassSession::query()
                ->live()
                ->with('batch:id,code')
                ->limit($limit)
                ->each(function (ClassSession $session) use ($detector, &$found, &$examined): void {
                    $examined++;
                    $report = $detector->check(SlotCandidate::forClassSession($session));

                    foreach ($report->conflicts as $conflict) {
                        $found[] = sprintf(
                            'class #%d (%s %s) clashes on %s with %s %s',
                            $session->getKey(),
                            $session->batch?->code ?? '?',
                            $session->session_date->toDateString(),
                            $conflict->dimension,
                            $conflict->type,
                            '#'.$conflict->id,
                        );
                    }
                });
        }

        if ($only === null || $only === SlotCandidate::TYPE_DEMO_CLASS) {
            DemoClass::query()
                ->scheduled()
                ->limit($limit)
                ->each(function (DemoClass $demo) use ($detector, &$found, &$examined): void {
                    $examined++;
                    $report = $detector->check(SlotCandidate::forDemoClass($demo));

                    foreach ($report->conflicts as $conflict) {
                        $found[] = sprintf(
                            'demo #%d (%s) clashes on %s with %s %s',
                            $demo->getKey(),
                            $demo->scheduled_on->toDateString(),
                            $conflict->dimension,
                            $conflict->type,
                            '#'.$conflict->id,
                        );
                    }
                });
        }

        $this->info(sprintf('%d booking%s examined.', $examined, $examined === 1 ? '' : 's'));

        if ($found === []) {
            $this->info('No overlap anywhere: INV-I8 holds in the data, not only in the code.');

            return self::SUCCESS;
        }

        $this->error(sprintf('%d overlap%s found:', count($found), count($found) === 1 ? '' : 's'));

        foreach (array_slice($found, 0, 100) as $line) {
            $this->line('  '.$line);
        }

        if (count($found) > 100) {
            $this->line(sprintf('  … and %d more', count($found) - 100));
        }

        $this->newLine();
        $this->line('Nothing was moved. Which of two colliding classes gives way is a decision with a');
        $this->line('teacher and a roster attached to it.');

        return self::FAILURE;
    }
}
