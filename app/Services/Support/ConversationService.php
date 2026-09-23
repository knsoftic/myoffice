<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\ConversationType;
use App\Models\Support\Conversation;
use App\Models\Support\ConversationParticipant;
use App\Models\Support\Message;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Support\Exceptions\SupportRuleException;
use App\Support\MessagingMatrix;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Starting, joining and writing to a thread (phase-19-23 §6.18, requirement §94).
 *
 * **`threadsFor()` is the isolation, and it is one condition repeated everywhere**: the participant
 * row, live, for the signed-in user. Never a client id, never a company — a conversation is personal,
 * and two people at the same client do not share an inbox (phase-05 §9.2). Every other method here
 * re-checks membership rather than trusting that the caller already did.
 *
 * **A duplicate direct thread is a success, not a failure** (INV-22-5). `uq_cv_direct` is the
 * guarantee and the 1062 is the mechanism: `startDirect()` catches it and returns the thread that
 * already exists, because the person pressing "message" wants to talk to somebody, not to be told
 * their conversation is a constraint violation. Two processes racing both end up in the same thread.
 *
 * **A group re-checks every member against every other** (PH22-26). Checking only creator↔member
 * would make a group the back door a direct thread refuses: put a student and a client in one room
 * and they are talking, whatever the matrix says about the pair.
 *
 * **`unread_count` is recounted, never incremented** (INV-22-6). A `++` drifts the first time two
 * sends race, and an inbox that says 3 when there are 2 is one people stop trusting — after which
 * the badge is worse than no badge.
 *
 * **The rate limit is enforced here as well as in middleware.** Middleware counts requests and this
 * counts sends, which are not the same number when one request retries — and a service called from a
 * job or a command never passes through middleware at all.
 */
final class ConversationService
{
    use WritesAuditTrail;

    private const MODULE = 'messages';

    /**
     * How many messages one read may refresh the `reads_count` of.
     *
     * See `refreshReadCounts()`. Two hundred is far more than anybody accumulates between visits and
     * far less than a thread's whole history.
     */
    private const SWEEP_LIMIT = 200;

