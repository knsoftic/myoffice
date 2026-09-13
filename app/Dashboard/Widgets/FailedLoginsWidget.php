<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets;

use App\Dashboard\Concerns\ComparesRanges;
use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\LoginStatus;
use App\Models\LoginHistory;
use App\Support\DateRange;
use Throwable;

/**
 * Failed and blocked sign-in attempts in the selected period (phase-02 §3).
 *
 * `failed` is a wrong credential; `blocked` is a correct credential on an account whose
 * `UserStatus` forbids login. They are separate facts and the card keeps them separate — a spike
 * in the second is an access-management problem, a spike in the first may be an attack.
 *
 * One query, same shape as the successful-logins card: both windows and the distinct-identity
 * count as conditional aggregates over a single bounded scan. A delta here is read the other way
 * round — more failures is worse — which is why `delta()` is told so.
 */
final class FailedLoginsWidget extends Widget
{
    use ComparesRanges;

    public function key(): string
    {
        return 'failed_logins';
    }

    public function title(): string
    {
        return 'Failed logins';
    }

    public function icon(): string
    {
        return 'exclamation-triangle';
    }

    public function permission(): ?string
    {
        return 'login_history.view_logs';
    }

    public function module(): ?string
    {
        return 'login_history';
    }

    public function span(): int
    {
        return 3;
    }

    public function group(): string
    {
        return WidgetGroup::SECURITY;
    }

    public function sort(): int
    {
        return 20;
    }

    public function href(): ?string
    {
        return $this->routeUrlWithQuery('admin.login-history.index', [
            'status' => LoginStatus::Failed->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $previous = $range->previous();

        $currentStart = $range->storageStart()->format('Y-m-d H:i:s');
        $currentEnd = $range->storageEnd()->format('Y-m-d H:i:s');
        $previousStart = $previous->storageStart()->format('Y-m-d H:i:s');
        $previousEnd = $previous->storageEnd()->format('Y-m-d H:i:s');

        try {
            $row = LoginHistory::query()
                ->whereIn('status', [LoginStatus::Failed->value, LoginStatus::Blocked->value])
                ->whereBetween('created_at', [min($previousStart, $currentStart), max($previousEnd, $currentEnd)])
                ->selectRaw('sum(case when status = ? and created_at between ? and ? then 1 else 0 end) as failed_current', [
                    LoginStatus::Failed->value, $currentStart, $currentEnd,
                ])
                ->selectRaw('sum(case when status = ? and created_at between ? and ? then 1 else 0 end) as blocked_current', [
                    LoginStatus::Blocked->value, $currentStart, $currentEnd,
                ])
                ->selectRaw('sum(case when created_at between ? and ? then 1 else 0 end) as total_previous', [
                    $previousStart, $previousEnd,
                ])
                ->selectRaw('count(distinct case when created_at between ? and ? then coalesce(email, ip_address) end) as identities', [
                    $currentStart, $currentEnd,
                ])
                ->first();
        } catch (Throwable) {
            return [
                'available' => false,
                'failed' => 0,
                'blocked' => 0,
                'total' => 0,
                'identities' => 0,
                'delta' => $this->delta(0, 0, moreIsBetter: false),
                'range_label' => $range->label(),
                'previous_label' => $previous->label(),
            ];
        }

        $failed = (int) ($row->failed_current ?? 0);
        $blocked = (int) ($row->blocked_current ?? 0);
        $total = $failed + $blocked;

        return [
            'available' => true,
            'failed' => $failed,
            'blocked' => $blocked,
            'total' => $total,
            'identities' => (int) ($row->identities ?? 0),
            'delta' => $this->delta($total, (int) ($row->total_previous ?? 0), moreIsBetter: false),
            'range_label' => $range->label(),
            'previous_label' => $previous->label(),
            'blocked_href' => $this->routeUrlWithQuery('admin.login-history.index', [
                'status' => LoginStatus::Blocked->value,
            ]),
        ];
    }

    public function emptyMessage(): ?string
    {
        return 'No failed or blocked attempts in this period.';
    }
}
