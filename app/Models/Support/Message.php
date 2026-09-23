<?php

declare(strict_types=1);

namespace App\Models\Support;

use App\Enums\PanelType;
use App\Models\Concerns\Blameable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * One message in a thread (phase-19-23 §2.24, requirement §94).
 *
 * **Append-only, enforced below the gate.** No edit route, no delete route, the policy false for
 * both — and the hooks here, because a policy protects nothing from a Super Admin (D124, D140).
 * **[D-22-1]** records that "delete for me" was considered and refused: §94 asks for conversations,
 * messages, attachments, read status and timestamps, and a half-deleted thread makes §112's
 * isolation unprovable — a row one participant cannot see is a row nobody can prove was or was not
 * delivered.
 *
 * **`body` is plain text and is always rendered escaped.** It never goes through `RichText`: a
 * message is typed into a chat box by anybody with an account on any of five panels, which is the
 * widest authorship surface in the system, and the cheapest way to be certain none of it becomes
 * markup is for none of it to be allowed to.
 *
 * **No activity log.** Every message is already a permanent, timestamped, attributed row; logging
 * "a message was created" beside it would double the write volume of the busiest table in the phase
 * to record something the row itself says better.
 */
class Message extends Model
{
    use Blameable;
    use SoftDeletes;

    protected $table = 'messages';

    /**
     * What may change after a message is sent — the read counter and nothing else. Not a word of it.
     *
     * @var list<string>
     */
    private const MUTABLE_AFTER_SENDING = [
        'reads_count',
        'attachments_count',
        'updated_at',
        'updated_by',
        'deleted_at',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'body',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'panel' => PanelType::class,
            'attachments_count' => 'integer',
            'is_system' => 'boolean',
            'reads_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (self $message): void {
            $forbidden = array_diff(array_keys($message->getDirty()), self::MUTABLE_AFTER_SENDING);

            if ($forbidden !== []) {
                throw new LogicException(sprintf(
                    'A message is never edited (§2.24). Send another — the person you are talking to '
                    .'has already read this one. Refused: %s.',
                    implode(', ', $forbidden),
                ));
            }
        });

        static::deleting(static function (self $message): never {
            throw new LogicException(
                'A message is never deleted ([D-22-1]). A thread with a gap in it cannot prove what '
                .'was or was not delivered, which is what §112 asks of it.'
            );
        });
    }

    public function moduleSlug(): string
    {
        return 'messages';
    }

    /** Written by a person, rather than "X joined" or "thread closed". */
    public function isHuman(): bool
    {
        return ! (bool) $this->getAttribute('is_system');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** Null for a system entry. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeHuman(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    /** In the order they were sent. The id, not the timestamp — see the migration's note. */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('id');
    }

    /** What arrived after this person last looked. */
    public function scopeAfter(Builder $query, ?int $messageId): Builder
    {
        return $messageId === null ? $query : $query->where('id', '>', $messageId);
    }
}
