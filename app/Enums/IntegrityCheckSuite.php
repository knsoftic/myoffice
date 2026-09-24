<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * One family of proofs the system can run against itself (phase-24-25 §3, §110).
 *
 * **Each case names a command, and that is the whole design.** `IntegrityCheckService` does not
 * implement nine kinds of verification; it invokes nine commands that already exist or that Phase 24
 * ships, records what they said, and writes one `integrity_check_runs` row per suite. A suite that
 * re-implemented a check the spine already owns would be a second answer to "is the money intact" —
 * and the two would differ exactly when one of them was right.
 *
 * **`isFinancial()` is not a label, it is a gate.** HD-10 schedules these to run for ever, and
 * {@see IntegrityCheckStatus::blocksGoLive()} treats a *warning* on a financial suite as blocking
 * while a warning elsewhere is not. A drifting wallet is never "probably fine": either the ledger
 * re-derives the balance or somebody's money is wrong.
 */
enum IntegrityCheckSuite: string
{
    use HasOptions;

    /** The spine's own CHECK / trigger / FK verifier over the fifteen financial tables. */
    case Constraints = 'constraints';

    /** Every wallet re-derived from the ledger. Read-only here — `--repair` is never passed. */
    case Wallet = 'wallet';

    /** Indexes, foreign keys and the append-only triggers, against `index-manifest.php`. */
    case Schema = 'schema';

    /** Every route accounted for in `route-guard-manifest.php`, with a rationale where unguarded. */
    case Routes = 'routes';

    /** The five-panel matrix: no panel can read another tenant's rows. */
    case Isolation = 'isolation';

    /** Every upload endpoint's disk, MIME list and size cap, against `upload-manifest.php`. */
    case Uploads = 'uploads';

    /** Query budgets and the pagination scan. */
    case Performance = 'performance';

    /** The static and runtime security audit. */
    case Security = 'security';

    /** Checksum, and the restore-into-scratch proof (HD-6). */
    case Backup = 'backup';

    public function label(): string
    {
        return match ($this) {
            self::Constraints => 'Financial constraints',
            self::Wallet => 'Wallet reconciliation',
            self::Schema => 'Schema and indexes',
            self::Routes => 'Route guards',
            self::Isolation => 'Panel isolation',
            self::Uploads => 'Upload safety',
            self::Performance => 'Query budgets',
            self::Security => 'Security audit',
            self::Backup => 'Backup verification',
        };
    }

    /**
     * Rose for the two that are about money, amber for the two about who can see what, slate for
     * the rest. The colour is how somebody scanning a list of runs finds the one that matters.
     */
    public function color(): string
    {
        return match ($this) {
            self::Constraints, self::Wallet => 'rose',
            self::Isolation, self::Security => 'amber',
            self::Routes, self::Uploads => 'sky',
            self::Schema, self::Performance, self::Backup => 'slate',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Constraints => 'Every CHECK, trigger and foreign key on the financial tables still refuses what it was written to refuse.',
            self::Wallet => 'Every collaborator wallet still equals the sum of its own ledger.',
            self::Schema => 'Every index the manifest requires exists, and every foreign key has one.',
            self::Routes => 'Every route either carries a permission or has a written reason not to.',
            self::Isolation => 'No panel can read another tenant\'s rows.',
            self::Uploads => 'Every upload endpoint knows its disk, its types and its ceiling.',
            self::Performance => 'Every screen stays inside its query budget.',
            self::Security => 'Headers, secrets, debug flags, dependencies and the CSRF surface.',
            self::Backup => 'The latest backup is intact, and has been restored somewhere to prove it.',
        };
    }

    /**
     * The command that implements this suite.
     *
     * Two of them are not Phase 24's: `constraints` is the spine's and `wallet` is Phase 12's.
     * Naming them here rather than reimplementing them is the point — see the class note.
     */
    public function command(): string
    {
        return match ($this) {
            self::Constraints => 'financial:verify-constraints',
            self::Wallet => 'collaborators:reconcile-wallets',
            self::Schema => 'audit:manifest',
            self::Routes => 'security:route-manifest',
            self::Isolation => 'security:audit',
            self::Uploads => 'audit:manifest',
            self::Performance => 'perf:budget',
            self::Security => 'security:audit',
            self::Backup => 'backup:verify',
        };
    }

    /**
     * Arguments the command needs to run only this suite.
     *
     * @return array<string, mixed>
     */
    public function commandArguments(): array
    {
        return match ($this) {
            self::Wallet => ['--dry-run' => true],
            self::Schema, self::Uploads => ['--check' => true],
            self::Isolation => ['--suite' => 'isolation'],
            self::Security => ['--suite' => 'all'],
            self::Performance => ['--all' => true],
            self::Backup => ['--latest' => true],
            default => [],
        };
    }

    /**
     * Is this suite about money?
     *
     * A warning here blocks a go-live where a warning elsewhere does not — see the class note and
     * {@see IntegrityCheckStatus::blocksGoLive()}.
     */
    public function isFinancial(): bool
    {
        return $this === self::Constraints || $this === self::Wallet;
    }

    /**
     * How often HD-10 runs this on its own, or null when it only runs as part of `--suite=all`.
     *
     * The two financial suites run nightly because a drift introduced in month seven should be
     * found that night rather than by a client. The rest are daily or weekly: they answer questions
     * about code, and code changes on deploys, not overnight.
     */
    public function defaultSchedule(): ?string
    {
        return match ($this) {
            self::Constraints, self::Wallet => 'daily',
            self::Schema, self::Routes, self::Security, self::Backup => 'daily',
            self::Isolation, self::Uploads, self::Performance => 'weekly',
        };
    }

    /**
     * Does running this suite need a database at all?
     *
     * The three static ones can run in CI against a checkout with no database, which is where they
     * are most useful — a route that lost its permission should fail the pull request, not the
     * nightly sweep.
     */
    public function isStatic(): bool
    {
        return $this === self::Routes || $this === self::Uploads || $this === self::Security;
    }

    /**
     * The suites that make up `--suite=all`, in the order they should run.
     *
     * Financial first, deliberately: if the money is wrong, that is the finding somebody needs to
     * see at the top of the output rather than after six screens of green.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            self::Constraints,
            self::Wallet,
            self::Schema,
            self::Routes,
            self::Uploads,
            self::Isolation,
            self::Security,
            self::Performance,
            self::Backup,
        ];
    }
}
