<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Enums\BackupStatus;
use App\Services\Ops\BackupRetentionService;
use App\Support\Ops\RetentionPlan;
use App\Support\Ops\RetentionResult;
use Illuminate\Console\Command;
use Throwable;

/**
 * `backup:prune` — removes archive files, and cannot remove a record (phase-24-25 §6.6, §6.10.3).
 *
 * **`--dry-run` reaches no code that deletes anything, and the branch is the first thing that
 * happens.** The plan is resolved by {@see BackupRetentionService::plan()}, which has no side effect
 * of any kind, and the dry arm returns before `prune()` is so much as named. The alternative shape —
 * one loop with `if (! $dryRun) { delete }` inside it, or worse a delete inside a transaction that
 * is rolled back — is one misplaced negation away from destroying archives during a preview, and the
 * preview is exactly what an operator runs the morning before a deploy *because* they are not ready
 * to lose anything. A dry run that deleted one file would be discovered at the next restore.
 *
 * **The `retention_min_copies` floor outranks every date window, it is checked last, and it is
 * printed.** The retention ladder is a calendar and a calendar can be talked into strange answers: a
 * month with no Sunday archive because the server was down, a schedule changed from daily to weekly
 * the week before an audit, a clock that moved. Any of those can leave the date rules pointing at two
 * usable database archives, and the same reasoning that reaches two reaches none. So the plan prints
 * the floor, the count now and the count after — an operator who sees "3 now, 3 after, floor 3" knows
 * the windows have become shorter than the floor and that a setting needs fixing, rather than
 * wondering why a year-old archive survived.
 *
 * **Nothing here deletes a `backup_runs` row, and nothing could** (§6.10.3 rule 4, D19). The table is
 * append-only: the model's `deleting` hook throws and `trg_br_no_delete` throws underneath it for the
 * raw query and the database client. A prune stamps `file_pruned_at`, `pruned_by` and moves a
 * completed run to {@see BackupStatus::Pruned}, so "did an archive of the night the wallet
 * drifted exist, how big was it and did it ever verify" stays answerable years after the bytes were
 * swept. A failed run keeps its `failed` status — overwriting it would erase the evidence that a
 * backup did not work on a night somebody may later need to ask about.
 *
 * **A storage-ceiling breach fails loudly and prunes nothing extra** (rule 5). Deleting past policy
 * to get under `backup.max_storage_gb` converts a disk problem — visible, fixable, somebody's
 * afternoon — into a lost archive nobody discovers until a restore. The policy prune still runs,
 * because everything in it was already eligible; what never happens is one more file removed to make
 * a number look right.
 *
 * Every decision is {@see BackupRetentionService}'s. This adds the branch, the table and the exit
 * code: **0 the plan ran (or was only printed), 1 a file would not go or the disk is still over the
 * ceiling** (§6.6).
 */
final class BackupPrune extends Command
{
    protected $signature = 'backup:prune
                            {--dry-run : Print the plan and change nothing}';

    protected $description = 'Apply the retention policy to backup archive files. Never deletes a backup_runs row.';

