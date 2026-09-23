<?php

declare(strict_types=1);

namespace App\Models\Support;

use App\Enums\NotificationDigest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's choice about one kind of notification (phase-19-23 §2.25, requirement §97).
 *
 * **A missing row means "the registry default", and that is the whole design.** No backfill is ever
 * needed when a later phase registers a new event — every user is already on its default, because
 * they have no row saying otherwise. Resetting to defaults deletes the row rather than writing one,
 * which is why this table has no soft deletes: an absent row *is* the default.
 *
 * **[D-22-2] is enforced above this table, not in it.** A registry entry may declare
 * `mandatory: true`, and `NotificationService` ignores a stored row that tries to disable one.
 * There is deliberately no `mandatory` column here: a flag stored per user is a flag that can be
 * edited per user, and the point of a mandatory event is that it cannot.
 *
 * **No permission gates this.** §4.1 says so in as many words — a user always owns their own
 * preferences, so the only scope there is is `user_id = auth()->id()`.
 */
class NotificationPreference extends Model
{
    protected $table = 'notification_preferences';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_key',
        'database_enabled',
        'mail_enabled',
        'mail_digest',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'database_enabled' => 'boolean',
            'mail_enabled' => 'boolean',
            'mail_digest' => NotificationDigest::class,
        ];
    }

    /**
     * Would this row have mail sent?
     *
     * Two conditions rather than one: `mail_enabled` off and a digest of `off` mean the same thing
     * to a reader, and either alone is enough to stop the send. The master switch
     * (`support.notifications_mail_enabled`) sits above both and is the service's to check.
     */
    public function sendsMail(): bool
    {
        return (bool) $this->getAttribute('mail_enabled') && $this->mail_digest->sendsMail();
    }

    /** Does this one wait for the nightly digest rather than sending now? */
    public function isBatched(): bool
    {
        return $this->sendsMail() && $this->mail_digest->isBatched();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    public function scopeForEvent(Builder $query, string $eventKey): Builder
    {
        return $query->where('event_key', $eventKey);
    }

    /** Everybody who takes this event in the nightly digest. */
    public function scopeBatched(Builder $query): Builder
    {
        return $query
            ->where('mail_enabled', true)
            ->where('mail_digest', NotificationDigest::Daily->value);
    }
}
