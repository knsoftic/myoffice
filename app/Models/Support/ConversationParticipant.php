<?php

declare(strict_types=1);

namespace App\Models\Support;

use App\Enums\PanelType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's membership of one thread (phase-19-23 §2.23, requirement §94).
 *
 * **This table is the whole of §94's isolation**, and the scope is always the same two conditions:
 * `user_id = auth()->id() AND left_at IS NULL`. Never a client id, never a company — a conversation
 * is personal, and two people at the same client do not share an inbox (phase-05 §9.2).
 *
 * **`panel` is snapshotted at join time, not read live.** §94's matrix is about roles, and the side
 * somebody participates *as* was decided when the thread was authorised. Somebody who is a teacher
 * and later becomes an employee does not retroactively turn an old `student_staff` thread into an
 * `admin_employee` one.
 *
 * **`unread_count` is recounted under a row lock, never incremented** (INV-22-6). A `++` on a badge
 * drifts the first time two sends race, and an inbox that says 3 when there are 2 is one people stop
 * trusting — after which the badge is worse than no badge.
 *
 * **Leaving is `left_at`, not a deleted row.** The history of who was in a thread when something was
 * said is part of what the thread means, and `active_guard` lets somebody who left and was re-added
 * have two rows with one of them live.
 */
class ConversationParticipant extends Model
{
    protected $table = 'conversation_participants';

    /**
     * Only what a person chooses about their own membership. `panel`, the counts and every
     * timestamp are the service's.
     *
     * @var list<string>
     */
    protected $fillable = [
        'is_muted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'panel' => PanelType::class,
            'last_read_at' => 'datetime',
            'unread_count' => 'integer',
            'is_muted' => 'boolean',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /** Still in the thread. */
    public function isActive(): bool
    {
        return $this->getAttribute('left_at') === null;
    }

    /** Anything unread? Asked of the cache, which is recounted rather than incremented. */
    public function hasUnread(): bool
    {
        return (int) $this->getAttribute('unread_count') > 0;
    }

    /** Should a new message notify them? Muting silences the bell, never the row. */
    public function wantsNotifying(): bool
    {
        return $this->isActive() && ! (bool) $this->getAttribute('is_muted');
    }

    /** How long they were in the thread, or how long so far. */
    public function membershipMinutes(?Carbon $asOf = null): int
    {
        $joined = $this->getAttribute('joined_at');

        if ($joined === null) {
            return 0;
        }

        return (int) $joined->diffInMinutes($this->getAttribute('left_at') ?? $asOf ?? Carbon::now());
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastReadMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_read_message_id');
    }

    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    /** Membership that has not ended — the half of §94's scope that is not the user id. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('left_at');
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /** Everybody who should hear about a new message. */
    public function scopeNotifiable(Builder $query): Builder
    {
        return $query->active()->where('is_muted', false);
    }
}
