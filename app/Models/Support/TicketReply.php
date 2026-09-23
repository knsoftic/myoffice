<?php

declare(strict_types=1);

namespace App\Models\Support;

use App\Enums\PanelType;
use App\Enums\ReplyVisibility;
use App\Enums\TicketStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * One entry in a ticket's timeline (phase-19-23 §2.19, requirement §93).
 *
 * **Append-only, and the hooks below are the layer that holds.** There is no edit route and no delete
 * route, and the policy returns false for both — but a policy protects nothing from a Super Admin
 * (D124, D140), so the model refuses an update to what a reply *says* and refuses deletion outright.
 * A correction is a new reply. The question a ticket exists to answer six months later is "what did
 * we actually promise?", and a thread that could be rewritten answers it unreliably.
 *
 * **`MUTABLE_AFTER_WRITING` is short and every entry on it is bookkeeping**, not content: the
 * attachment count the service recounts, and the first-response flag it stamps once. Neither changes
 * a word anybody read.
 *
 * **`internal_note` never leaves staff** (INV-22-3). `public()` is the scope every portal query uses,
 * and `TicketService::reply()` forces a portal user's own reply to `public` rather than validating
 * it — a field the requester controls must not be able to mint a note only staff were meant to see.
 *
 * @property ReplyVisibility $visibility
 */
class TicketReply extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'ticket_replies';

    /**
     * What may change after a reply has been written. See the class note — none of it is content.
     *
     * @var list<string>
     */
    private const MUTABLE_AFTER_WRITING = [
        'attachments_count',
        'is_first_response',
        'updated_at',
        'updated_by',
        'deleted_at',
    ];

    /**
     * `visibility` is absent: it is decided by the service from who is replying, never posted.
     *
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
            'visibility' => ReplyVisibility::class,
            'status_from' => TicketStatus::class,
            'status_to' => TicketStatus::class,
            'is_first_response' => 'boolean',
            'is_system' => 'boolean',
            'attachments_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (self $reply): void {
            $forbidden = array_diff(array_keys($reply->getDirty()), self::MUTABLE_AFTER_WRITING);

            if ($forbidden !== []) {
                throw new LogicException(sprintf(
                    'A ticket reply is never edited (§2.19). Post a correction instead — somebody '
                    .'has already read this one. Refused: %s.',
                    implode(', ', $forbidden),
                ));
            }
        });

        static::deleting(static function (self $reply): never {
            throw new LogicException(
                'A ticket reply is never deleted (§2.19). The thread is the record of what was said, '
                .'and a gap in it is worse than a correction under it.'
            );
        });
    }

    public function moduleSlug(): string
    {
        return 'support_tickets';
    }

    protected function activityModule(): ?string
    {
        return 'support_tickets';
    }

    /** Does the person who raised the ticket see this? */
    public function reachesRequester(): bool
    {
        return $this->visibility->reachesRequester();
    }

    /** Written by a person, rather than stamped by the application. */
    public function isHuman(): bool
    {
        return ! (bool) $this->getAttribute('is_system');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /** Null for a system entry. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The one scope every portal query uses.
     *
     * Named for what it returns rather than for what it excludes, because a caller reading
     * `->public()` cannot forget which way round it goes.
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('visibility', ReplyVisibility::Public->value);
    }

    public function scopeHuman(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    /** In the order they were written. The id, not the timestamp — see the migration's note. */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('id');
    }
}
