<?php

declare(strict_types=1);

namespace App\Policies\Ops;

use App\Models\Ops\BackupRun;
use App\Models\User;
use App\Services\Ops\BackupRetentionService;
use Throwable;

/**
 * Who may read a restore point, take one, stream one, and overwrite a database with one
 * (phase-24-25 section 4.2, section 4.3, section 6.10.3, section 6.10.5).
 *
 * **Every dangerous answer on this screen is decided here, not in the Blade.** A disabled button is a
 * suggestion; a policy is the rule. Three of the answers below are not "does this person hold a
 * permission" at all — they are facts about the archive, and they are the ones that matter:
 *
 * 1.  **{@see delete()} refuses the newest usable database archive** (section 6.10.3 rule 2) and
 *     refuses any prune that would drop the number of usable database archives under
 *     `backup.retention_min_copies` (rule 1). A retention policy that can be talked into deleting the
 *     only copy is not a retention policy, and "the operator clicked it" is exactly how it gets talked
 *     into it. It also refuses a `pre_restore` / `pre_deploy` archive (rule 3): that file is the way
 *     back from the act it was taken for.
 * 2.  **{@see download()} and {@see restore()} refuse an archive whose file is gone.** A pruned row
 *     keeps its checksum, its size and its proof counts for ever (section 2.1), so it reads as a
 *     perfectly good backup to anything that only looks at `status`. {@see BackupRun::isUsable()} is
 *     the question both of them actually mean.
 * 3.  **{@see restore()} refuses an archive whose checksum has never been proved** (section 8.3 step
 *     1) and refuses a files-only archive, which holds no database to restore.
 *
 * **Super Admin only, said in permissions rather than in a role name.** `backups.restore` is granted
 * to Super Admin and to nobody else, and Phase 1 section 5 excludes **all** of `backups.*` from Admin —
 * an exclusion phase-24-25 section 4.3 restates rather than relaxes. So these screens are Super
 * Admin's in practice while this file never spells a role out (`CLAUDE.md` section 1.8): change the
 * grant and the answer changes with it, which is the point of keeping roles in the database.
 *
 * **This policy is asked directly, never through `Gate`, and that is the most important line in this
 * file.** `Gate::before` allows a Super Admin everything *before* a policy is consulted (`CLAUDE.md`
 * section 4), so `Gate::allows('delete', $run)` answers "yes, prune the only copy of the database" to
 * exactly the person most able to act on it — the methods below never run. But section 6.10.3's rules
 * are not permissions: they are facts about the archive, and they bind everybody. So `BackupController`
 * resolves this class and calls it, and every question about a backup has one answer and one path.
 *
 * Laravel's policy discovery maps `App\Models\Ops\BackupRun` onto this class by name, so the `Gate`
 * route exists whether it is registered in `AppServiceProvider::POLICIES` or not. It is therefore not
 * enough to leave it unregistered — **no caller may ask these questions through the Gate at all**, and
 * a test that means to assert rule 1 or rule 2 has to resolve this class the way the controller does.
 * The permission half is the one thing that legitimately goes through the Gate, inside
 * {@see holds()}, precisely so that Super Admin and the module gate both apply to it.
 *
 * **`view_logs` is separate from `view` because they are different disclosures.** `view` is the
 * register: when, how big, whether it verified. `view_logs` is the raw dump and prune output, which
 * names tables, paths and the database — a map of the installation. The controller sends the log text
 * to the view only when this returns true, rather than sending it and wrapping it in a `@can`
 * (section 4.2).
 *
 * **Nothing here ever authorises a delete of the row.** `backups.delete` prunes a **file**;
 * {@see forceDelete()} and the row itself are refused by the model's `deleting` hook and by
 * `trg_br_no_delete` underneath it (D19, section 6.10.3 rule 4). See the note on {@see forceDelete()}
 * for why a `false` here is advisory and where the real guarantee lives.
 */
final class BackupRunPolicy
{
    /**
     * Memoised so a paginated register asks the database once rather than once per row.
     *
     * The register renders up to a hundred rows and calls {@see delete()} on each of them; the floor
     * rule needs "how many usable database archives are there, and which is the newest", which is one
     * query for the page rather than two hundred. A policy instance lives for one request (the Gate
     * resolves it through the container and keeps it), so the memo can never be stale in a way that
     * matters: a backup finishing mid-request does not retroactively make a prune safe.
     *
     * @var list<int>|null
     */
    private ?array $usableDatabaseArchiveIds = null;

    public function __construct(
        private readonly BackupRetentionService $retention,
    ) {}

    /**
     * The register.
     */
    public function viewAny(User $user): bool
    {
        return $this->holds($user, 'backups.view_any');
    }

