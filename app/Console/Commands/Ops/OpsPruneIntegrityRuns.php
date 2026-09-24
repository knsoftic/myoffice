<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Enums\IntegrityCheckStatus;
use App\Models\Ops\IntegrityCheckRun;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * `ops:prune-integrity-runs` — retention for the proof table that deletes nothing (phase-24-25
 * §2.3, §6.6, D19).
 *
 * **It does not delete a single row, and it could not if it tried.** `integrity_check_runs` is
 * append-only: the model refuses `delete()` with a readable exception and a `BEFORE DELETE` trigger
 * refuses it underneath the model as well, so a raw query or a truncate fails too. That is not
 * bureaucracy — a run row is the evidence that on a given night the wallets reconciled with the
 * ledger. Evidence somebody can tidy away after reading it is not evidence, and the row somebody
 * would most want gone is exactly the one a dispute is about.
 *
 * **What ages out is the `findings` blob, not the verdict.** The findings are the bulky part: two
 * hundred `{code, severity, subject, expected, actual}` entries per bad run, on a table that gains
 * nine rows a night for the life of the system. Releasing them past
 * `ops.integrity_run_retention_days` (default 180) keeps the table readable while the status, the
 * counts, the duration and the command that produced them stay for ever — which is what a "has this
 * been clean since March" question actually needs.
 *
 * **A failed run keeps its findings for {@see IntegrityCheckRun::FAILED_FINDINGS_DAYS} days**
 * (1095, three years). A failure is the run somebody comes back to, often long after the incident,
 * and "we know it failed but no longer know what it said" is the answer that makes the whole table
 * pointless. The same three years is how long the financial evidence around it is kept.
 *
 * `findings_truncated` is set at the same time, so a released run reads as "there were findings and
 * they have aged out" rather than as "this run found nothing" — {@see
 * IntegrityCheckRun::findingsReleased()} is the distinction, and it only works if both columns move
 * together.
 */
final class OpsPruneIntegrityRuns extends Command
{
    protected $signature = 'ops:prune-integrity-runs
                            {--dry-run : Count what would be released and change nothing}';

    protected $description = 'Release the findings of aged integrity runs. Deletes nothing — the table is append-only.';

    public function handle(): int
    {
        $retentionDays = max(7, (int) setting('ops.integrity_run_retention_days', 180));

        $cutoff = Carbon::now()->subDays($retentionDays);
        $failedCutoff = Carbon::now()->subDays(IntegrityCheckRun::FAILED_FINDINGS_DAYS);

        $dryRun = (bool) $this->option('dry-run');

        $ordinary = $this->release(
            IntegrityCheckRun::query()
                ->where('status', '!=', IntegrityCheckStatus::Failed->value)
                ->where('created_at', '<', $cutoff),
            $dryRun,
        );

        $failed = $this->release(
            IntegrityCheckRun::query()
                ->where('status', IntegrityCheckStatus::Failed->value)
                ->where('created_at', '<', $failedCutoff),
            $dryRun,
        );

        $this->newLine();
        $this->table(
            ['rows', 'kept findings for', 'older than'],
            [
                [$ordinary, $retentionDays.' days', $cutoff->toDateString()],
                [$failed, IntegrityCheckRun::FAILED_FINDINGS_DAYS.' days (failed runs)', $failedCutoff->toDateString()],
            ],
        );

        $this->newLine();
        $this->info(sprintf(
            'ops:prune-integrity-runs — %s %d run(s). No row was deleted; the verdicts and counts are kept for ever.',
            $dryRun ? 'would release' : 'released',
            $ordinary + $failed,
        ));

        return self::SUCCESS;
    }

    /**
     * Null the findings of everything the builder matched that still has any.
     *
     * A mass `update()` rather than a model loop on purpose: this runs weekly over a table that
     * only grows, and hydrating a hundred thousand rows to set two columns is work with no reader.
     * Nothing is lost by skipping the model events — `Blameable` would write a null `updated_by`
     * anyway, because the scheduler is not a person — and `updated_at` is set explicitly so a
     * released row still says when it was released.
     *
     * @param  Builder<IntegrityCheckRun>  $query
     */
    private function release(Builder $query, bool $dryRun): int
    {
        // Rows whose findings are already gone are skipped rather than re-stamped: re-writing
        // `updated_at` every week would make every old row look as if something had just happened
        // to it.
        $query->whereNotNull('findings');

        if ($dryRun) {
            return $query->count();
        }

        return $query->update([
            'findings' => null,
            'findings_truncated' => true,
            'updated_at' => Carbon::now(),
        ]);
    }
}
