<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Enums\BackupType;
use App\Enums\BackupVerificationStatus;
use App\Models\Ops\BackupRun;
use App\Services\Ops\BackupService;
use App\Services\Ops\BackupVerificationService;
use App\Support\Ops\RetentionPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * `backup:verify` — turns an archive from a hypothesis into a backup (phase-24-25 §6.6, §6.10.4, HD-6).
 *
 * **A backup nobody has restored is a file with a hopeful name.** That is HD-6, and it is why this
 * command has two levels rather than a `--check` flag. Without `--deep` it proves the bytes on disk
 * still hash to `checksum_sha256` and that the archive opens as a zip — which catches a truncated
 * write, a half-finished offsite copy, a disk that has rotted, and proves *nothing whatever* about
 * whether `mysql` would accept the dump inside. With `--deep` the archive is restored into
 * `backup.restore_scratch_database`, the schema is brought forward, the proof counts of §6.10.4 are
 * compared, and `financial:verify-constraints` and the wallet reconciliation are run **on the
 * restored copy**. Only that reaches `restore_ok`, and only `restore_ok` satisfies go-live (§6.13,
 * {@see BackupVerificationStatus::satisfiesGoLive()}).
 *
 * **`--deep` runs as a different database user than the shallow path, and that is the point (D168).**
 * {@see BackupVerificationService::ddlConnection()} resolves `mysql_migration`, because §6.9.3 grants
 * the runtime user `my_office_app` SELECT, INSERT, UPDATE, DELETE and EXECUTE and nothing else: it
 * cannot `CREATE DATABASE`, cannot load a dump into one, cannot drop one. So on a hardened install
 * the checksum level runs as the application and the deep level runs as the migration user — and a
 * deep proof wired to the default connection would fail at its first statement on every correctly
 * installed production host while passing on every developer's XAMPP root, which is the shape of bug
 * that reaches production and keeps GL-36 red for ever. The nine append-only delete triggers carry
 * the DEFINER of whoever ran `migrate`, so the restore has to run as that user or ask for `SUPER`,
 * which §6.9.3 forbids. This command reports which of the two connections the proof will have used,
 * because a `restore_ok` earned by the fallback is not the proof the contract asked for.
 *
 * **`--latest` and `--backup=` are mutually exclusive, and giving neither is an error rather than a
 * silent default.**
 * "Verify something" is not a request anybody meant: a silent default would either re-verify the
 * newest archive when the operator meant the one they are about to restore from, or verify the one
 * they named when the schedule meant the newest. Both answers look like a pass, and one of them is a
 * pass about the wrong file. Passing both is the same mistake stated twice.
 *
 * **It never repairs anything** (spine §6.5.4, INV-26). A proof that is allowed to edit the thing it
 * is proving is not a proof, and a verification that quietly fixed what it found would make the same
 * problem invisible every week.
 *
 * Every verdict is {@see BackupVerificationService}'s. This adds the argument handling, the operator
 * table and the exit code: **0 proved, 1 not proved** (§6.6).
 */
final class BackupVerify extends Command
{
    protected $signature = 'backup:verify
                            {--backup= : The id or uuid of one archive}
                            {--latest : The newest usable archive instead}
                            {--deep : Restore it into the scratch database and prove the money (HD-6)}';

    protected $description = 'Prove an archive: its checksum, or with --deep the restore-into-scratch proof of HD-6.';

