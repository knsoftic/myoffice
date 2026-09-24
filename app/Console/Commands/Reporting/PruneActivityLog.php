<?php

declare(strict_types=1);

namespace App\Console\Commands\Reporting;

use App\Models\Activity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prune the activity log, if - and only if - somebody has asked for it (phase-19-23 10.4).
 *
 * **`reports.activity_log_retention_days` defaults to 0, meaning never, and this command honours
 * that by doing nothing.** Every other retention setting in the system counts down to a deletion;
 * this one does not, because 110 asks for an audit trail and a financial log that quietly deletes
 * its own oldest rows is not one. The command exists so an institute that has decided otherwise has
 * a supported way to do it - not so the default can be changed by forgetting.
 *
 * **Rows that carry a `reason` are never pruned.** A reason is written when somebody had to explain
 * themselves - an amended mark, a cancelled fee, a re-linked collaborator - and those are precisely
 * the rows a dispute turns on. Losing them to a retention sweep would be losing the only record of
 * a deliberate override.
 *
 * Chunked, because the table this runs against is the one that grows fastest, and one unbounded
 * DELETE on it would hold a lock for as long as it took.
 */
final class PruneActivityLog extends Command
{
    protected $signature = 'activity-log:prune {--dry-run : Count what would go and delete nothing}';

    protected $description = 'Delete activity rows older than reports.activity_log_retention_days (0 = never).';

    public function handle(): int
    {
        $days = (int) setting('reports.activity_log_retention_days', 0);

        if ($days <= 0) {
            $this->components->info(
                'activity_log_retention_days is 0, so nothing is pruned. That is the default, and it '
                .'is deliberate: an audit trail that deletes its own history is not evidence of anything.'
            );

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        $query = fn () => Activity::query()
            ->where('created_at', '<', $cutoff)
            // Never a row somebody had to explain - see the class note.
            ->where(static fn ($q) => $q->whereNull('reason')->orWhere('reason', ''));

        $due = $query()->count();

        if ($due === 0) {
            $this->components->info(sprintf('Nothing older than %d days to prune.', $days));

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->warn(sprintf(
                '%d row(s) older than %s would be deleted. %d row(s) with a reason are kept.',
                $due,
                $cutoff->toDateString(),
                Activity::query()->where('created_at', '<', $cutoff)->whereNotNull('reason')->where('reason', '<>', '')->count(),
            ));

            return self::SUCCESS;
        }

        $deleted = 0;

        // Chunked deletes: one unbounded statement on this table holds a lock for as long as it
        // takes, and this is the table every other write appends to.
        do {
            $batch = $query()->limit(1000)->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += DB::table('activity_log')->whereIn('id', $batch)->delete();
        } while (true);

        activity('system')
            ->withProperties(['deleted' => $deleted, 'older_than' => $cutoff->toDateString(), 'retention_days' => $days])
            ->log(sprintf('Pruned %d activity log row(s).', $deleted));

        $this->components->info(sprintf('%d row(s) deleted. Rows carrying a reason were kept.', $deleted));

        return self::SUCCESS;
    }
}