    public function handle(BackupRetentionService $retention): int
    {
        $dryRun = (bool) $this->option('dry-run');

        /*
        | Resolve, then branch. `plan()` is read-only — it reads the table and asks the disk for file
        | sizes — so the dry arm below is provably incapable of removing anything: it never reaches a
        | call that can. See the class note for why the shape matters more than the behaviour.
        */
        if ($dryRun) {
            $plan = $retention->plan();

            $this->renderPlan($plan, dryRun: true);

            // A preview whose plan already breaches the ceiling exits non-zero. The prune it is
            // previewing would throw, and a preview that exits 0 for a prune that cannot finish has
            // told the operator the opposite of what they asked.
            return $plan->ceilingBreached ? self::FAILURE : self::SUCCESS;
        }

        try {
            // `prune()` recomputes the plan itself and carries it on the result, so what is printed
            // below is the plan that actually ran rather than a second one computed against a disk
            // that has since changed.
            //
            // No actor: `pruned_by` is null when the scheduler pruned (§2.1), the console is not a
            // person, and the activity row the service writes records "Scheduled retention run" rather
            // than naming an account this command would have had to guess.
            $result = $retention->prune(null);
        } catch (Throwable $exception) {
            // Rule 5. The service has already pruned everything the policy allowed and audited it;
            // this is the loud part.
            $this->newLine();
            $this->error('backup:prune — stopped: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->renderPlan($result->plan, dryRun: false);
        $this->renderResult($result);

        // A file that would not delete leaves its row unpruned on purpose and is reported: the next
        // run tries again, and nothing has been written that claims the bytes are gone while they are
        // still occupying the disk the ceiling is computed from.
        return $result->hasFailures() ? self::FAILURE : self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    */

    /**
     * What goes, what is kept, and why — the table §6.6 asks an operator to act on.
     *
     * `RetentionPlan::describe()` is deliberately not printed alongside this. It exists for the UI's
     * "Preview prune" button and for a notification body; two accounts of one plan in one output can
     * disagree the moment somebody edits either, and the operator would have no way to tell which
     * one the prune is about to follow.
     */
    private function renderPlan(RetentionPlan $plan, bool $dryRun): void
    {
        $this->newLine();
        $this->line($dryRun
            ? 'backup:prune --dry-run — the plan. Nothing below has been touched.'
            : 'backup:prune — the plan that ran.');

        $rows = [];

        foreach ($plan->prune as $row) {
            $rows[] = $this->row('<fg=red>prune</>', $row);
        }

        /*
        | Every kept row except the ordinary ones. `RetentionPlan::rescued()` is the documented subset
        | — the floor, the newest database archive, the protected triggers — and it is the list that
        | matters, because it is the difference between "retention is working" and "retention would
        | have gone too far and something stopped it". The test here is deliberately wider than that
        | method: anything whose reason this command does not recognise gets a row of its own with its
        | raw code, rather than being counted into the "inside their window" sentence below, which
        | would then be a sentence that is quietly wrong about it.
        */
        $insideWindow = 0;

        foreach ($plan->keep as $row) {
            if ($row['reason'] === RetentionPlan::REASON_INSIDE_WINDOW) {
                $insideWindow++;

                continue;
            }

            $rows[] = $this->row('<fg=green>keep</>', $row);
        }

        // No table at all rather than an empty one: a header with nothing under it reads as "the query
        // returned nothing", and on this screen that is indistinguishable from "nothing was eligible".
        if ($rows !== []) {
            $this->newLine();
            $this->table(['', '#', 'type', 'trigger', 'class', 'until', 'size', 'why'], $rows);
        }

        if ($insideWindow > 0) {
            $this->line(sprintf(
                '  %d more archive(s) kept because they are inside their retention window.',
                $insideWindow,
            ));
        }

        $this->newLine();

        // The floor, printed whether or not it bit. An operator who can see it is the reason an old
        // archive that survived needs no explaining — see the class note.
        $this->line(sprintf(
            '  usable database archives: %d now, %d after — the floor is %d (backup.retention_min_copies).',
            $plan->usableDatabaseArchives,
            $plan->usableDatabaseArchivesAfter,
            $plan->minimumCopies,
        ));

        $this->line(sprintf(
            '  storage: %s used, %s would be released, %s left of %s (backup.max_storage_gb).',
            RetentionPlan::humanBytes($plan->usedBytes),
            RetentionPlan::humanBytes($plan->reclaimableBytes),
            RetentionPlan::humanBytes($plan->remainingBytes()),
            RetentionPlan::humanBytes($plan->ceilingBytes),
        ));

        if ($plan->ceilingBreached) {
            $this->newLine();
            $this->error(
                'The storage ceiling is still exceeded after this plan, so the prune fails rather than '
                .'pruning past policy. Free disk space, raise backup.max_storage_gb, or shorten the '
                .'retention windows — running out of disk is an operations problem, not a licence to '
                .'destroy history.'
            );
        }

        if ($plan->keep === [] && $plan->isEmpty()) {
            // Not "nothing to prune" — nothing at all. Said plainly, because a prune that reports a
            // clean run on an empty shelf is the most reassuring possible way to be told there are no
            // backups. Whether that is acceptable is §6.13's question, not this command's, so this
            // states the fact and does not fail on it.
            $this->newLine();
            $this->warn('There are no archives on disk at all, so there was nothing to consider. Take one: php artisan backup:run --type=full --reason="first archive".');

            return;
        }

        if ($plan->isEmpty()) {
            $this->newLine();
            $this->info('Nothing is eligible for pruning. Every archive is inside its window, protected, or held by the floor.');
        }
    }

    private function renderResult(RetentionResult $result): void
    {
        $this->newLine();

        $line = sprintf(
            'backup:prune — %d archive file(s) removed, %s released. No backup_runs row was deleted (§6.10.3 rule 4, D19).',
            $result->count(),
            RetentionPlan::humanBytes($result->reclaimedBytes),
        );

        if ($result->hasFailures()) {
            $this->warn($line);
        } else {
            $this->info($line);
        }

        foreach ($result->failures as $failure) {
            $this->line(sprintf(
                '  <fg=red>would not delete</> #%d %s — %s',
                $failure['id'],
                $failure['path'],
                $failure['error'],
            ));
        }

        if ($result->hasFailures()) {
            $this->line(
                '  Those rows are deliberately left unpruned: the file is still there, so the row still '
                .'says so, and the next run tries again.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function row(string $marker, array $row): array
    {
        return [
            $marker,
            (string) $row['id'],
            (string) $row['type'],
            (string) $row['trigger'],
            (string) $row['retention_class'],
            $row['retention_until'] === null ? '—' : app_date($row['retention_until']),
            RetentionPlan::humanBytes((string) (int) $row['size_bytes']),
            $this->why((string) $row['reason']),
        ];
    }

    /**
     * The plan's reason code as a sentence.
     *
     * The constants are the authority, not the strings — and an unknown code prints itself rather
     * than an empty cell, so a reason added to `RetentionPlan` later is visible here the day it
     * lands instead of turning one column blank on the screen an operator trusts.
     */
    private function why(string $reason): string
    {
        return match ($reason) {
            RetentionPlan::REASON_WINDOW_EXPIRED => 'its retention window has closed',
            RetentionPlan::REASON_INSIDE_WINDOW => 'inside its retention window',
            RetentionPlan::REASON_PROTECTED_TRIGGER => 'pre-restore/pre-deploy — never pruned (rule 3)',
            RetentionPlan::REASON_NEWEST_DATABASE => 'newest database archive — never pruned (rule 2)',
            RetentionPlan::REASON_MINIMUM_COPIES => 'held back by the minimum-copies floor (rule 1)',
            default => $reason,
        };
    }
}