    /**
     * One backup's evidence page.
     *
     * No row-level narrowing: a backup belongs to the installation, not to the person who took it, and
     * there is no tenant dimension to scope by. Whoever may read the register may read any row in it.
     */
    public function view(User $user, BackupRun $run): bool
    {
        return $this->holds($user, 'backups.view');
    }

    /**
     * Take a manual backup.
     *
     * The route also carries `throttle:backup-run` (two an hour, section 6.3.1) and the Form Request
     * demands a written reason, because a manual backup is a claim about a moment — "before I changed
     * the fee structure" — and a claim with no reason on it is a file with a date.
     */
    public function create(User $user): bool
    {
        return $this->holds($user, 'backups.create');
    }

    /**
     * Verify an archive now (checksum, and that it opens as a zip).
     *
     * Deliberately `backups.create` rather than an ability of its own: section 4.2 declares no
     * `backups.verify`, and inventing one here would put a permission name outside
     * `PermissionRegistry` (D4). Verifying writes only `verification_status`, `verified_at` and
     * `verification_notes` — it is the same "may you make a backup happen" authority.
     *
     * The archive has to still be there. Verifying a pruned row would record a verdict about a file
     * nobody can produce.
     */
    public function verify(User $user, BackupRun $run): bool
    {
        return $this->holds($user, 'backups.create') && $run->isUsable();
    }

    /**
     * Stream the archive.
     *
     * **The signature on the URL is not the authorization.** Section 7.1 puts `signed` on this route
     * with a five-minute expiry so a copied link stops working, and `auth` + `can:backups.download` +
     * this policy all still run on the request that follows it — the archive lives on the private
     * `backups` disk and is served by a controller that re-runs the whole chain, never by a framework
     * route or a storage URL (D21).
     */
    public function download(User $user, BackupRun $run): bool
    {
        return $this->holds($user, 'backups.download')
            && $run->isUsable()
            && $run->path !== null;
    }

    /**
     * Prune this archive's **file**, ahead of its retention date.
     *
     * Everything after the permission check is section 6.10.3's hard rules, and each `false` here is a
     * rule that exists because somebody could otherwise delete the last copy of the database with two
     * clicks and a good reason. See the class note.
     */
    public function delete(User $user, BackupRun $run): bool
    {
        if (! $this->holds($user, 'backups.delete')) {
            return false;
        }

        // Nothing to prune: the file is already gone, or there never was one (a failed run that died
        // before it wrote anything). Both are `true` for `file_pruned_at` purposes and neither is an
        // error worth a confirm dialog.
        if ($run->path === null || $run->file_pruned_at !== null) {
            return false;
        }

        // Rule 3. A pre-restore or pre-deploy copy is the way back from the act it was taken for.
        if ($run->isProtectedFromPruning()) {
            return false;
        }

        /*
        | Rules 2 and 1 need one fact: the usable database archives, newest first. When that lookup
        | fails there is no safe answer, so there is no answer — a prune is refused rather than
        | allowed by a question nobody could ask. "The system could not work out whether this is your
        | last copy" is never a reason to delete it.
        */
        $ids = $this->usableDatabaseArchiveIds();

        if ($ids === null) {
            return false;
        }

        // Rule 2 first: the newest usable database archive is never prunable whatever the floor says.
        // Then rule 1, the floor, which guards the rest.
        return ! $this->isNewestUsableDatabaseArchive($run, $ids)
            && ! $this->wouldBreachMinimumCopies($run, $ids);
    }

    /**
     * Read the raw dump / prune / verification output for this run.
     *
     * A different disclosure from {@see view()} — see the class note.
     */
    public function viewLogs(User $user, BackupRun $run): bool
    {
        return $this->holds($user, 'backups.view_logs');
    }

    /**
     * Restore from this archive — the most consequential act an operator can perform.
     *
     * The permission is gate one of section 6.10.5's four. The other three are not this file's to
     * answer and are not skippable anywhere: `password.confirm` middleware on both the form and the
     * submit, the typed confirmation phrase compared case-sensitively in `RestoreBackupRequest`, and a
     * written reason of at least twenty characters. What this method adds is the archive's side of the
     * question — see the class note for why each of the three refusals below is here rather than in
     * the wizard's markup.
     */
    public function restore(User $user, BackupRun $run): bool
    {
        if (! $this->holds($user, 'backups.restore')) {
            return false;
        }

        // The file has to exist, and it has to contain a database. A `files` archive restores
        // documents, not rows, and the restore procedure of section 6.10.5 is entirely about rows.
        if (! $run->isUsable() || ! $run->type->includesDatabase()) {
            return false;
        }

        /*
        | Section 8.3 step 1: the wizard refuses to continue while the archive's checksum is
        | unverified. Recorded verification is the gate to *start* — the executing service re-hashes
        | the bytes immediately before it writes anything (section 6.10.5 step 2), because a stamp
        | that says the bytes were checked must mean they were checked now, not that a row remembers
        | a check from Tuesday.
        */
        return $run->verification_status->checksumProved();
    }

