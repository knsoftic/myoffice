<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\DataObjects\Support\AudienceInput;
use App\DataObjects\Support\BellPayload;
use App\DataObjects\Support\ChannelSet;
use App\DataObjects\Support\DispatchResult;
use App\Enums\NotificationDigest;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\NotificationEvent;
use App\Notifications\Support\RegistryNotification;
use App\Services\Support\Exceptions\InvalidNotificationEvent;
use App\Support\Modules;
use App\Support\NotificationRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Notifier;

/**
 * The only way a notification is sent (phase-19-23 §6.19, INV-22-7, INV-22-8).
 *
 * **One entry point, because the interesting logic is all in the deciding.** Who counts as a
 * recipient, whether the module is on, whether this person may even be told, which channels survive
 * their preferences and the institute's master switch — a second caller doing `$user->notify()`
 * skips every one of those, and would be indistinguishable from this one until the day somebody
 * muted an event and kept receiving it.
 *
 * **An unknown key throws** and nothing else here does. A typo in an event key is a message that
 * would never arrive and never be missed; failing loudly in development is the only moment anybody
 * will notice. Every *other* failure — the module off, no recipients, a broken deep link — is logged
 * and returns, because a business write must never roll back because the bell was unavailable
 * (§4.4).
 *
 * **Nothing is sent inside the transaction that caused it** (INV-22-8). Everything is queued and
 * `afterCommit`: a receipt emailed for a payment a later failure rolled back is worse than a receipt
 * that arrives a second late.
 *
 * **`mandatory` beats every preference, and the list is three or four events long.** Money leaving a
 * wallet, a revoked certificate, a breached target: the cases where "I was never told" is a dispute.
 * Everything else the person decides, or the preference screen is theatre.
 */
final class NotificationService
{
    /** Why a recipient was dropped — the keys of `DispatchResult::$skipped`. */
    public const SKIP_INACTIVE = 'inactive';

    public const SKIP_NO_ACCOUNT = 'no_account';

    public const SKIP_PERMISSION = 'permission';

    public const SKIP_MUTED = 'muted';

    private ?BellPayload $bell = null;

    private ?int $bellFor = null;

    /**
     * The preferences are read through their own service, not cached again here.
     *
     * A second copy went stale the moment somebody saved a preference and dispatched in the same
     * request: the save went to one cache and the dispatch read the other. One owner, one answer.
     */
    public function __construct(
        private readonly NotificationPreferenceService $preferences,
    ) {}

    /**
     * Send one event to an audience.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $eventKey, AudienceInput $audience, array $payload = [], ?User $actor = null): DispatchResult
    {
        $event = NotificationRegistry::event($eventKey);

        if (! $event instanceof NotificationEvent) {
            throw InvalidNotificationEvent::key($eventKey);
        }

        // (2) The bell being off never fails the act that rang it. §4.4: a logged no-op.
        if (! Modules::enabled('notifications')) {
            Log::info('Notification suppressed: the notifications module is disabled.', ['event' => $eventKey]);

            return DispatchResult::nothing($eventKey, 'the notifications module is disabled');
        }

        if (! Modules::enabled($event->module)) {
            Log::info('Notification suppressed: the owning module is disabled.', [
                'event' => $eventKey,
                'module' => $event->module,
            ]);

            return DispatchResult::nothing($eventKey, 'the '.$event->module.' module is disabled');
        }

        $skipped = [];
        $recipients = $this->resolve($event, $audience, $payload, $skipped);

        if ($recipients === []) {
            return DispatchResult::nothing($eventKey, 'no recipient survived resolution', $skipped);
        }

        $sent = [];

        foreach ($recipients as $recipient) {
            $channels = $this->channelsFor($event, $recipient);

            if (! $channels->sendsAnything()) {
                $skipped[self::SKIP_MUTED] = ($skipped[self::SKIP_MUTED] ?? 0) + 1;

                continue;
            }

            $notification = $this->build($event, $payload, $actor, $channels);

            // afterCommit, always. `Notification::sendNow()` is never used here (INV-22-8).
            Notifier::send([$recipient], $notification);

            $sent[] = (int) $recipient->getKey();
        }

        if ($sent !== []) {
            // The row itself is written by the queue worker through `RichDatabaseChannel`, columns
            // and all. Nothing is stamped afterwards here, and that is deliberate: an UPDATE issued
            // now would match no rows at all, because none exist yet.
            $this->forgetBell();
        }

        return DispatchResult::delivered($eventKey, $sent, $skipped);
    }

    /**
     * "Tell whoever may act on this."
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatchToPermission(string $eventKey, string $permission, array $payload = [], ?User $actor = null): DispatchResult
    {
        return $this->dispatch($eventKey, AudienceInput::permission($permission), $payload, $actor);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading and clearing
    |--------------------------------------------------------------------------
    */

