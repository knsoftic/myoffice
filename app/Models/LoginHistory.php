<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoginStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One authentication event (phase-01 §1.6).
 *
 * Written by the auth listeners (RecordSuccessfulLogin / RecordFailedLogin / RecordLogout).
 * `user_id` is nullable because a failed attempt may not resolve to an account — `email`
 * keeps whatever was typed. Append-only: no soft deletes, never updated except to stamp
 * `logged_out_at`.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $email
 * @property LoginStatus $status
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $device
 * @property string|null $platform
 * @property string|null $browser
 * @property string|null $session_id
 * @property Carbon|null $logged_in_at
 * @property Carbon|null $logged_out_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LoginHistory extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'email',
        'status',
        'ip_address',
        'user_agent',
        'device',
        'platform',
        'browser',
        'session_id',
        'logged_in_at',
        'logged_out_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LoginStatus::class,
            'logged_in_at' => 'datetime',
            'logged_out_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isSuccessful(): bool
    {
        return $this->status === LoginStatus::Success;
    }

    /**
     * Is this the row for a session that is still open?
     */
    public function isOpen(): bool
    {
        return $this->status === LoginStatus::Success && $this->logged_out_at === null;
    }

    /**
     * "Chrome 131 on Windows 10/11" — what the history table shows.
     */
    public function deviceLabel(): string
    {
        $parts = array_values(array_filter([
            trim((string) $this->browser),
            trim((string) $this->platform),
        ], static fn (string $part): bool => $part !== ''));

        if ($parts === []) {
            $device = trim((string) $this->device);

            return $device === '' ? 'Unknown device' : $device;
        }

        return implode(' on ', $parts);
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
        return $this->belongsTo(User::class)->withTrashed();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<LoginHistory>  $query
     * @return Builder<LoginHistory>
     */
    public function scopeForUser(Builder $query, User|int|string|null $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        if ($id === null) {
            return $query->whereNull('user_id');
        }

        return $query->where('user_id', $id);
    }

    /**
     * @param  Builder<LoginHistory>  $query
     * @param  LoginStatus|string|array<int, LoginStatus|string>  $status
     * @return Builder<LoginHistory>
     */
    public function scopeWithStatus(Builder $query, LoginStatus|string|array $status): Builder
    {
        $normalise = static fn (LoginStatus|string $value): string => $value instanceof LoginStatus
            ? $value->value
            : $value;

        if (is_array($status)) {
            return $query->whereIn('status', array_map($normalise, $status));
        }

        return $query->where('status', $normalise($status));
    }

    /**
     * @param  Builder<LoginHistory>  $query
     * @return Builder<LoginHistory>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', LoginStatus::Success->value);
    }

    /**
     * Newest first — the only order the history screens use.
     *
     * @param  Builder<LoginHistory>  $query
     * @return Builder<LoginHistory>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @param  Builder<LoginHistory>  $query
     * @return Builder<LoginHistory>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $query->where(function (Builder $builder) use ($like): void {
            $builder->where('email', 'like', $like)
                ->orWhere('ip_address', 'like', $like)
                ->orWhere('platform', 'like', $like)
                ->orWhere('browser', 'like', $like)
                ->orWhereHas('user', function (Builder $userQuery) use ($like): void {
                    $userQuery->where('name', 'like', $like)->orWhere('email', 'like', $like);
                });
        });
    }
}
