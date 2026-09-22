<?php

declare(strict_types=1);

namespace App\Console\Commands\Institute;

use App\Models\Institute\Batch;
use App\Models\Institute\StudentCourseProgress;
use App\Services\Institute\CourseProgressService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `progress:recompute` — the proof that every progress percentage is derivable (§10.4, INV-I11).
 *
 * Daily at 02:40, for batches whose coverage or outline moved in the last day; `--all` walks
 * everything. It also tops up the module and topic rows from the outline, which is what brings a
 * student's snapshot forward after a topic was added or deactivated — and the reason deactivating an
 * uncovered topic *raises* a percentage rather than stalling it.
 */
#[AsCommand(name: 'progress:recompute')]
final class RecomputeProgress extends Command
{
    protected $signature = 'progress:recompute
        {--all : Every enrolment, not only those that changed recently}
        {--batch= : Only this batch id}
        {--hours=24 : How far back a change counts as recent}';

    protected $description = 'Recompute student progress and the batch syllabus percentages';

    public function handle(CourseProgressService $progress): int
    {
        $since = Carbon::now()->subHours(max(1, (int) $this->option('hours')));

        $batchIds = $this->batchIds($since);

        if ($batchIds === []) {
            $this->info('No batch has had coverage or outline changes in that window.');

            return self::SUCCESS;
        }

        $enrolments = 0;
        $drifted = 0;

        StudentCourseProgress::query()
            ->whereIn('batch_id', $batchIds)
            ->chunkById(200, function ($rows) use ($progress, &$enrolments, &$drifted): void {
                foreach ($rows as $row) {
                    $before = (string) $row->completion_percentage;

                    $progress->recompute($row);
                    $enrolments++;

                    if ((string) $row->refresh()->completion_percentage !== $before) {
                        $drifted++;
                    }
                }
            });

        foreach (Batch::query()->whereIn('id', $batchIds)->get() as $batch) {
            $progress->recountBatchSyllabus($batch);
        }

        $this->info(sprintf(
            '%d enrolment%s recomputed across %d batch%s; %d percentage%s moved.',
            $enrolments,
            $enrolments === 1 ? '' : 's',
            count($batchIds),
            count($batchIds) === 1 ? '' : 'es',
            $drifted,
            $drifted === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function batchIds(Carbon $since): array
    {
        if ($this->option('batch') !== null) {
            return [(int) $this->option('batch')];
        }

        if ((bool) $this->option('all')) {
            return Batch::query()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        }

        return Batch::query()
            ->whereHas('sessions', static fn ($q) => $q->where('updated_at', '>=', $since))
            ->orWhereIn('id', DB::table('batch_topic_coverage')
                ->where('updated_at', '>=', $since)
                ->select('batch_id'))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