    /**
     * The bell: the unread count and the newest page of rows.
     *
     * Cached per request per user, because the topbar renders on every page and a layout that asked
     * twice would pay twice for an answer that cannot have changed between them.
     */
    public function bell(User $user, bool $fresh = false): BellPayload
    {
        $id = (int) $user->getKey();

        if (! $fresh && $this->bell instanceof BellPayload && $this->bellFor === $id) {
            return $this->bell;
        }

        if (! Modules::enabled('notifications')) {
            return $this->remember($id, BellPayload::empty());
        }

        $size = max(1, (int) setting('support.notification_bell_page_size', 10));

        $rows = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $id)
            ->whereNull('archived_at')
            ->orderByDesc('created_at')
            ->limit($size + 1)
            ->get();

        $unread = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $id)
            ->whereNull('archived_at')
            ->whereNull('read_at')
            ->count();

        // One row over the page size answers "is there more" without a second COUNT.
        $hasMore = $rows->count() > $size;

        return $this->remember($id, new BellPayload($unread, $rows->take($size)->values(), $hasMore));
    }

    /** Mark one row read. Returns false when the row is not this person's — never an exception. */
    public function markRead(User $user, string $id): bool
    {
        $touched = DB::table('notifications')
            ->where('id', $id)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        $this->forgetBell();

        return $touched > 0;
    }

    public function markAllRead(User $user): int
    {
        $touched = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        $this->forgetBell();

        return $touched;
    }

    /**
     * Archive one row.
     *
     * **Archiving is not deleting** (§2.25). The row leaves the bell and stays in the table: it is
     * the evidence that the person was told, and the first question after any dispute is exactly
     * that. `support.notification_retention_days` prunes it later, on a schedule, once it is old
     * enough that nobody is asking.
     */
    public function archive(User $user, string $id): bool
    {
        $now = Carbon::now();

        $touched = DB::table('notifications')
            ->where('id', $id)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->getKey())
            ->whereNull('archived_at')
            ->update(['archived_at' => $now, 'read_at' => DB::raw('COALESCE(`read_at`, '.$this->quote($now).')'), 'updated_at' => $now]);

        $this->forgetBell();

        return $touched > 0;
    }

    public function archiveAll(User $user): int
    {
        $now = Carbon::now();

        $touched = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->getKey())
            ->whereNull('archived_at')
            ->update(['archived_at' => $now, 'read_at' => DB::raw('COALESCE(`read_at`, '.$this->quote($now).')'), 'updated_at' => $now]);

        $this->forgetBell();

        return $touched;
    }

    /** The unread badge on its own — what `UnreadCounters` asks for. */
    public function unreadCountFor(User $user): int
    {
        if (! Modules::enabled('notifications')) {
            return 0;
        }

        return DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->getKey())
            ->whereNull('archived_at')
            ->whereNull('read_at')
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | Maintenance (§10.5)
    |--------------------------------------------------------------------------
    */

    /**
     * `notifications:prune`, weekly.
     *
     * **Only archived rows, and only past the retention window.** An unread notification is never
     * pruned however old it is: it is somebody's outstanding record of being told something, and
     * age is not consent. `support.notification_retention_days = 0` means "keep everything", which
     * is the honest reading of zero here — a zero-day retention that deleted everything nightly
     * would be a footgun with a number on it.
     *
     * Chunked, because this table grows without bound by design (§2.25 archives, never deletes) and
     * a single `DELETE` over a year of rows locks it for as long as it takes.
     *
     * @return int rows removed
     */
    public function pruneArchived(?Carbon $asOf = null, int $chunk = 1000): int
    {
        $days = (int) setting('support.notification_retention_days', 180);

        if ($days <= 0) {
            Log::info('Notification prune skipped: support.notification_retention_days is 0 (keep for ever).');

            return 0;
        }

        $cutoff = ($asOf ?? Carbon::now())->copy()->subDays($days);
        $removed = 0;

        do {
            $deleted = DB::table('notifications')
                ->whereNotNull('archived_at')
                ->where('archived_at', '<', $cutoff)
                ->limit(max(100, $chunk))
                ->delete();

            $removed += $deleted;
        } while ($deleted > 0);

        $this->forgetBell();

        return $removed;
    }

    /**
     * `notifications:digest`, daily at `support.notification_digest_hour`.
     *
     * **A digest is assembled from what is already in the bell**, never from a second send: every
     * row here was written by `dispatch()` when the event happened, and the digest is one mail
     * listing the ones the person chose to have batched rather than mailed one at a time.
     *
     * Returns the assembled payloads rather than sending them, because the sending belongs to a
     * queued job and this way a command can print what it would send without sending it.
     *
     * @return array<int, array{user: User, rows: Collection<int, object>}>
     */
    public function pendingDigests(?Carbon $asOf = null): array
    {
        if (! (bool) setting('support.notifications_mail_enabled', false)) {
            Log::info('Notification digest skipped: mail is switched off for this installation.');

            return [];
        }

        $asOf ??= Carbon::now();

        // The people who asked for a digest of *something*. A preference row exists only where
        // somebody differs from the default, so this is a small table however many users there are.
        $wanted = DB::table('notification_preferences')
            ->where('mail_enabled', true)
            ->where('mail_digest', NotificationDigest::Daily->value)
            ->get()
            ->groupBy(static fn (object $row): int => (int) $row->user_id);

        if ($wanted->isEmpty()) {
            return [];
        }

        $users = User::query()
            ->whereIn('id', $wanted->keys()->all())
            ->where('status', UserStatus::Active->value)
            ->get()
            ->keyBy(static fn (User $user): int => (int) $user->getKey());

        $out = [];

        foreach ($wanted as $userId => $preferences) {
            $user = $users->get((int) $userId);

            if (! $user instanceof User) {
                continue;
            }

            $keys = $preferences->pluck('event_key')->map(static fn ($k): string => (string) $k)->all();

            $rows = DB::table('notifications')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $userId)
                ->whereIn('event_key', $keys)
                ->whereNull('read_at')
                ->whereNull('archived_at')
                ->whereNull('emailed_at')
                ->where('created_at', '>=', $asOf->copy()->subDay())
                ->orderByDesc('created_at')
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $out[] = ['user' => $user, 'rows' => $rows];
        }

        return $out;
    }

    /**
     * Mark a digest's rows as emailed, so tomorrow's does not repeat them.
     *
     * **Stamped after the mail is queued, not before**, and on the ids the digest actually carried —
     * a stamp on "everything unread" would silently swallow a row that arrived between assembling
     * the digest and sending it.
     *
     * @param  list<string>  $ids
     */
    public function markEmailed(array $ids, ?Carbon $asOf = null): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table('notifications')
            ->whereIn('id', $ids)
            ->whereNull('emailed_at')
            ->update(['emailed_at' => $asOf ?? Carbon::now()]);
    }

    /** Between requests a queued job may have written rows; a long command calls this. */
    public function forgetBell(): void
    {
        $this->bell = null;
        $this->bellFor = null;
    }

    public function forgetPreferences(): void
    {
        $this->preferences->flush();
    }

    // ===============================================================================================

    /**
     * Steps 3 and 4 of §6.19: resolve, de-duplicate, drop.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $skipped
     * @return list<User>
     */
    private function resolve(NotificationEvent $event, AudienceInput $audience, array $payload, array &$skipped): array
    {
        $ids = [];

        foreach ($audience->subjects as $subject) {
            $id = $this->userIdOf($subject);

            if ($id === null) {
                // A student or a collaborator with no login. There is nobody to tell, and that is
                // ordinary rather than an error — they are reached by post, or not at all.
                $skipped[self::SKIP_NO_ACCOUNT] = ($skipped[self::SKIP_NO_ACCOUNT] ?? 0) + 1;

                continue;
            }

            $ids[] = $id;
        }

        $permission = $audience->permission
            ?? ($audience->fromRegistry ? $event->audiencePermission() : null);

        if ($permission !== null) {
            $ids = array_merge($ids, $this->holdersOf($permission));
        }

        if ($audience->fromRegistry && $event->audience instanceof \Closure) {
            foreach ((array) ($event->audience)($payload) as $subject) {
                $id = $this->userIdOf($subject);

                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, User> $users */
        $users = User::query()->whereIn('id', $ids)->get();

        $inactive = $users->count();
        $users = $users->filter(static fn (User $user): bool => $user->status === UserStatus::Active);
        $dropped = $inactive - $users->count();

        if ($dropped > 0) {
            $skipped[self::SKIP_INACTIVE] = ($skipped[self::SKIP_INACTIVE] ?? 0) + $dropped;
        }

        // A user id that resolved to no row at all — deleted between the act and the dispatch.
        $missing = count($ids) - $inactive;

        if ($missing > 0) {
            $skipped[self::SKIP_NO_ACCOUNT] = ($skipped[self::SKIP_NO_ACCOUNT] ?? 0) + $missing;
        }

        if ($event->requiredPermission !== null) {
            $before = $users->count();
            $users = $users->filter(
                static fn (User $user): bool => $user->can($event->requiredPermission),
            );

            $lost = $before - $users->count();

            if ($lost > 0) {
                // The row would have linked to a page they cannot open. Not creating it is kinder
                // than creating one that 403s.
                $skipped[self::SKIP_PERMISSION] = ($skipped[self::SKIP_PERMISSION] ?? 0) + $lost;
            }
        }

        return $users->values()->all();
    }

    /**
     * Step 5: the registry's defaults, then the person's row, then the master switch, then mandatory.
     *
     * The order is the whole of it. Defaults are what the event wants; the preference is what the
     * person wants; the master switch is what the installation can actually do — an institute with
     * no SMTP server must not queue mail that will fail for every recipient. `mandatory` comes last
     * because it overrules all three, and a rule that ran before the thing it overrules would not.
     */
    private function channelsFor(NotificationEvent $event, User $user): ChannelSet
    {
        $resolved = $this->preferences->resolve($user, $event->key);

        $channels = new ChannelSet(
            database: $resolved['database'],
            mail: $resolved['mail'],
            digest: $resolved['digest'],
        );

        if (! (bool) setting('support.notifications_mail_enabled', false)) {
            $channels = $channels->withMail(false);
        }

        if ($event->mandatory) {
            // The bell entry cannot be switched off, and a mandatory event's mail still obeys the
            // master switch — there is no sense queueing mail an installation cannot send.
            $channels = new ChannelSet(
                database: true,
                mail: $channels->mail,
                digest: NotificationDigest::Immediate,
            );
        }

        return $channels;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function build(NotificationEvent $event, array $payload, ?User $actor, ChannelSet $channels): Notification
    {
        $actorId = $actor !== null ? (int) $actor->getKey() : null;

        if ($event->factory !== null) {
            $notification = ($event->factory)($payload, $event);

            if ($notification instanceof Notification) {
                return $notification;
            }

            Log::warning('A notification factory returned something that is not a Notification.', [
                'event' => $event->key,
            ]);
        }

        return new RegistryNotification($event, $payload, $actorId, $channels->channels());
    }

    /**
     * @return list<int>
     */
    private function holdersOf(string $permission): array
    {
        // Resolved through the Gate rather than through a role join, so a permission granted
        // directly to one user is not missed and a disabled module still denies it (§4.4's
        // `Gate::before`). The candidate set is narrowed first: `users` is the only table that can
        // hold a notifiable, and an inactive one is dropped a step later anyway.
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->get()
            ->filter(static fn (User $user): bool => $user->can($permission))
            ->map(static fn (User $user): int => (int) $user->getKey())
            ->values()
            ->all();
    }

    private function userIdOf(User|Model|int $subject): ?int
    {
        if (is_int($subject)) {
            return $subject;
        }

        if ($subject instanceof User) {
            return (int) $subject->getKey();
        }

        $id = $subject->getAttribute('user_id');

        return $id === null ? null : (int) $id;
    }

    private function remember(int $userId, BellPayload $payload): BellPayload
    {
        $this->bell = $payload;
        $this->bellFor = $userId;

        return $payload;
    }

    private function quote(Carbon $at): string
    {
        return DB::connection()->getPdo()->quote($at->toDateTimeString());
    }
}
