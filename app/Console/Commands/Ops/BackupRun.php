<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Enums\BackupType;
use App\Models\Ops\BackupRun as BackupRunRecord;
use App\Services\Ops\BackupService;
use App\Support\Ops\RetentionPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * `backup:run` — takes the archive, and refuses to take an anonymous one (phase-24-25 §6.6, §6.10).
 *
 * **The presence of `--reason` is what makes a run manual, and a manual run without a reason is
 * refused.** That is {@see BackupTrigger::requiresReason()} and it is the whole difference between a
 * backup and a file: in six months the only thing that can explain why somebody took an unscheduled
 * copy of the database at 16:40 on a Tuesday is the sentence they typed, and a command that accepted
 * an empty one would fill `backup_runs.reason` with the word "backup" and teach every later reader
 * to skip the column. A run with no `--reason` is therefore `BackupTrigger::Scheduled` — it is not a
 * manual run with a missing note, it *is* the schedule's run, and the cadence gate below applies to
 * it.
 *
 * **This command decides whether this is its minute, because the scheduler cannot.** `routes/console.php`
 * registers both entries `hourly()` on purpose: the schedule is built at boot, so
 * `backup.database_schedule` and `backup.database_time` are not available as a fluent call, and a
 * cadence nobody can change without a deploy is not a setting at all (§10.4). So the gate lives here
 * — and it is a **window**, not a minute comparison. An hourly entry fires at :00 and the configured
 * time is `02:30`; an equality test would skip every night for ever. Instead the window that has most
 * recently opened is resolved, and the run happens unless a successful archive of this type already
 * covers it. That is also what makes the entry idempotent for the day and self-healing across one:
 * a server that was down all night backs up at 09:00 rather than skipping the day, and an attempt
 * that **failed** does not count as covering its window, so the next hourly invocation tries again
 * rather than leaving a gap nobody notices until a restore.
 *
 * **`backup.enabled` gates the scheduled path and nothing else.** Its own help text is the contract:
 * off stops the scheduled jobs only, a manual backup still works — so this setting can never be the
 * reason there is no archive at all. Gating the manual path on it would make the one copy an
 * operator takes deliberately, mid-incident, the copy the system quietly declines to take.
 *
 * **`--type` defaults to `full`, not to `database`.** An under-specified backup errs towards more,
 * because `full` is the only type a restore can work from alone ({@see BackupType::Full}) and the
 * cost of the wrong default is discovered at the one moment nothing can be done about it. The
 * schedule always passes `--type` explicitly; the operator typing this in a hurry usually does not.
 *
 * **The archive password is never read by this file.** {@see BackupService} reads it once, hands it
 * to the zip library and scrubs it out of anything on its way to a column. That is why a failure
 * here prints `backup_runs.error_message` — the copy the service already scrubbed — rather than the
 * exception's own message: a zip library that echoed the password it was given would otherwise put
 * it on the console and into the cron mail that quotes the console.
 *
 * Everything the archive actually is belongs to {@see BackupService}. This adds the argument
 * handling, the cadence verdict, the operator table and the exit code: **0 taken or deliberately
 * skipped, 1 attempted and failed** (§6.6).
 */
final class BackupRun extends Command
{
    protected $signature = 'backup:run
                            {--type= : database, files or full — omitted means full}
                            {--reason= : Why this copy was taken. Its presence is what makes the run manual}';

    protected $description = 'Take a backup and record that it was taken. A reason makes it manual; without one it is the scheduled run.';

    /**
     * The cadence values of `backup.{database,files}_schedule` (§5.1).
     *
     * Mirrored here rather than shared, for the reason §3 gives for the retention classes: a cadence
     * is a policy parameter read from settings, not a domain status with a label and a colour, so it
     * is not an enum. `App\Support\SettingsRegistry` is the authority for the list — these constants
     * only have to agree with it, and `default:` below is what happens when they do not.
     */
    private const SCHEDULE_OFF = 'off';

    private const SCHEDULE_TWICE_DAILY = 'twice_daily';

    private const SCHEDULE_DAILY = 'daily';

    private const SCHEDULE_WEEKLY = 'weekly';

    private const SCHEDULE_MONTHLY = 'monthly';

