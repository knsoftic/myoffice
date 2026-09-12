<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Device;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A row of Laravel's `sessions` table (database session driver).
 *
 * Read-only by contract (phase-01 §1.8): the session-management UI lists the current user's
 * sessions and revokes them by deleting rows — nothing is ever mass-assigned here, so the
 * model keeps Eloquent's default total guard.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $payload
 * @property int $last_activity
 */
class Session extends Model
{
    protected $table = 'sessions';

    /**
     * The primary key is the session id string.
     */
    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * The table has no created_at / updated_at — `last_activity` is a unix timestamp.
     */
    public $timestamps = false;

    /**
     * The serialized session payload is never needed by the UI.
     *
     * @var list<string>
     */
    protected $hidden = [
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_activity' => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * When the session was last used, in the application timezone (the column is a unix
     * timestamp, unlike every other datetime in the schema).
     */
    public function lastActiveAt(): Carbon
    {
        return Carbon::createFromTimestamp((int) $this->last_activity)
            ->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /**
     * Is this the session making the current request? (never offer to revoke it silently)
     */
    public function isCurrent(): bool
    {
        try {
            return (string) $this->getKey() === (string) session()->getId();
        } catch (Throwable) {
            // No session store bound (console / API request).
            return false;
        }
    }

    /**
     * desktop | mobile | tablet | bot | unknown
     */
    public function device(): string
    {
        return Device::device((string) $this->user_agent);
    }

    public function platform(): string
    {
        return Device::parse((string) $this->user_agent)['platform'];
    }

    public function browser(): string
    {
        return Device::parse((string) $this->user_agent)['browser'];
    }

    /**
     * "Chrome 131 on Windows 10/11".
     */
    public function deviceLabel(): string
    {
        $parsed = Device::parse((string) $this->user_agent);

        $parts = array_values(array_filter(
            [$parsed['browser'], $parsed['platform']],
            static fn (string $part): bool => $part !== '' && $part !== Device::UNKNOWN
        ));

        return $parts === [] ? 'Unknown device' : implode(' on ', $parts);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<Session>  $query
     * @return Builder<Session>
     */
    public function scopeForUser(Builder $query, User|int|string $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * Most recently used first.
     *
     * @param  Builder<Session>  $query
     * @return Builder<Session>
     */
    public function scopeRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('last_activity');
    }
}
