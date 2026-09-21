<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Collaborator;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Services\Collaborator\CollaboratorWalletService;
use App\Support\DateRange;
use Throwable;

/**
 * What the business owes partners right now (phase-10-12 §8.12).
 *
 * Derived from the ledger in one query through `CollaboratorWalletService::liabilityTotals()`
 * (INV-26). Summing the cached wallet columns would make this a sum of caches, each of which may have
 * drifted — and a liability figure is exactly the number nobody should have to take on trust.
 *
 * The approval queue is **included**. Money the business will owe the moment somebody presses approve
 * is not a different kind of money, and a liability that excluded it would understate itself every
 * month by however long approvals take.
 */
final class WalletLiabilityWidget extends Widget
{
    public function __construct(
        private readonly CollaboratorWalletService $wallets,
    ) {}

    public function key(): string
    {
        return 'wallet_liability';
    }

    public function title(): string
    {
        return 'Owed to partners';
    }

    public function icon(): string
    {
        return 'scale';
    }

    public function permission(): ?string
    {
        return 'collaborator_wallets.view_reports';
    }

    public function module(): ?string
    {
        return 'collaborator_wallets';
    }

    public function group(): string
    {
        return WidgetGroup::FINANCE;
    }

    public function sort(): int
    {
        return 50;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.wallets.index');
    }

    public function emptyMessage(): ?string
    {
        return 'Nothing is outstanding.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $totals = $this->wallets->liabilityTotals();
        } catch (Throwable) {
            return ['available' => false, 'range_label' => $range->label()];
        }

        return [
            'available' => true,
            'owed' => $totals['owed'],
            'pending' => $totals['pending'],
            'payable' => $totals['payable'],
            'reserved' => $totals['reserved'],
            'range_label' => 'right now',
        ];
    }
}