    /**
     * Open a direct thread, or return the one that already exists.
     *
     * @param  array<string, mixed>  $firstMessage  optional body + attachments for the opening message
     */
    public function startDirect(User $initiator, User $target, array $firstMessage = []): Conversation
    {
        $decision = MessagingMatrix::mayStart($initiator, $target);

        if (! $decision->allowed) {
            throw SupportRuleException::refuse('participants', $decision->reason);
        }

        $this->assertMayOpenAThread($initiator);

        $key = Conversation::keyFor((int) $initiator->getKey(), (int) $target->getKey());

        $existing = Conversation::query()->where('direct_key', $key)->first();

        if ($existing instanceof Conversation) {
            return $this->sendOpeningMessage($existing, $initiator, $firstMessage);
        }

        try {
            $conversation = DB::transaction(function () use ($key, $decision, $initiator, $target): Conversation {
                $conversation = new Conversation;
                $conversation->forceFill([
                    'type' => ConversationType::Direct->value,
                    'pair_scope' => $decision->scopeOrFail()->value,
                    'direct_key' => $key,
                    'participants_count' => 2,
                ]);
                $conversation->save();

                $this->seat($conversation, $initiator, 'owner');
                $this->seat($conversation, $target, 'member');

                return $conversation->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            // Somebody else opened it between the check and the insert. That is the right outcome
            // arriving by another route — INV-22-5.
            $conversation = Conversation::query()->where('direct_key', $key)->firstOrFail();
        }

        return $this->sendOpeningMessage($conversation, $initiator, $firstMessage);
    }

    /**
     * Open a group.
     *
     * **Every pair is checked, not just the creator's.** See the class note: a group whose members
     * could not message each other directly is the back door.
     *
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $context  project_id / course_id / batch_id / support_ticket_id
     */
    public function startGroup(
        array $userIds,
        string $subject,
        User $creator,
        array $context = [],
        array $firstMessage = [],
    ): Conversation {
        $subject = trim($subject);

        if ($subject === '') {
            throw SupportRuleException::refuse('subject', 'Give the group a subject — without one it is indistinguishable from every other group with the same people in it.');
        }

        $this->assertMayOpenAThread($creator);

        $members = User::query()
            ->whereIn('id', array_values(array_unique($userIds)))
            ->whereKeyNot($creator->getKey())
            ->get();

        if ($members->count() < 2) {
            throw SupportRuleException::refuse('participants', 'A group needs at least two other people. For one, open a direct conversation.');
        }

        $everybody = $members->push($creator)->values();

        // Every pair, both halves. The scope the group records is the creator's first pairing — a
        // group has no single pair, and this is the one that authorised it existing at all.
        $scope = null;

        foreach ($everybody as $a) {
            foreach ($everybody as $b) {
                if ((int) $a->getKey() >= (int) $b->getKey()) {
                    continue;
                }

                $decision = MessagingMatrix::mayStart($a, $b);

                if (! $decision->allowed) {
                    throw SupportRuleException::refuse('participants', sprintf(
                        '%s and %s cannot be in the same conversation. %s',
                        $a->getAttribute('name'),
                        $b->getAttribute('name'),
                        $decision->reason,
                    ));
                }

                if ($scope === null && ((int) $a->getKey() === (int) $creator->getKey() || (int) $b->getKey() === (int) $creator->getKey())) {
                    $scope = $decision->scopeOrFail();
                }
            }
        }

        $resolved = $scope ?? MessagingMatrix::mayStart($creator, $members->first())->scopeOrFail();

        $conversation = DB::transaction(function () use ($subject, $resolved, $context, $creator, $everybody): Conversation {
            $conversation = new Conversation;
            $conversation->forceFill(array_merge(
                [
                    'type' => ConversationType::Group->value,
                    'subject' => mb_substr($subject, 0, 180),
                    'pair_scope' => $resolved->value,
                    'participants_count' => $everybody->count(),
                ],
                array_intersect_key($context, array_flip(['project_id', 'course_id', 'batch_id', 'support_ticket_id'])),
            ));
            $conversation->save();

            foreach ($everybody as $person) {
                $this->seat(
                    $conversation,
                    $person,
                    (int) $person->getKey() === (int) $creator->getKey() ? 'owner' : 'member',
                );
            }

            return $conversation->refresh();
        });

        return $this->sendOpeningMessage($conversation, $creator, $firstMessage);
    }

    /**
     * Send a message.
     *
     * One transaction: live participant, thread open, matrix re-checked, rate limit, payload present,
     * caches recounted. The recount is the last step and is a COUNT, never an increment.
     */
    public function send(Conversation $conversation, User $sender, array $data = []): Message
    {
        $decision = MessagingMatrix::mayParticipate($conversation, $sender);

        if (! $decision->allowed) {
            throw SupportRuleException::refuse('conversation', $decision->reason);
        }

        $body = trim((string) ($data['body'] ?? ''));
        $attachments = (int) ($data['attachments_count'] ?? 0);

        if ($body === '' && $attachments < 1) {
            throw SupportRuleException::refuse('body', 'Write something, or attach a file.');
        }

        $this->assertWithinRateLimit($sender);

        return DB::transaction(function () use ($conversation, $sender, $body, $attachments): Message {
            $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();

            $message = new Message;
            $message->forceFill([
                'conversation_id' => $locked->getKey(),
                'user_id' => $sender->getKey(),
                'panel' => $sender->primaryPanel()->value,
                'body' => $body === '' ? null : $body,
                'attachments_count' => $attachments,
                'is_system' => false,
            ]);
            $message->save();

            $this->refreshThread($locked, $message);

            return $message->refresh();
        });
    }

    /**
     * Mark a thread read up to a message.
     *
     * `unread_count` is recomputed by COUNT rather than zeroed, so a message that arrives during the
     * read still counts — zeroing would lose it, and the person would never be told.
     */
    public function markRead(Conversation $conversation, User $user, ?Message $upTo = null): void
    {
        DB::transaction(function () use ($conversation, $user, $upTo): void {
            $participant = $this->liveMembership($conversation, $user, lock: true);

            if ($participant === null) {
                return;
            }

            $latest = $upTo?->getKey() ?? Message::query()
                ->where('conversation_id', $conversation->getKey())
                ->max('id');

            // Where they had got to before. Everything between that and `$latest` has just become
            // read by this person, and those are the only messages whose `reads_count` changed —
            // refreshing the whole thread would be O(messages) on every read of a long conversation,
            // and refreshing only the newest (which is what this did first) leaves every message
            // before it saying nobody has read it.
            $previous = $participant->getAttribute('last_read_message_id');

            $participant->forceFill([
                'last_read_message_id' => $latest,
                'last_read_at' => Carbon::now(),
                'unread_count' => $this->unreadFor($conversation, $latest),
            ])->save();

            if ($latest !== null) {
                $this->refreshReadCounts($conversation, $previous === null ? null : (int) $previous, (int) $latest);
            }
        });
    }

    /** The bell's message badge: one indexed sum, never a loop. */
    public function unreadCountFor(User $user): int
    {
        return (int) ConversationParticipant::query()
            ->where('user_id', $user->getKey())
            ->whereNull('left_at')
            ->sum('unread_count');
    }

    /**
     * This person's threads.
     *
     * **The one scope, stated once.** Every screen, every count and every export reads it — see the
     * class note for why it is the participant row and nothing else.
     */
    public function threadsFor(User $user): \Illuminate\Database\Eloquent\Builder
    {
        return Conversation::query()->forUser($user)->recent();
    }

    /** Add somebody to a group, checking them against everybody already in it. */
    public function addParticipant(Conversation $conversation, User $user, User $actor): ConversationParticipant
    {
        if ($conversation->type !== ConversationType::Group) {
            throw SupportRuleException::refuse('participants', 'People cannot be added to a direct conversation. Start a group instead.');
        }

        foreach ($this->liveMembers($conversation) as $member) {
            $decision = MessagingMatrix::mayStart($user, $member);

            if (! $decision->allowed) {
                throw SupportRuleException::refuse('participants', sprintf(
                    '%s cannot join: %s',
                    $user->getAttribute('name'),
                    $decision->reason,
                ));
            }
        }

        return DB::transaction(function () use ($conversation, $user, $actor): ConversationParticipant {
            $participant = $this->seat($conversation, $user, 'member');

            $this->systemMessage($conversation, sprintf('%s joined.', $user->getAttribute('name')), 'participant.added');
            $this->recountParticipants($conversation);

            $this->audit($conversation, 'Added to conversation', [
                'attributes' => ['user_id' => $user->getKey(), 'by' => $actor->getKey()],
            ], self::MODULE);

            return $participant;
        });
    }

    /** Remove somebody. The membership ends; the history stays. */
    public function removeParticipant(Conversation $conversation, User $user, string $reason, User $actor): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw SupportRuleException::reasonRequired('reason', 'Say why this person is being removed.');
        }

        DB::transaction(function () use ($conversation, $user, $reason, $actor): void {
            $participant = $this->liveMembership($conversation, $user, lock: true);

            if ($participant === null) {
                return;
            }

            $participant->forceFill([
                'left_at' => Carbon::now(),
                'removed_by' => $actor->getKey(),
            ])->save();

            $this->systemMessage($conversation, sprintf('%s was removed.', $user->getAttribute('name')), 'participant.removed');
            $this->recountParticipants($conversation);

            $this->audit($conversation, 'Removed from conversation', [
                'attributes' => ['user_id' => $user->getKey()],
            ], self::MODULE, $reason);
        });
    }

