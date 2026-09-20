<?php

declare(strict_types=1);

namespace App\Console\Commands\Collaborator;

use App\Enums\CommissionStatus;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Services\Collaborator\CommissionApprovalService;
use App\Support\Format;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `commissions:release-held` — `approved` → `available` once the hold has run out (spine §10.4, §2.18.7).
 *
 * A business that holds commission for thirty days has entries that are approved and still not
 * spendable, and the two states are genuinely different to the partner looking at them. This is the
 * only thing that moves the second into the first.
 *
 * **The date compared is the entry's own `hold_until`**, stamped at posting time from the hold days
 * that were snapshotted onto its entitlement. Shortening the policy today does not retroactively
 * release last month's entries, and lengthening it does not re-hold them — which is the same
 * guarantee every other figure on a ledger row carries.
 *
 * Each entry moves through `CommissionApprovalService::release()` rather than a bulk UPDATE: the
 * wallet delta and the audit row belong to the same transaction as the status change, and a mass
 * update would leave the cache behind and the trail empty.
 */
#[AsCommand(name: 'commissions:release-held')]
final class ReleaseHeldCommissions extends Command
{
    protected $signature = 'commissions:release-held {--limit=1000 : Entries released in one pass}';

    protected $description = 'Make approved commissions available once their hold period has passed';

    public function handle(CommissionApprovalService $approvals): int
    {
        $today = Carbon::now(Format::timezone())->startOfDay();
        $limit = max(1, min(10000, (int) $this->option('limit')));

        $entries = CollaboratorCommissionLedgerEntry::query()
            ->where('status', CommissionStatus::Approved->value)
            ->whereNotNull('hold_until')
            ->whereDate('hold_until', '<=', $today->toDateString())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $released = 0;

        foreach ($entries as $entry) {
            if ($approvals->release($entry)->status === CommissionStatus::Available) {
                $released++;
            }
        }

        $this->info(sprintf('released %d held commission(s) as of %s', $released, $today->toDateString()));

        return self::SUCCESS;
    }
}
