<?php

declare(strict_types=1);

namespace App\Models\Support;

use App\Enums\ConversationScope;
use App\Enums\ConversationType;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A message thread (phase-19-23 §2.22, requirement §94).
 *
 * **`pair_scope` is stored, and re-read on every send** (INV-22-4). It records which of §94's six
 * pairs authorised this thread, so switching `student_staff` off in
 * `support.messaging_allowed_pairs` silences the existing student threads rather than only
 * preventing new ones. A check made once at creation leaves yesterday's conversations running under
 * today's policy, which is the opposite of what switching a pair off means.
 *
 * **`direct_key` is what makes one thread per pair** (INV-22-5): the sha256 of the sorted
 * participant ids, unique, so messaging the same person twice reopens the thread you already had
 * rather than splitting the history across two. `keyFor()` is the one place that hash is computed —
 * a second implementation would eventually sort differently and mint a duplicate.
 *
 * **A closed thread is readable for ever and writable by nobody.** It is not deleted, because §94
 * asks for read status and timestamps and [D-22-1] refuses a half-deleted thread: a row one
 * participant cannot see is a row nobody can prove was or was not delivered (§112).
 *
 * @property ConversationType $type
 * @property ConversationScope $pair_scope
 */
class Conversation extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'conversations';

    /**
     * `pair_scope`, `direct_key` and every cache belong to the service — the scope in particular is
     * the matrix's answer, not a caller's claim.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'subject',
        'project_id',
        'course_id',
        'batch_id',
        'support_ticket_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'pair_scope' => ConversationScope::class,
            'last_message_at' => 'datetime',
            'messages_count' => 'integer',
            'participants_count' => 'integer',
            'is_closed' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function moduleSlug(): string
    {
        return 'messages';
    }

    protected function activityModule(): ?string
    {
        return 'messages';
    }

    /**
     * The key that makes a direct thread unique, from two user ids.
     *
     * **Sorted before hashing**, so (7, 12) and (12, 7) are the same thread. The one implementation:
     * a second one would eventually sort differently, and `uq_cv_direct` would then permit the
     * duplicate it exists to prevent.
     */
    public static function keyFor(int $a, int $b): string
    {
        $ids = [$a, $b];
        sort($ids);

        return hash('sha256', implode(':', $ids));
    }

    /** Writable? A closed thread is not, by anybody, including whoever closed it. */
    public function acceptsMessages(): bool
    {
        return ! (bool) $this->getAttribute('is_closed');
    }

    /** Is this person a live member? The live question, never a snapshot. */
    public function includes(User|int $user): bool
    {
        return $this->activeParticipants()
            ->where('user_id', $user instanceof User ? $user->getKey() : $user)
            ->exists();
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /** Membership that has not ended. Everything §9 scopes on reads this. */
    public function activeParticipants(): HasMany
    {
        return $this->participants()->whereNull('left_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /**
     * This person's inbox.
     *
     * **Always the participant row, never a client id and never a company.** A conversation is
     * personal: two people at the same client do not share an inbox (phase-05 §9.2, restated in
     * §2.23's note because this is the scope somebody would be tempted to widen).
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->whereHas(
            'participants',
            fn (Builder $p) => $p->where('user_id', $id)->whereNull('left_at'),
        );
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_closed', false);
    }

    /** Newest conversation first, with never-used threads last rather than first. */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByRaw('COALESCE(last_message_at, created_at) DESC');
    }
}