    /** Leave a thread of your own accord. */
    public function leave(Conversation $conversation, User $user): void
    {
        DB::transaction(function () use ($conversation, $user): void {
            $participant = $this->liveMembership($conversation, $user, lock: true);

            if ($participant === null) {
                return;
            }

            $participant->forceFill(['left_at' => Carbon::now()])->save();

            $this->systemMessage($conversation, sprintf('%s left.', $user->getAttribute('name')), 'participant.left');
            $this->recountParticipants($conversation);
        });
    }

    /** Close a thread. Readable for ever, writable by nobody. */
    public function close(Conversation $conversation, string $reason, User $actor): Conversation
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw SupportRuleException::reasonRequired('reason', 'Say why this conversation is being closed. Everybody in it can read the reason.');
        }

        return DB::transaction(function () use ($conversation, $reason, $actor): Conversation {
            $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();

            if ((bool) $locked->getAttribute('is_closed')) {
                return $locked;
            }

            $locked->forceFill([
                'is_closed' => true,
                'closed_at' => Carbon::now(),
                'closed_by' => $actor->getKey(),
                'closure_reason' => mb_substr($reason, 0, 255),
            ])->save();

            $this->systemMessage($locked, 'This conversation was closed.', 'conversation.closed');

            $this->audit($locked, 'Conversation closed', [], self::MODULE, $reason);

            return $locked->refresh();
        });
    }

    // ===============================================================================================

    /** May this person open a thread at all? The per-panel switch of §5.2. */
    private function assertMayOpenAThread(User $user): void
    {
        $panel = $user->primaryPanel()->value;

        $key = match ($panel) {
            'student' => 'support.messaging_student_can_start',
            'client' => 'support.messaging_client_can_start',
            'collaborator' => 'support.messaging_collaborator_can_start',
            default => null,
        };

        if ($key !== null && ! (bool) setting($key, true)) {
            throw SupportRuleException::refuse(
                'conversation',
                'You can reply to a conversation somebody else starts, but not open one. Raise a support ticket instead.',
            );
        }
    }

    /**
     * The rate limit, in the service as well as the middleware.
     *
     * Keyed on the sender rather than the request, because the thing being limited is a person
     * sending messages — not an IP address, which a whole office shares.
     */
    private function assertWithinRateLimit(User $sender): void
    {
        $limit = max(1, (int) setting('support.messaging_rate_limit_per_minute', 20));
        $key = 'messaging:'.$sender->getKey();

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw SupportRuleException::refuse('body', sprintf(
                'That is a lot of messages at once. Try again in %d seconds.',
                RateLimiter::availableIn($key),
            ));
        }

        RateLimiter::hit($key, 60);
    }

    /** Seat somebody, or bring a past member back. */
    private function seat(Conversation $conversation, User $user, string $role): ConversationParticipant
    {
        $participant = new ConversationParticipant;
        $participant->forceFill([
            'conversation_id' => $conversation->getKey(),
            'user_id' => $user->getKey(),
            // Snapshotted: the side they participate as was decided now. See the model's note.
            'panel' => $user->primaryPanel()->value,
            'role' => $role,
            'joined_at' => Carbon::now(),
            'unread_count' => 0,
        ]);
        $participant->save();

        return $participant;
    }

    /** @return Collection<int, User> */
    private function liveMembers(Conversation $conversation): Collection
    {
        return User::query()
            ->whereIn('id', ConversationParticipant::query()
                ->where('conversation_id', $conversation->getKey())
                ->whereNull('left_at')
                ->pluck('user_id'))
            ->get();
    }

    private function liveMembership(Conversation $conversation, User $user, bool $lock = false): ?ConversationParticipant
    {
        $query = ConversationParticipant::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('user_id', $user->getKey())
            ->whereNull('left_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** How many messages this person has not seen. A COUNT — see the class note. */
    private function unreadFor(Conversation $conversation, ?int $lastReadId): int
    {
        return (int) Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->when($lastReadId !== null, fn ($q) => $q->where('id', '>', $lastReadId))
            ->count();
    }

    /** After a send: the thread's pointers, and everybody else's unread count. */
    private function refreshThread(Conversation $conversation, Message $message): void
    {
        $conversation->forceFill([
            'last_message_id' => $message->getKey(),
            'last_message_at' => $message->getAttribute('created_at') ?? Carbon::now(),
            'messages_count' => Message::query()->where('conversation_id', $conversation->getKey())->count(),
        ])->save();

        $others = ConversationParticipant::query()
            ->where('conversation_id', $conversation->getKey())
            ->whereNull('left_at')
            ->where('user_id', '!=', $message->getAttribute('user_id'))
            ->lockForUpdate()
            ->get();

        foreach ($others as $participant) {
            $participant->forceFill([
                'unread_count' => $this->unreadFor($conversation, $participant->getAttribute('last_read_message_id')),
            ])->save();
        }
    }

    /**
     * Refresh `reads_count` on the messages somebody has just read.
     *
     * **Bounded to the window that changed.** `reads_count` is per message — "how many participants
     * have read at least this far" — so one person moving their marker changes the count on every
     * message between where they were and where they are now, and on nothing else. That window is
     * normally the handful they had unread.
     *
     * A capped sweep rather than an unbounded one: somebody returning to a thread after a thousand
     * messages should not pay for all of them in one request, and the messages further back are
     * already showing a count that is only low by one. `SWEEP_LIMIT` bounds it to the most recent
     * slice, which is the part anybody is looking at.
     */
    private function refreshReadCounts(Conversation $conversation, ?int $from, int $to): void
    {
        $ids = Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->when($from !== null, fn ($q) => $q->where('id', '>', $from))
            ->where('id', '<=', $to)
            ->orderByDesc('id')
            ->limit(self::SWEEP_LIMIT)
            ->pluck('id');

        foreach ($ids as $id) {
            Message::query()->whereKey($id)->update([
                'reads_count' => ConversationParticipant::query()
                    ->where('conversation_id', $conversation->getKey())
                    ->whereNotNull('last_read_message_id')
                    ->where('last_read_message_id', '>=', $id)
                    ->count(),
            ]);
        }
    }

    private function recountParticipants(Conversation $conversation): void
    {
        $conversation->forceFill([
            'participants_count' => ConversationParticipant::query()
                ->where('conversation_id', $conversation->getKey())
                ->whereNull('left_at')
                ->count(),
        ])->save();
    }

    /** "X joined", "thread closed" — an entry in the timeline with no author. */
    private function systemMessage(Conversation $conversation, string $body, string $event): Message
    {
        $message = new Message;
        $message->forceFill([
            'conversation_id' => $conversation->getKey(),
            'user_id' => null,
            'body' => $body,
            'is_system' => true,
            'system_event' => $event,
        ]);
        $message->save();

        return $message;
    }

    /** The opening message, when one was given. */
    private function sendOpeningMessage(Conversation $conversation, User $sender, array $data): Conversation
    {
        if (trim((string) ($data['body'] ?? '')) === '' && (int) ($data['attachments_count'] ?? 0) < 1) {
            return $conversation;
        }

        $this->send($conversation, $sender, $data);

        return $conversation->refresh();
    }
}
