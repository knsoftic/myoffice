<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Collaborator;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Models\Collaborator\Collaborator;
use App\Services\Collaborator\CollaboratorStatementService;
use App\Support\DateRange;
use App\Support\Money;
use Throwable;

/**
 * Who earned the most over the range (phase-10-12 §8.12).
 *
 * The figure is `CollaboratorStatementService::topEarners()` (INV-26), which sums the same column a
 * partner's own statement balances to — so a partner's place on this board and the closing balance on
 * their statement are the same arithmetic, and a disagreement between them is impossible rather than
 * merely unlikely.
 *
 * Partners are loaded `withTrashed()`: somebody who has since left still earned what they earned, and
 * a leaderboard with a gap in it is a leaderboard nobody trusts.
 */
final class CollaboratorLeaderboardWidget extends Widget
{
    public function __construct(
        private readonly CollaboratorStatementService $statements,
    ) {}

    public function key(): string
    {
        return 'collaborator_leaderboard';
    }

    public function title(): string
    {
        return 'Top partners';
    }

    public function icon(): string
    {
        return 'trophy';
    }

    public function permission(): ?string
    {
        return 'collaborator_commissions.view_reports';
    }

    public function module(): ?string
    {
        return 'collaborator_commissions';
    }

    public function group(): string
    {
        return WidgetGroup::FINANCE;
    }

    public function span(): int
    {
        return 2;
    }

    public function sort(): int
    {
        return 90;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.wallets.index');
    }

    public function emptyMessage(): ?string
    {
        return 'Nobody earned commission in this period.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $rows = $this->statements->topEarners($range, 5);

            $partners = Collaborator::withTrashed()
                ->whereIn('id', $rows->pluck('collaborator_id'))
                ->get(['id', 'collaborator_code', 'name', 'company_name', 'deleted_at'])
                ->keyBy('id');
        } catch (Throwable) {
            return ['available' => false, 'range_label' => $range->label()];
        }

        $leaders = [];
        $top = $rows->first()?->total ?? Money::ZERO;

        foreach ($rows as $index => $row) {
            $partner = $partners->get($row->collaborator_id);

            $leaders[] = [
                'position' => $index + 1,
                'name' => $partner?->displayName() ?? 'Collaborator #'.$row->collaborator_id,
                'code' => (string) $partner?->collaborator_code,
                'trashed' => (bool) $partner?->trashed(),
                'total' => $row->total,
                // Relative to the leader, so the bars mean something without an axis.
                'share' => Money::isZero($top) ? 0.0 : round(((float) $row->total / (float) $top) * 100, 1),
                'href' => $this->routeUrl('admin.wallets.show', $row->collaborator_id),
            ];
        }

        return [
            'available' => true,
            'leaders' => $leaders,
            'range_label' => $range->label(),
        ];
    }
}
