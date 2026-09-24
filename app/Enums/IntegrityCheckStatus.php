<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How one integrity suite came out (phase-24-25 §3, §110).
 *
 * **Three states, not two, because "nothing failed" and "everything is right" are different
 * claims.** A warning is a finding the suite could not classify as a breach — an index the manifest
 * wants that the table does not have, a route with no rationale, a backup whose checksum is fine but
 * which has never been restored. None of those is broken today; each is how something breaks later.
 *
 * **`blocksGoLive()` is the asymmetry worth reading twice.** A warning on a financial suite blocks;
 * a warning anywhere else does not. HD-6 and §6.13 both turn on that line: a wallet that *probably*
 * reconciles is not a wallet that reconciles, and the cost of being wrong is somebody's money
 * rather than a slow page.
 */
enum IntegrityCheckStatus: string
{
    use HasOptions;

    case Passed = 'passed';

    case Warning = 'warning';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Passed',
            self::Warning => 'Warning',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Passed => 'emerald',
            self::Warning => 'amber',
            self::Failed => 'rose',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Passed => 'Every check in the suite held.',
            self::Warning => 'Nothing is broken today, and something here is how it breaks later.',
            self::Failed => 'A check the system depends on did not hold.',
        };
    }

    /**
     * The process exit code, which is the contract a CI job and a monitoring agent both read.
     *
     * 0 / 1 / 2 rather than 0 / 1: a pipeline that treats every non-zero the same cannot let a
     * warning through while stopping on a failure, and that distinction is the reason the middle
     * state exists at all.
     */
    public function exitCode(): int
    {
        return match ($this) {
            self::Passed => 0,
            self::Warning => 1,
            self::Failed => 2,
        };
    }

    /**
     * Does a run in this state stop a go-live?
     *
     * A failure always does. A warning does only on a financial suite — see the class note.
     */
    public function blocksGoLive(IntegrityCheckSuite $suite): bool
    {
        if ($this === self::Failed) {
            return true;
        }

        return $this === self::Warning && $suite->isFinancial();
    }

    public function isTerminal(): bool
    {
        return true;
    }

    /** Worth surfacing rather than leaving on a list somebody scrolls past. */
    public function needsAttention(): bool
    {
        return $this !== self::Passed;
    }

    /**
     * The status a run lands on, given what it counted.
     *
     * One place rather than a conditional at every call site: a command that decided its own status
     * could report `passed` with failures in its findings, which is the one output nobody would
     * think to check.
     */
    public static function fromCounts(int $failed, int $warned): self
    {
        return match (true) {
            $failed > 0 => self::Failed,
            $warned > 0 => self::Warning,
            default => self::Passed,
        };
    }

    /**
     * The worst of several — how `--suite=all` reports one answer for nine runs.
     *
     * @param  iterable<self>  $statuses
     */
    public static function worst(iterable $statuses): self
    {
        $worst = self::Passed;

        foreach ($statuses as $status) {
            if ($status->exitCode() > $worst->exitCode()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
