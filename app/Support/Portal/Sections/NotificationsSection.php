<?php

declare(strict_types=1);

namespace App\Support\Portal\Sections;

use App\Contracts\Portal\ClientPortalSection;
use App\Models\Crm\Client;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The client panel's Notifications screen — Phase 5's own section (phase-05 §8.10, §9.2).
 *
 * `notifications.notifiable_type = User AND notifiable_id = auth()->id()`: a notification belongs to the signed-in
 * **user**, never to the company. The `notifications` table is Phase 22's; until it exists this section reports no
 * count and an empty page rather than failing.
 */
final class NotificationsSection implements ClientPortalSection
{
    public function key(): string
    {
        return 'notifications';
    }

    public function label(): string
    {
        return 'Notifications';
    }

    public function icon(): string
    {
        return 'bell';
    }

    public function module(): ?string
    {
        return null;
    }

    public function permission(): string
    {
        return 'client_portal.notifications';
    }

    public function sort(): int
    {
        return 100;
    }

    public function badgeCount(Client $client): ?int
    {
        $user = $this->user();

        if (! $user instanceof User || ! $this->tableExists()) {
            return null;
        }

        return $user->unreadNotifications()->count();
    }

    /**
     * Filters: `unread` (bool), `per_page`.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    public function paginate(Client $client, array $filters): LengthAwarePaginator
    {
        $user = $this->user();
        $perPage = is_numeric($filters['per_page'] ?? null) ? max(5, min(100, (int) $filters['per_page'])) : 20;

        if (! $user instanceof User || ! $this->tableExists()) {
            return new Paginator([], 0, $perPage);
        }

        $unreadOnly = filter_var($filters['unread'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $query = $unreadOnly ? $user->unreadNotifications() : $user->notifications();

        return $query->latest()->paginate($perPage)->withQueryString();
    }

    public function view(): string
    {
        return 'client.notifications.index';
    }

    private function user(): ?User
    {
        try {
            $user = Auth::user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }

    private function tableExists(): bool
    {
        static $exists = null;

        try {
            return $exists ??= Schema::hasTable('notifications');
        } catch (Throwable) {
            return false;
        }
    }
}
