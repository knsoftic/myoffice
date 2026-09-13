<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\LoginStatus;
use App\Models\LoginHistory;
use App\Support\DateRange;
use App\Support\Format;
use Throwable;

/**
 * The last sign-in attempts, whatever their outcome (phase-02 §3).
 *
 * Successes, failures, blocked accounts and logouts in one list, because the interesting reading
 * is the sequence — three failures then a success on the same IP is a different story from three
 * failures on three IPs. The failed and blocked rows carry their own tint in the view.
 *
 * Two queries, fixed: the page of rows plus one eager load of the accounts behind them. The email
 * column is what a failed attempt leaves instead of a user, so a row with no account still names
 * who was tried.
 */
final class RecentLoginsWidget extends Widget
{
    private const LIMIT = 8;

    public function key(): string
    {
        return 'recent_logins';
    }

    public function title(): string
    {
        return 'Recent sign-ins';
    }

    public function icon(): string
    {
        return 'finger-print';
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
        return 6;
    }

    public function group(): string
    {
        return WidgetGroup::SECURITY;
    }

    public function sort(): int
    {
        return 50;
    }

    public function skeleton(): string
    {
        return 'card';
    }

    public function padded(): bool
    {
        return false;
    }

    public function subtitle(): ?string
    {
        return 'Successes, failures and blocked accounts';
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.login-history.index');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $entries = LoginHistory::query()
                ->with('user')
                ->whereBetween('created_at', [
                    $range->storageStart()->format('Y-m-d H:i:s'),
                    $range->storageEnd()->format('Y-m-d H:i:s'),
                ])
                ->latestFirst()
                ->limit(self::LIMIT)
                ->get();
        } catch (Throwable) {
            return [
                'available' => false,
                'entries' => [],
                'range_label' => $range->label(),
                'index_url' => $this->href(),
            ];
        }

        $rows = [];

        foreach ($entries as $entry) {
            $status = $entry->status instanceof LoginStatus
                ? $entry->status
                : LoginStatus::tryFrom((string) $entry->status);

            $rows[] = [
                'id' => $entry->getKey(),
                'name' => $entry->user?->name ?? $entry->email ?? 'Unknown',
                'avatar' => $entry->user?->avatar_url,
                'is_known' => $entry->user !== null,
                'email' => $entry->email === null ? null : (string) $entry->email,
                'status' => $status?->value,
                'status_label' => $status?->label() ?? '—',
                'status_color' => $status?->color() ?? 'slate',
                'is_failure' => $status === LoginStatus::Failed,
                'is_blocked' => $status === LoginStatus::Blocked,
                'ip_address' => $entry->ip_address === null ? null : (string) $entry->ip_address,
                'device' => $entry->deviceLabel(),
                'device_icon' => $this->deviceIcon($entry->device),
                'at' => $entry->created_at?->toIso8601String(),
                'at_label' => $entry->created_at === null ? null : Format::dateTime($entry->created_at),
                'ago' => $entry->created_at === null ? null : Format::forHumans($entry->created_at),
            ];
        }

        return [
            'available' => true,
            'entries' => $rows,
            'range_label' => $range->label(),
            'index_url' => $this->href(),
        ];
    }

    public function emptyMessage(): ?string
    {
        return 'No sign-in attempts were recorded in this period.';
    }

    private function deviceIcon(?string $device): string
    {
        return match (strtolower(trim((string) $device))) {
            'mobile' => 'phone',
            'tablet' => 'view-columns',
            'desktop' => 'computer-desktop',
            'bot' => 'server-stack',
            default => 'question-mark-circle',
        };
    }
}
