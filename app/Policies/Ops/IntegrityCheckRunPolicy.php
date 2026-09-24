<?php

declare(strict_types=1);

namespace App\Policies\Ops;

use App\Enums\IntegrityCheckSuite;
use App\Models\Ops\IntegrityCheckRun;
use App\Models\User;
use Throwable;

/**
 * Who may read a proof, and which proofs they may read (phase-24-25 §9, §8.6).
 *
 * **The interesting rule here is not "may you read integrity checks" — it is "which suites are
 * yours".** §9 narrows the Accountant to the two financial suites (`constraints`, `wallet`),
 * because that is the evidence behind §120 and the rest is a map of the database: a `routes` run
 * names every unguarded endpoint, a `schema` run names every index, an `isolation` run names the
 * panels. Those are operations facts, and handing them to everyone who may read a wallet proof
 * widens an audit right into a reconnaissance one.
 *
 * **The narrowing is a query scope before it is a policy answer.** `visibleSuites()` exists so the
 * controller can put the restriction in the `WHERE` clause; `viewSuite()` exists so a record that
 * arrives by any other path is still refused. Hiding a row in Blade is not isolation
 * (`CLAUDE.md` §1.10) — a filtered view over an unfiltered query leaks through pagination counts,
 * sort order, an export and a direct URL, and each of those is a different person's bug.
 *
 * **A refused suite is a 404, not a 403** (§9). That decision lives in the controller, because a
 * policy can only say yes or no; what it means here is that `viewSuite()` is asked *first* and its
 * `false` becomes "no such run" rather than "not yours". A 403 would confirm that a `security` run
 * exists, which is the one fact the narrowing is meant to withhold.
 *
 * **The discriminator is a permission, never a role name** (`CLAUDE.md` §1.8). Whoever may read
 * the system health screens is an operations reader and sees every suite; whoever holds only
 * `integrity_checks.*` is a financial-evidence reader and sees the financial suites. That matches
 * the seeded grants exactly — Admin receives `system_health.*` whole, the Accountant receives none
 * of it (`RoleSeeder`, phase-24-25 §4.3) — without this file ever spelling a role out.
 */
final class IntegrityCheckRunPolicy
{
    /**
     * Holding this marks somebody as an operations reader rather than an evidence reader.
     *
     * Deliberately a `system_health` ability and not an `integrity_checks` one: Admin and the
     * Accountant hold the *same* three abilities on `integrity_checks`, so nothing on this module
     * can tell them apart. What separates them is whether operations screens are theirs at all.
     */
    private const OPERATIONS_PERMISSION = 'system_health.view_any';

    /**
     * The register. The rows it may contain are decided by {@see self::visibleSuites()}, not here.
     */
    public function viewAny(User $user): bool
    {
        return $this->holds($user, 'integrity_checks.view_any');
    }

    /**
     * One run's verdict and counts.
     *
     * Both halves are required: the ability, and the suite being one this person may see. The
     * controller asks about the suite first so the refusal is a 404 (see the class note); this
     * repeats the check because a second caller — an export, a notification, a future screen —
     * must not depend on that controller having remembered to.
     */
    public function view(User $user, IntegrityCheckRun $run): bool
    {
        return $this->holds($user, 'integrity_checks.view')
            && $this->viewSuite($user, $run->suite);
    }

    /**
     * Starting a run.
     *
     * Withheld from Admin on purpose (§4.3): a run is evidence, and whoever can produce evidence on
     * demand can also produce it until it says what they want. Super Admin and the scheduler.
     */
    public function create(User $user): bool
    {
        return $this->holds($user, 'integrity_checks.create');
    }

    /**
     * The raw findings — the rows naming tables, columns, routes and ids.
     *
     * Separate from `view` because they answer a different question: `view` is "did the wallet
     * reconcile", `view_logs` is "here is the shape of the database it reconciled against"
     * (§4.1). Admin holds the first and not the second, and the controller passes `findings` to the
     * view only when this returns true — never a `@can` around a variable that was already sent.
     */
    public function viewFindings(User $user, IntegrityCheckRun $run): bool
    {
        return $this->holds($user, 'integrity_checks.view_logs')
            && $this->viewSuite($user, $run->suite);
    }

    /**
     * Exporting the register (class-level: `Gate::allows('exportAny', IntegrityCheckRun::class)`).
     */
    public function exportAny(User $user): bool
    {
        return $this->holds($user, 'integrity_checks.export');
    }

    /**
     * Exporting one run's evidence.
     */
    public function export(User $user, IntegrityCheckRun $run): bool
    {
        return $this->holds($user, 'integrity_checks.export')
            && $this->viewSuite($user, $run->suite);
    }

    /*
    |--------------------------------------------------------------------------
    | Suite visibility — the scope, and the answer the scope is built from
    |--------------------------------------------------------------------------
    */

    /**
     * May this person see runs of this suite at all?
     */
    public function viewSuite(User $user, IntegrityCheckSuite $suite): bool
    {
        if ($this->seesEverySuite($user)) {
            return true;
        }

        return $suite->isFinancial();
    }

    /**
     * The suites this person's queries may return — the `whereIn` the controller applies.
     *
     * Returned as a list of enum cases rather than strings so a caller cannot accidentally build a
     * `WHERE suite IN (...)` out of something that is not a suite.
     *
     * @return list<IntegrityCheckSuite>
     */
    public function visibleSuites(User $user): array
    {
        if ($this->seesEverySuite($user)) {
            return IntegrityCheckSuite::cases();
        }

        return array_values(array_filter(
            IntegrityCheckSuite::cases(),
            static fn (IntegrityCheckSuite $suite): bool => $suite->isFinancial(),
        ));
    }

    /**
     * Is this an operations reader?
     *
     * Super Admin always is. For everybody else the question is whether the operations permission
     * is **assigned**, which is not the same as whether `can()` would allow it right now:
     * `Gate::before` denies every ability of a disabled module, so an Admin would silently collapse
     * to the financial suites the moment somebody switched `system_health` off. That would be a
     * privilege change caused by an unrelated toggle, in a screen about integrity. The module gate
     * still 403s the `system_health` routes themselves — it just does not get to decide *this*.
     */
    public function seesEverySuite(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        try {
            // spatie's assignment check, deliberately not $user->can() — see above.
            return $user->hasPermissionTo(self::OPERATIONS_PERMISSION);
        } catch (Throwable) {
            // The permission row does not exist (fresh install, mid-migration, a trimmed seed).
            // Absent evidence of an operations grant, the narrow answer is the safe one.
            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The writes that do not exist
    |--------------------------------------------------------------------------
    */

    /**
     * A run is never edited. It is a statement about a moment that has passed.
     */
    public function update(User $user, IntegrityCheckRun $run): bool
    {
        return false;
    }

    /**
     * A run is never deleted (D19, §2.3).
     *
     * This `false` is advisory and nothing more: `Gate::before` allows Super Admin everything
     * before a policy is consulted, so it would not stop the one person most able to try. The
     * guarantee lives in the model's `deleting` hook and in the `BEFORE DELETE` trigger below it —
     * evidence somebody can delete after reading it is not evidence. Stated here so a reader of
     * this file is not left thinking deletion was simply never considered.
     */
    public function delete(User $user, IntegrityCheckRun $run): bool
    {
        return false;
    }

    public function restore(User $user, IntegrityCheckRun $run): bool
    {
        return false;
    }

    public function forceDelete(User $user, IntegrityCheckRun $run): bool
    {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * One permission, asked through the Gate so the module gate and Super Admin both apply.
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
