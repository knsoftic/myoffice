<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Collaborator;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\CommissionStatus;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Support\DateRange;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Commission waiting for somebody to approve it (phase-10-12 §8.12).
 *
 * **Deliberately not ranged.** A queue is about what is waiting *now*, and an entry that has been
 * waiting since before the selected range is precisely the one somebody needs to see. The age of the
 * oldest is shown for the same reason.
 */
final class CommissionPendingApprovalWidget extends Widget
{
    public function key(): string
    {
        return 'commission_pending_approval';
    }

    public function title(): string
    {
        return 'Commission awaiting approval';
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function permission(): ?string
    {
        return 'collaborator_commissions.view_any';
    }

    public function module(): ?string
    {
        return 'collaborator_commissions';
    }

    public function group(): string
    {
        return WidgetGroup::FINANCE;
    }

    public function sort(): int
    {
        return 30;
    }

    public function href(): ?string
    {
        return $this->routeUrlWithQuery('admin.commissions.index', ['tab' => 'pending']);
    }

    public function emptyMessage(): ?string
    {
        return 'Nothing is waiting for approval.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $row = CollaboratorCommissionLedgerEntry::query()
                ->where('status', CommissionStatus::Pending->value)
                ->selectRaw('COUNT(*) as entries')
                ->selectRaw('COALESCE(SUM(amount), 0) as total')
                ->selectRaw('MIN(transaction_date) as oldest')
                ->first();
        } catch (Throwable) {
            return ['available' => false, 'range_label' => $range->label()];
        }

        $oldest = $row->oldest === null ? null : Carbon::parse((string) $row->oldest, Format::timezone());

        return [
            'available' => true,
            'count' => (int) ($row->entries ?? 0),
            'total' => Money::of((string) ($row->total ?? Money::ZERO)),
            'oldest' => $oldest,
            'oldest_days' => $oldest === null ? null : $oldest->diffInDays(Carbon::now(Format::timezone())),
            'range_label' => 'right now',
        ];
    }
}
