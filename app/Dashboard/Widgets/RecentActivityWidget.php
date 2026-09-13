<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Models\Activity;
use App\Models\User;
use App\Support\DateRange;
use App\Support\Format;
use App\Support\Modules;
use Illuminate\Support\Str;
use Throwable;

/**
 * The tail of the audit trail (phase-02 §3).
 *
 * Gated on `activity_log.view_logs`, and the gate is the registry's, not this class's: a viewer
 * without that permission never receives this descriptor, so the two queries below are never
 * issued for them. That is the difference between hiding a card and not running its query, and
 * §6's authorization test asserts the second.
 *
 * Two queries, fixed: the page of entries, and one eager load of their causers. `with('causer')`
 * is what stops the avatar in each row from becoming a query of its own — the N+1 this card is
 * the classic example of.
 */
final class RecentActivityWidget extends Widget
{
    private const LIMIT = 8;

    public function key(): string
    {
        return 'recent_activity';
    }

    public function title(): string
    {
        return 'Recent activity';
    }

    public function icon(): string
    {
        return 'clipboard-document-list';
    }

    public function permission(): ?string
    {
        return 'activity_log.view_logs';
    }

    public function module(): ?string
    {
        return 'activity_log';
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
        return 40;
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
        return 'Who changed what, most recent first';
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.activity-log.index');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $entries = Activity::query()
                ->with('causer')
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

        $moduleNames = $this->moduleNames();
        $rows = [];

        foreach ($entries as $entry) {
            $causer = $entry->causer;
            $module = $entry->module === null ? null : (string) $entry->module;

            $rows[] = [
                'id' => $entry->getKey(),
                'description' => (string) $entry->description,
                'event' => filled($entry->event) ? Str::headline((string) $entry->event) : null,
                'module' => $module,
                'module_label' => $module === null || $module === ''
                    ? null
                    : ($moduleNames[$module] ?? Str::headline($module)),
                'causer_name' => $causer instanceof User ? $causer->name : null,
                'causer_avatar' => $causer instanceof User ? $causer->avatar_url : null,
                'ip_address' => $entry->ip_address === null ? null : (string) $entry->ip_address,
                'at' => $entry->created_at?->toIso8601String(),
                'at_label' => $entry->created_at === null ? null : Format::dateTime($entry->created_at),
                'ago' => $entry->created_at === null ? null : Format::forHumans($entry->created_at),
                'href' => $this->routeUrl('admin.activity-log.show', $entry->getKey()),
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
        return 'Nothing was created, edited or deleted in this period.';
    }

    /**
     * slug => display name, from the cached module payload — not a join per row.
     *
     * @return array<string, string>
     */
    private function moduleNames(): array
    {
        try {
            return Modules::names();
        } catch (Throwable) {
            return [];
        }
    }
}