    /**
     * Whether this person may reach the restore wizard at all, with no archive in hand yet.
     *
     * Used for the register's red Restore affordance and for the "four gates" tooltip. A separate
     * question from {@see restore()}: this one is about the person, that one is about the archive.
     */
    public function restoreAny(User $user): bool
    {
        return $this->holds($user, 'backups.restore');
    }

    /*
    |--------------------------------------------------------------------------
    | The writes that do not exist
    |--------------------------------------------------------------------------
    */

    /**
     * A backup run is never edited by a person.
     *
     * Its lifecycle columns move — `status`, the checksum, the verification verdict — and every one of
     * those moves is written by a service as the result of something that happened. There is no screen
     * on which an operator corrects a backup's record, because the record is the evidence.
     */
    public function update(User $user, BackupRun $run): bool
    {
        return false;
    }

    /**
     * The row is never deleted (D19, section 2.1, section 6.10.3 rule 4).
     *
     * This `false` is advisory and nothing more: `Gate::before` allows Super Admin everything before a
     * policy is consulted, so it would not stop the one person most able to try. The guarantee lives
     * in `BackupRun`'s `deleting` hook and in the `BEFORE DELETE` trigger below it. Stated here so a
     * reader of this file is not left thinking deletion was simply never considered — and so nobody
     * reads `backups.delete` as a row delete: that ability prunes a file and is {@see delete()}.
     */
    public function forceDelete(User $user, BackupRun $run): bool
    {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | The archive facts the rules are built from
    |--------------------------------------------------------------------------
    */

    /**
     * Is this the newest usable database archive? (Section 6.10.3 rule 2.)
     *
     * @param  list<int>  $ids  usable database archives, newest first
     */
    private function isNewestUsableDatabaseArchive(BackupRun $run, array $ids): bool
    {
        return ($ids[0] ?? null) === (int) $run->getKey();
    }

    /**
     * Would pruning this file leave fewer usable database archives than the floor allows?
     * (Section 6.10.3 rule 1.)
     *
     * A `files` archive is not in the list and cannot breach the floor: the floor is about copies of
     * the database. The floor value comes from the same setting the scheduled prune reads, so a manual
     * early prune and the nightly job cannot disagree about what "enough copies" means.
     *
     * @param  list<int>  $ids  usable database archives, newest first
     */
    private function wouldBreachMinimumCopies(BackupRun $run, array $ids): bool
    {
        if (! in_array((int) $run->getKey(), $ids, true)) {
            return false;
        }

        return (count($ids) - 1) < $this->minimumCopies();
    }

    /**
     * Usable database archives that still have a file, newest first, as ids — or **null** when the
     * question could not be answered.
     *
     * Asked through {@see BackupRetentionService::usableDatabaseArchives()} rather than rebuilt here,
     * so "usable database archive" has exactly one definition in the system: the one the nightly
     * prune, the go-live gate and this policy all share. Memoised per request (see the property note).
     *
     * Null on failure rather than an empty list, and the difference is load-bearing: an empty list
     * reads as "there are no copies to protect", which would make every prune look safe. The caller
     * turns null into a refusal.
     *
     * @return list<int>|null
     */
    private function usableDatabaseArchiveIds(): ?array
    {
        if ($this->usableDatabaseArchiveIds !== null) {
            return $this->usableDatabaseArchiveIds;
        }

        try {
            $this->usableDatabaseArchiveIds = $this->retention
                ->usableDatabaseArchives()
                ->map(static fn (BackupRun $run): int => (int) $run->getKey())
                ->values()
                ->all();
        } catch (Throwable) {
            // No table yet, a disk that would not answer, a mid-migration state. Left unmemoised so
            // a transient failure does not poison the rest of the request.
            return null;
        }

        return $this->usableDatabaseArchiveIds;
    }

    /**
     * `backup.retention_min_copies`, floored at one.
     *
     * A malformed or zero setting must not read as "no floor": the whole point of rule 1 is that a
     * number somebody typed cannot authorise the system into having no copies.
     */
    private function minimumCopies(): int
    {
        $configured = setting('backup.retention_min_copies', 3);

        return is_numeric($configured) ? max(1, (int) $configured) : 3;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * One permission, asked through the Gate so the module gate and Super Admin both apply.
     *
     * `backups` is a Phase 1 core module (`is_core = true`), so the module half of `Gate::before`
     * never denies it — the screens cannot be hidden by switching a module off, which is deliberate:
     * the register is how somebody finds out whether there is a way back.
     */
    private function holds(User $user, string $permission): bool
    {
        try {
            return $user->can($permission);
        } catch (Throwable) {
            return false;
        }
    }
}
