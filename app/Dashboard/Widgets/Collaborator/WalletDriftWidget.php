<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Collaborator;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\ReconciliationStatus;
use App\Models\Collaborator\CollaboratorWallet;
use App\Models\Collaborator\CollaboratorWalletReconciliation;
use App\Support\DateRange;
use App\Support\Money;
use Throwable;

/**
 * Wallets that do not match their ledger (phase-10-12 §8.12, spine §6.5.4).
 *
 * The one widget whose good state is a number: **zero**. It shows when the last reconciliation ran as
 * well as what it found, because "nothing is drifting" means very little if nothing has been checked
 * since March.
 */
final class WalletDriftWidget extends Widget
{
    public function key(): string
    {
        return 'wallet_drift';
    }

    public function title(): string
    {
        return 'Wallets not matching the ledger';
    }

    public function icon(): string
    {
        return 'exclamation-triangle';
    }

    public function permission(): ?string
    {
        return 'wallet_reconciliation.view_any';
    }

    public function module(): ?string
    {
        return 'wallet_reconciliation';
    }

    public function group(): string
    {
        return WidgetGroup::FINANCE;
    }

    public function sort(): int
    {
        return 70;
    }

    public function href(): ?string
    {
        return $this->routeUrlWithQuery('admin.wallet-reconciliations.index', ['problems' => 1]);
    }

    public function emptyMessage(): ?string
    {
        return 'Every wallet matches its ledger.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $drifting = CollaboratorWallet::query()
                ->where('reconciliation_status', ReconciliationStatus::Drift->value)
                ->count();

            $failed = CollaboratorWallet::query()
                ->where('reconciliation_status', ReconciliationStatus::Failed->value)
                ->count();

            $drift = Money::of((string) (CollaboratorWallet::query()->sum('drift_amount') ?: Money::ZERO));

            $lastRun = CollaboratorWalletReconciliation::query()->max('checked_at');
        } catch (Throwable) {
            return ['available' => false, 'range_label' => $range->label()];
        }

        return [
            'available' => true,
            'drifting' => $drifting,
            'failed' => $failed,
            'count' => $drifting + $failed,
            'drift_total' => $drift,
            'last_run' => $lastRun,
            'range_label' => 'right now',
        ];
    }
}