    public function handle(BackupService $backups, BackupVerificationService $verification): int
    {
        $deep = (bool) $this->option('deep');

        $run = $this->resolve($backups, $deep);

        if ($run === null) {
            return self::FAILURE;
        }

        $this->line(sprintf(
            'backup:verify — %s proof of %s (%s archive%s).',
            $deep ? 'deep restore' : 'checksum',
            $run->filename ?? $run->uuid,
            $run->type->value,
            $deep ? ', '.$this->deepConnectionNote() : '',
        ));

        $before = $run->verification_status;

        try {
            // Two calls, one decision, and the decision is `--deep`. Neither branch is a superset of
            // the other in output, but `verifyByRestore()` runs the checksum itself first — an
            // archive that does not match its own hash would otherwise produce a verdict about a file
            // nobody can identify.
            $verified = $deep
                ? $verification->verifyByRestore($run)
                : $verification->verifyChecksum($run);
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error('backup:verify — the verification itself did not complete: '.mb_substr($exception->getMessage(), 0, 600));
            $this->line('  ('.$exception::class.') The row keeps the verdict it had, which is '.$before->label().'.');

            return self::FAILURE;
        }

        $status = $verified->verification_status;

        // The two levels ask two different questions and the enum is where each is defined:
        // `satisfiesGoLive()` is the HD-6 gate that only a restore earns, `checksumProved()` is the
        // narrower one the restore wizard's gate 2 asks. Comparing the status against a string here
        // would be a third opinion about what "verified" means.
        $ok = $deep ? $status->satisfiesGoLive() : $status->checksumProved();

        $this->render($verified, $deep);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /*
    |--------------------------------------------------------------------------
    | Which archive
    |--------------------------------------------------------------------------
    */

    /**
     * The archive to verify, or null with the reason already printed.
     */
    private function resolve(BackupService $backups, bool $deep): ?BackupRun
    {
        $named = $this->option('backup');
        $named = $named === null ? null : trim((string) $named);
        $named = $named === '' ? null : $named;
        $latest = (bool) $this->option('latest');

        if ($named !== null && $latest) {
            $this->error(
                '--backup and --latest both name the archive to verify, and they disagree by '
                .'construction. Pass one: --backup='.$named.' for that archive, or --latest for the newest.'
            );

            return null;
        }

        if ($named === null && ! $latest) {
            // Not a default. See the class note.
            $this->error(
                'Nothing to verify. Pass --latest for the newest usable archive, or --backup=<id|uuid> '
                .'for one in particular. There is deliberately no default: "verify something" is not a '
                .'request anybody meant.'
            );

            return null;
        }

        $run = $named === null ? $this->newestUsable($backups, $deep) : $this->find($named);

        if ($run === null) {
            $this->error($named === null
                ? 'There is no usable archive to verify'.($deep ? ' that contains a database.' : '.')
                    .' Take one first: php artisan backup:run --type=full --reason="first archive".'
                : sprintf('No backup_runs row matches [%s]. The id or the uuid, as the backups register prints it.', $named));

            return null;
        }

        // `isUsable()` is the model's own question — `completed` *and* the file still on disk — and it
        // is the same one the restore screen, the minimum-copies rule and the go-live gate ask, so
        // this command does not get to form a fourth opinion about what is verifiable. Refusing here
        // matters because the service would otherwise record `failed` on the row: an archive that was
        // swept by policy last month would lose the `restore_ok` it earned while it existed, replaced
        // by a verdict that says only "the file is gone" — which `file_pruned_at` already says.
        if (! $run->isUsable()) {
            $this->error(sprintf(
                'Archive %s is not verifiable: the run is %s%s. Its verdict is left as it is — %s — '
                .'because the row is the record of the backup and overwriting it would destroy the only '
                .'account of what that archive was once proved to be.',
                $run->uuid,
                $run->status->value,
                $run->file_pruned_at === null
                    ? ''
                    : ' and retention removed the file on '.app_date($run->file_pruned_at),
                $run->verification_status->value,
            ));

            return null;
        }

        // Refused before the service is called, on purpose. `verifyByRestore()` would record
        // `verification_status = failed` on a files archive — a correct verdict about a question
        // nobody meant to ask, stamped for ever onto a perfectly good archive because of one typed
        // option. The right answer is the same night's database archive, so say so.
        if ($deep && ! $run->type->includesDatabase()) {
            $this->error(sprintf(
                'Archive %s is %s only, so there is no database to restore into the scratch schema. '
                .'Verify the database or full archive of the same night with --deep, and this one with '
                .'a plain checksum.',
                $run->uuid,
                $run->type->value,
            ));

            return null;
        }

        return $run;
    }

    /**
     * An id or a uuid — both, because the register prints the uuid and the table prints the id.
     */
    private function find(string $needle): ?BackupRun
    {
        if (ctype_digit($needle)) {
            return BackupRun::query()->find((int) $needle);
        }

        return BackupRun::query()->where('uuid', $needle)->first();
    }

    /**
     * The newest usable archive, restricted to the ones the requested level can prove.
     *
     * Asked per type through {@see BackupService::latestUsable()} and reduced here rather than
     * queried directly, because "usable" is one definition — completed, not pruned, still pointing
     * at a file — and a second query written in this file would be a second definition that drifts.
     * The reduction uses the same `finished_at ?? started_at ?? created_at` key the service orders by.
     */
    private function newestUsable(BackupService $backups, bool $deep): ?BackupRun
    {
        $newest = null;
        $newestAt = null;

        foreach (BackupType::cases() as $type) {
            // A deep proof needs a dump. `includesDatabase()` is the predicate rather than a literal
            // list of types, so a type added later is either counted correctly or not at all.
            if ($deep && ! $type->includesDatabase()) {
                continue;
            }

            $candidate = $backups->latestUsable($type);

            if ($candidate === null) {
                continue;
            }

            $at = $this->sortKey($candidate);

            if ($newestAt === null || $at->greaterThan($newestAt)) {
                $newest = $candidate;
                $newestAt = $at;
            }
        }

        return $newest;
    }

    private function sortKey(BackupRun $run): Carbon
    {
        $at = $run->finished_at ?? $run->started_at ?? $run->created_at;

        // A row with no timestamp at all sorts oldest rather than being dropped: it is still a usable
        // archive and still verifiable, and it cannot be the newest one under any reading.
        return $at === null ? Carbon::createFromTimestamp(0) : Carbon::instance($at);
    }

    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    */

    /**
     * Which database user the deep proof will have run as.
     *
     * The resolution itself belongs to {@see BackupVerificationService::ddlConnection()} and is not
     * reimplemented — this repeats only its fallback test, so the console can say which of the two
     * happened. A `restore_ok` earned on the fallback is a rehearsal run by the application user, and
     * on a hardened install (§6.9.3) that same run is denied at `CREATE DATABASE`. Saying so is what
     * stops the green verdict from being read as "the privilege separation works" (D168).
     */
    private function deepConnectionNote(): string
    {
        return is_array(config('database.connections.mysql_migration'))
            ? 'through the mysql_migration connection (D168)'
            : 'through the default connection — mysql_migration is not configured, so this proof runs as the '
                .'runtime user and would be refused on a hardened install (D168)';
    }

    /**
     * The table an operator can act on: what was proved, about which file, and what it said.
     */
    private function render(BackupRun $run, bool $deep): void
    {
        // Taken *and* its age, which is the announcement §6.10.5 step 1 asks for: the checksum says
        // the file is intact, the age says how much work a restore from it would lose.
        $taken = $run->finished_at ?? $run->started_at;

        $rows = [
            ['archive', $run->filename ?? '(none)'],
            ['disk · path', $run->disk.' · '.($run->path ?? '(none)')],
            ['type · trigger', $run->type->value.' · '.$run->trigger->value],
            ['taken', $taken === null ? '—' : app_datetime($taken).' ('.Carbon::instance($taken)->diffForHumans().')'],
            ['size', $run->size_bytes === null ? '—' : RetentionPlan::humanBytes((string) $run->size_bytes)],
            // The short form only; the full 64 characters are on the row for whoever is comparing
            // two archives byte for byte.
            ['checksum', $run->checksum_sha256 === null ? '—' : mb_substr($run->checksum_sha256, 0, 12).'… (sha256)'],
            ['level run', $deep ? 'deep — restore into '.(string) setting('backup.restore_scratch_database', 'my_office_restore_test') : 'checksum'],
            ['verdict', $this->marker($run)],
            ['verified', $run->verified_at === null ? '—' : app_datetime($run->verified_at)],
        ];

        $this->newLine();
        $this->table(['', 'run '.$run->uuid], $rows);
        $this->newLine();

        $line = sprintf(
            'backup:verify — %s. %s',
            $run->verification_status->label(),
            $run->verification_status->description(),
        );

        // A shallow pass is amber, not green: `ChecksumOk` is not a green state (§6.10.4), and a
        // green line is how an unrestored archive stops being looked at.
        match (true) {
            $run->verification_status->satisfiesGoLive() => $this->info($line),
            $run->verification_status->checksumProved() => $this->warn($line),
            default => $this->error($line),
        };

        // The notes are the reason somebody ran this: which count did not match, which command
        // failed on the restored copy. Printed as the service recorded them — the notes are also
        // what the backups screen renders, so anything that has to be taken out of them belongs in
        // `BackupVerificationService::record()` and not in one of its two readers.
        if ($run->verification_notes !== null && $run->verification_notes !== '') {
            foreach (explode("\n", wordwrap($run->verification_notes, 110)) as $noteLine) {
                $this->line('  '.$noteLine);
            }
        }

        // The amber case, stated out loud. `ChecksumOk` is not a green state (§6.10.4): intact bytes
        // that have never been restored are the exact thing HD-6 was written about.
        if (! $deep && $run->verification_status->checksumProved()) {
            $this->line(sprintf(
                '  <fg=yellow>not a backup for go-live yet</> → php artisan backup:verify --backup=%s --deep',
                $run->uuid,
            ));
        }
    }

    private function marker(BackupRun $run): string
    {
        $status = $run->verification_status;

        if ($status->satisfiesGoLive()) {
            return '<fg=green>'.$status->value.'</>';
        }

        return $status->checksumProved()
            ? '<fg=yellow>'.$status->value.'</>'
            : '<fg=red>'.$status->value.'</>';
    }
}
