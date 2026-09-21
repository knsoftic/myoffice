<?php

declare(strict_types=1);

namespace App\Events\Collaborator;

use App\DataObjects\Collaborator\ReconciliationReport;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorWalletReconciliation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A wallet did not equal its ledger (spine §6.5.4).
 *
 * The scheduled run **does not repair on its own**: it records, fires this, and every screen switches
 * to the derived figures behind a banner until a human presses Recalculate. Silent auto-repair would
 * hide the bug that caused the drift, and the bug is the interesting part — a cache that fell behind
 * once will fall behind again.
 *
 * Carries the report, not just the collaborator, so a listener naming what disagreed does not re-run
 * eight checks to find out.
 */
final class WalletDriftDetected implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Collaborator $collaborator,
        public readonly ReconciliationReport $report,
        public readonly CollaboratorWalletReconciliation $record,
    ) {}

    /**
     * Is this the serious kind? A structural failure is not repairable by any button on any screen.
     */
    public function isStructural(): bool
    {
        return $this->report->structural() !== [];
    }
}