    public function handle(BackupService $service): int
    {
        $type = $this->resolveType();

        if ($type === null) {
            return self::FAILURE;
        }

        $reason = $this->resolveReason();

        if ($reason === false) {
            return self::FAILURE;
        }

        // The one line that decides everything else. A reason means a person; no reason means the
        // schedule — see the class note.
        $trigger = $reason === null ? BackupTrigger::Scheduled : BackupTrigger::Manual;

        if ($trigger === BackupTrigger::Scheduled) {
            $skip = $this->scheduledSkipReason($type, $service);

            if ($skip !== null) {
                // Exit 0: a window that is already covered, or a cadence that is off, is the system
                // working. A non-zero exit here would page somebody every hour for the twenty-three
                // hours a day on which the daily backup correctly does nothing.
                $this->line('backup:run — skipped. '.$skip);

                return self::SUCCESS;
            }
        }

        $this->line(sprintf(
            'backup:run — %s archive, %s%s.',
            $type->value,
            $trigger->label(),
            $reason === null ? '' : ': '.$reason,
        ));

        // Captured before the call so the failure path can find the row this invocation opened. The
        // service holds a mutual-exclusion lock for the length of a run, so the newest failed row of
        // this type started at or after this moment is ours.
        $since = Carbon::now();

        try {
            // No actor, deliberately. The console is not a person: `created_by` is nullable for
            // exactly this case (§2.1, "null for scheduled runs"), and a console run that invented an
            // actor would attribute the archive to whichever account the resolver guessed. `reason` is
            // how a console run is attributed, which is the other half of why it is mandatory.
            $run = $service->run($type, $trigger, $reason, null);
        } catch (Throwable $exception) {
            return $this->reportFailure($type, $since, $exception);
        }

        $this->render($run);

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Arguments
    |--------------------------------------------------------------------------
    */

    /**
     * `--type`, or `full` when it was not given. Null means the value was not a type at all.
     */
    private function resolveType(): ?BackupType
    {
        $requested = $this->option('type');

        if ($requested === null || trim((string) $requested) === '') {
            // See the class note: more, not less, when nobody said.
            $this->line('  no --type given, taking a full archive — the only kind a restore can work from alone.');

            return BackupType::Full;
        }

        $type = BackupType::tryFrom(mb_strtolower(trim((string) $requested)));

        if ($type === null) {
            $this->error(sprintf(
                'Unknown backup type [%s]. Known types: %s.',
                (string) $requested,
                implode(', ', array_map(
                    static fn (BackupType $case): string => $case->value,
                    BackupType::cases(),
                )),
            ));

            return null;
        }

        return $type;
    }

    /**
     * The reason, `null` when none was given, `false` when what was given cannot be used.
     *
     * `--reason=""` is refused rather than treated as absent. The two mean opposite things — one is
     * "the schedule took this", the other is "a person took this and will not say why" — and
     * silently promoting the second into the first would record the manual copy as a scheduled one.
     */
    private function resolveReason(): string|false|null
    {
        $raw = $this->option('reason');

        if ($raw === null) {
            return null;
        }

        $reason = trim((string) $raw);

        if ($reason === '') {
            $this->error(
                '--reason was given but is empty. A manual backup needs a written reason; there is no '
                .'default for "why". Omit --reason entirely if this is the scheduled run.'
            );

            return false;
        }

        // `backup_runs.reason` is string(255) (§2.1). Refused here with a readable message rather
        // than left to the database: a too-long reason would abort the whole run at the INSERT, and
        // losing the night's archive to a long sentence is the wrong trade.
        if (mb_strlen($reason) > 255) {
            $this->error(sprintf(
                'The reason is %d characters and backup_runs.reason holds 255. Shorten it — the row is '
                .'a note, and the detail belongs in the ticket it refers to.',
                mb_strlen($reason),
            ));

            return false;
        }

        return $reason;
    }

    /*
    |--------------------------------------------------------------------------
    | The cadence gate (scheduled runs only)
    |--------------------------------------------------------------------------
    */

    /**
     * Why this scheduled invocation should do nothing, or null when it should run.
     */
    private function scheduledSkipReason(BackupType $type, BackupService $service): ?string
    {
        if (! (bool) setting('backup.enabled', true)) {
            return 'backup.enabled is off, which stops the scheduled runs only — '
                .'php artisan backup:run --reason="..." still takes one.';
        }

        // Asked before the lock refuses us, and only on the scheduled path. The service's lock is
        // still the thing that prevents two dumps — this check only decides whether an invocation
        // that arrives while another archive is being written exits 0 and lets the next hourly one
        // pick the window up, or exits 1 and mails an operator about a collision the schedule
        // creates by design (the database and files entries share a 03:00 boundary on the defaults).
        if ($service->isRunning()) {
            return 'another backup is still running. This window is not marked covered, so the next '
                .'hourly invocation will take it.';
        }

        if ($type === BackupType::Full) {
            // §5.1 declares a cadence for `database` and `files` and none for `full`, so there is no
            // window to be outside of. Whoever registered a scheduled full archive chose its cadence
            // in the schedule, and inventing one here would silently override them.
            return null;
        }

        $prefix = $type === BackupType::Database ? 'database' : 'files';
        $schedule = mb_strtolower(trim((string) setting('backup.'.$prefix.'_schedule', $prefix === 'database' ? 'daily' : 'weekly')));

        if ($schedule === self::SCHEDULE_OFF) {
            return sprintf('backup.%s_schedule is "%s".', $prefix, self::SCHEDULE_OFF);
        }

        $time = trim((string) setting('backup.'.$prefix.'_time', $prefix === 'database' ? '02:30' : '03:00'));
        $clock = $this->parseClock($time);

        if ($clock === null) {
            // A malformed time field is not permission to stop backing up. The archive is taken and
            // the operator is told which setting to fix.
            $this->warn(sprintf(
                'backup.%s_time is "%s", which is not HH:MM. Taking the archive now and treating every '
                .'invocation as due until the setting is fixed.',
                $prefix,
                $time,
            ));

            return null;
        }

        $now = Carbon::now();
        $candidates = $this->windowCandidates($schedule, $clock[0], $clock[1], $now);

        if ($candidates === null) {
            $this->warn(sprintf(
                'backup.%s_schedule is "%s", which this command does not know. Taking the archive now '
                .'rather than skipping a cadence it cannot read.',
                $prefix,
                $schedule,
            ));

            return null;
        }

        $window = $this->mostRecent($candidates, $now);

        if ($window === null) {
            // Unreachable by construction — every cadence offers a candidate a full period in the
            // past, which is exactly why each list starts one period back. Kept as its own branch,
            // and deliberately *not* folded back into the unknown-cadence warning above, because
            // the two were once the same `return null` and that is what hid the twice-daily defect:
            // a candidate list whose earliest entry was still in the future reported itself as an
            // unreadable cadence, and an operator sent to inspect the word "twice_daily" for a typo
            // is an operator looking at the one thing that was not wrong.
            $this->warn(sprintf(
                'No backup.%s_schedule window has opened yet. Taking the archive now rather than '
                .'skipping one this command cannot place.',
                $prefix,
            ));

            return null;
        }

        if ($this->windowIsCovered($type, $window)) {
            return sprintf(
                'the %s window that opened %s already holds a %s archive.',
                $schedule,
                app_datetime($window),
                $type->value,
            );
        }

        return null;
    }

    /**
     * `HH:MM` as [hour, minute], or null when it is not that.
     *
     * @return array{0: int, 1: int}|null
     */
    private function parseClock(string $time): ?array
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches) !== 1) {
            return null;
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /**
     * Every scheduled occurrence near `now` for a cadence this command knows — null when it does not.
     *
     * Null means *run*, never *skip*, which is why **`off` never reaches here**: the caller answers
     * `off` first, and for `off` this answer would be exactly backwards.
     *
     * **Each list begins a full period before the current one, and that is load-bearing.**
     * {@see mostRecent()} wants the latest occurrence at or before now, so a list whose earliest
     * entry can itself still be in the future has no answer at all for the hours before the day's
     * first window. `twice_daily` at 14:30 is the case that proves it: the occurrences are 02:30 and
     * 14:30, and a list built as [yesterday+12h, today, today+12h] is [today 02:30, today 14:30,
     * tomorrow 02:30] — every entry in the future at 01:00. That produced no window, the caller read
     * no window as an unreadable cadence, and the command took an unscheduled database dump on every
     * hourly invocation from midnight until 02:30 while warning that "twice_daily" was a cadence it
     * did not know. Three extra dumps a night, a false alarm about the one setting that was correct,
     * and a retention ladder fed archives the operator never asked for.
     *
     * @return list<Carbon>|null
     */
    private function windowCandidates(string $schedule, int $hour, int $minute, Carbon $now): ?array
    {
        $today = $now->copy()->setTime($hour, $minute);

        return match ($schedule) {
            self::SCHEDULE_TWICE_DAILY => [
                // Two windows a day, twelve hours apart. The second is derived rather than
                // configured because §5.1 declares one time field per type: an operator who picks
                // 02:30 and "twice a day" means 02:30 and 14:30, and asking them for a second time
                // field would be asking them to re-state the same decision.
                //
                // Both of yesterday's occurrences are listed, not just the later one. Which of the
                // two is the most recent past window depends on the configured hour — before 12:00
                // it is yesterday's +12h occurrence, at or after 12:00 it is yesterday's base one —
                // and listing both lets `mostRecent()` decide instead of this arm guessing.
                $today->copy()->subDay(),
                $today->copy()->subDay()->addHours(12),
                $today->copy(),
                $today->copy()->addHours(12),
            ],
            self::SCHEDULE_DAILY => [
                $today->copy()->subDay(),
                $today->copy(),
            ],
            // Sunday, not "seven days since the last one". `BackupRetentionService::classify()`
            // stamps `weekly` on a Sunday archive and `daily` on every other day, so a weekly
            // cadence that drifted onto a Tuesday would write archives the prune removes on the
            // daily window — eleven weeks earlier than the policy an operator read in the settings.
            self::SCHEDULE_WEEKLY => [
                $today->copy()->startOfWeek(Carbon::SUNDAY)->setTime($hour, $minute)->subWeek(),
                $today->copy()->startOfWeek(Carbon::SUNDAY)->setTime($hour, $minute),
            ],
            // The first of the month, for the same reason weekly is Sunday: `classify()` stamps
            // `monthly` on day 1 and nowhere else.
            self::SCHEDULE_MONTHLY => [
                $today->copy()->startOfMonth()->setTime($hour, $minute)->subMonthNoOverflow(),
                $today->copy()->startOfMonth()->setTime($hour, $minute),
            ],
            default => null,
        };
    }

    /**
     * The latest occurrence at or before `$now` — the moment the current window opened.
     *
     * Separate from {@see windowCandidates()} so that "this cadence is not one I know" and "none of
     * this cadence's occurrences has happened yet" stay two different answers with two different
     * messages. See the note on `windowCandidates()` for what happened when they were one.
     *
     * @param  list<Carbon>  $candidates
     */
    private function mostRecent(array $candidates, Carbon $now): ?Carbon
    {
        $start = null;

        foreach ($candidates as $candidate) {
            if ($candidate->greaterThan($now)) {
                continue;
            }

            if ($start === null || $candidate->greaterThan($start)) {
                $start = $candidate;
            }
        }

        return $start;
    }

    /**
     * Has a backup of this type already succeeded inside this window?
     *
     * **A failed run deliberately does not cover its window.** A backup that did not work is not a
     * backup, and the next hourly invocation retrying is the difference between a transient failure
     * at 03:00 and a day with no archive. The notification per failure is `backup.notify_on_failure`,
     * which is readonly true — an operator whose backups fail every hour has an emergency, and that
     * is the correct volume of mail for one.
     *
     * `Pruned` counts alongside `Completed`: retention removed the file by policy and the row still
     * records that the backup happened. Excluding it would have this command take a second archive
     * of a window that was covered, against the policy that had just swept the first.
     *
     * `COALESCE(finished_at, started_at, created_at)` is the same ordering key
     * `BackupRetentionService` uses, for the same reason — a row exists before the dump finishes.
     */
    private function windowIsCovered(BackupType $type, Carbon $window): bool
    {
        return BackupRunRecord::query()
            ->ofType($type)
            ->whereIn('status', [BackupStatus::Completed->value, BackupStatus::Pruned->value])
            ->whereRaw('COALESCE(finished_at, started_at, created_at) >= ?', [$window])
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    */

    /**
     * The table an operator can act on: what was backed up, where, how big, how long, the checksum.
     */
    private function render(BackupRunRecord $run): void
    {
        $rows = [
            ['archive', $run->filename ?? '(none)'],
            ['disk · path', $run->disk.' · '.($run->path ?? '(none)')],
            ['size', $run->size_bytes === null ? '—' : RetentionPlan::humanBytes((string) $run->size_bytes)],
            ['took', $run->duration_seconds === null ? '—' : $run->duration_seconds.'s'],
            // The short form only. The full 64 characters are on the row and in the activity log for
            // whoever is comparing two archives; twelve is enough to read one out over a phone.
            ['checksum', $run->checksum_sha256 === null ? '—' : mb_substr($run->checksum_sha256, 0, 12).'… (sha256)'],
            ['contents', $this->contents($run)],
            // What the archive is, never what it was locked with: `backup.archive_password` is not
            // read by this file at all, which is the point.
            ['encrypted', ($run->is_encrypted ? 'yes' : 'no').($run->includes_env ? ' · .env inside' : '')],
            ['retention', $run->retention_class.($run->retention_until === null ? '' : ', until '.app_date($run->retention_until))],
            ['verification', $run->verification_status->label()],
        ];

        $this->newLine();
        $this->table(['', 'run '.$run->uuid], $rows);
        $this->newLine();

        $this->info('backup:run — '.$run->summary());

        if ($run->reason !== null) {
            $this->line('  reason: '.$run->reason);
        }

        // HD-6, printed at the one moment somebody is looking: an archive nobody has restored is a
        // hypothesis, and §6.13 does not count it as a backup until it reaches `restore_ok`.
        if (! $run->verification_status->satisfiesGoLive()) {
            $this->line(sprintf(
                '  <fg=yellow>not yet proved</> → php artisan backup:verify --backup=%s --deep',
                $run->uuid,
            ));
        }
    }

    private function contents(BackupRunRecord $run): string
    {
        $parts = [];

        if ($run->table_count !== null) {
            $parts[] = app_number($run->table_count).' tables';
        }

        if ($run->row_count_total !== null) {
            $parts[] = app_number($run->row_count_total).' rows';
        }

        if ($run->file_count !== null) {
            $parts[] = app_number($run->file_count).' files';
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    /**
     * Report a failed attempt from the row the service closed, not from the exception.
     *
     * The row's `error_message` has been through the service's scrubber; the exception's has not —
     * see the class note. When there is no row the throw happened before the run was opened, which
     * is one of three refusals whose messages this command and the service both write and neither
     * interpolates a secret into (a missing reason, a held lock, an encryption setting that
     * contradicts itself). The moment that stops being true, this branch prints the class alone.
     */
    private function reportFailure(BackupType $type, Carbon $since, Throwable $exception): int
    {
        $run = BackupRunRecord::query()
            ->ofType($type)
            ->where('status', BackupStatus::Failed->value)
            ->whereRaw('COALESCE(started_at, created_at) >= ?', [$since])
            ->orderByDesc('id')
            ->first();

        $this->newLine();

        if ($run === null) {
            $this->error('backup:run — nothing was attempted. '.$exception->getMessage());
            $this->line('  ('.$exception::class.')');

            return self::FAILURE;
        }

        $this->table(['', 'run '.$run->uuid], [
            ['type · trigger', $run->type->value.' · '.$run->trigger->value],
            ['started', app_datetime($run->started_at)],
            ['failed after', $run->duration_seconds === null ? '—' : $run->duration_seconds.'s'],
            ['exception', (string) $run->error_class],
            ['message', mb_substr((string) $run->error_message, 0, 600)],
        ]);

        $this->newLine();
        $this->error(sprintf(
            'backup:run — failed, and recorded. Run %s is the evidence: a night with no row at all is a '
            .'night nobody knows about.',
            $run->uuid,
        ));

        return self::FAILURE;
    }
}
