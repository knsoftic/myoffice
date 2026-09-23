<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PanelType;
use App\Enums\TicketStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The three numbers in the topbar of all five panels (phase-19-23 §6.19, PH23-47).
 *
 * **Three indexed `COUNT`s, never three relations.** The topbar renders on every page of every
 * panel, so this is the most-executed query set in the application. `$user->unreadNotifications->count()`
 * would hydrate every row to count them; `$user->conversations->sum('unread_count')` would load
 * every thread. Both are correct and both are the reason a dashboard takes two seconds.
 *
 * **Cached per request, and the cache is per user.** One layout may ask for the badge, the drawer
 * and the mobile header; a queued job iterating users must not be handed the first user's numbers
 * for the second. The key is the user id, which is why {@see self::all()} takes one rather than
 * reading `auth()`.
 *
 * **A disabled module counts zero rather than erroring.** Switching `messages` off should empty its
 * badge, not take the topbar down with it (§4.4).
 */
final class UnreadCounters
{
    /** @var array<int, array{notifications: int, messages: int, tickets: int}> */
    private array $cache = [];

    /**
     * @return array{notifications: int, messages: int, tickets: int, total: int}
     */
    public function all(User $user): array
    {
        $id = (int) $user->getKey();

        if (! array_key_exists($id, $this->cache)) {
            $this->cache[$id] = [
                'notifications' => $this->countNotifications($id),
                'messages' => $this->countMessages($id),
                'tickets' => $this->countTickets($user),
            ];
        }

        $counts = $this->cache[$id];

        return $counts + ['total' => array_sum($counts)];
    }

    public function notifications(User $user): int
    {
        return $this->all($user)['notifications'];
    }

    public function messages(User $user): int
    {
        return $this->all($user)['messages'];
    }

    public function tickets(User $user): int
    {
        return $this->all($user)['tickets'];
    }

    /** A queued job that moves between users, or a test that wrote rows mid-request. */
    public function flush(?User $user = null): void
    {
        if ($user === null) {
            $this->cache = [];

            return;
        }

        unset($this->cache[(int) $user->getKey()]);
    }

    // ===============================================================================================

    /** Covered by `idx_nt_bell` — `(notifiable_type, notifiable_id, read_at, archived_at)`. */
    private function countNotifications(int $userId): int
    {
        if (! Modules::enabled('notifications')) {
            return 0;
        }

        return (int) DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->whereNull('archived_at')
            ->whereNull('read_at')
            ->count();
    }

    /**
     * The sum of the per-participant caches, not a count of messages.
     *
     * `conversation_participants.unread_count` is recomputed by COUNT under a row lock whenever it
     * moves (INV-22-6), so summing it is reading a figure that is already correct rather than
     * recomputing it across every thread on every page load.
     *
     * A thread somebody has left is excluded by `left_at IS NULL` — the same clause `threadsFor()`
     * uses, because a badge that counted threads the list will not show is a badge that never
     * clears.
     */
    private function countMessages(int $userId): int
    {
        if (! Modules::enabled('messages')) {
            return 0;
        }

        return (int) DB::table('conversation_participants')
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->sum('unread_count');
    }

    /**
     * Open tickets waiting on **this viewer**, which is a different question for staff and for a
     * requester.
     *
     * An agent's badge means "tickets assigned to me that somebody is waiting on"; a client's means
     * "my tickets that have been answered". Counting the same rows for both would give an agent a
     * badge for every ticket in the system and a client a badge for tickets they cannot see.
     */
    private function countTickets(User $user): int
    {
        if (! Modules::enabled('support_tickets')) {
            return 0;
        }

        $id = (int) $user->getKey();
        $open = array_map(
            static fn (TicketStatus $status): string => $status->value,
            array_values(array_filter(TicketStatus::cases(), static fn (TicketStatus $s): bool => $s->isOpen())),
        );

        $query = DB::table('support_tickets')
            ->whereNull('deleted_at')
            ->whereIn('status', $open);

        if ($user->can('support_tickets.view_any')) {
            // Assigned to me and the ball is in our court. An unassigned queue is somebody's job to
            // watch on the queue screen, not a number that follows every agent around.
            return (int) $query
                ->where('assigned_to', $id)
                ->where('last_reply_panel', '!=', PanelType::Admin->value)
                ->count();
        }

        // A requester: their own tickets that staff have answered since they last wrote.
        return (int) $query
            ->where('user_id', $id)
            ->where('last_reply_panel', PanelType::Admin->value)
            ->count();
    }
}
