<?php

declare(strict_types=1);

namespace App\Models\Support;

use App\Enums\DeliveryMode;
use App\Enums\MeetingParticipantRole;
use App\Enums\MeetingResponse;
use App\Enums\MeetingStatus;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Institute\Batch;
use App\Models\Institute\Classroom;
use App\Models\Institute\Course;
use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A meeting (phase-19-23 §2.20, requirement §95).
 *
 * **`ends_at` is read, never written.** It is a generated STORED column —
 * `scheduled_at + INTERVAL duration_minutes MINUTE` — so an overlap query can use an index instead of
 * recomputing the right-hand side per row. It is absent from `$fillable` for the obvious reason and
 * absent from `$guarded`'s blind spots because `forceFill` on a generated column is an error MariaDB
 * raises rather than ignores.
 *
 * **A meeting is soft-deleted rather than protected**, unlike a ticket. The difference is what the
 * row is: a ticket is a record of what was promised to somebody, and a meeting that was cancelled
 * before anybody attended is closer to a diary entry. What *is* protected is the wording — a
 * cancellation without a reason is refused by `chk_me_cancel` at the database, because the people
 * whose afternoon was cleared are owed a sentence.
 *
 * **`quorum()` counts the roles that matter.** An optional attendee declining is not a meeting in
 * trouble; `MeetingParticipantRole::countsInQuorum()` is the one place that judgement lives.
 *
 * @property MeetingStatus $status
 * @property Carbon $scheduled_at
 */
class Meeting extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'meetings';

    /**
     * `status`, every count and `rescheduled_from_id` belong to the service. `ends_at` is generated.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'branch_id',
        'scheduled_at',
        'duration_minutes',
        'delivery_mode',
        'location',
        'classroom_id',
        'meeting_url',
        'agenda',
        'project_id',
        'course_id',
        'batch_id',
        'client_id',
        'lead_id',
        'collaborator_id',
        'support_ticket_id',
        'is_private',
        'reminder_minutes_before',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'ends_at' => 'datetime',
            'duration_minutes' => 'integer',
            'delivery_mode' => DeliveryMode::class,
            'status' => MeetingStatus::class,
            'is_private' => 'boolean',
            'reminder_minutes_before' => 'integer',
            'reminder_sent_at' => 'datetime',
            'second_reminder_sent_at' => 'datetime',
            'participants_count' => 'integer',
            'accepted_count' => 'integer',
            'declined_count' => 'integer',
            'attended_count' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'meetings';
    }

    protected function activityModule(): ?string
    {
        return 'meetings';
    }

    /*
    |--------------------------------------------------------------------------
    | Where it is in its life
    |--------------------------------------------------------------------------
    */

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    /** Scheduled, and the time has passed. What `meetings:close-past` sweeps into `missed`. */
    public function isOverdue(?Carbon $asOf = null): bool
    {
        if (! $this->status->isLive()) {
            return false;
        }

        $ends = $this->getAttribute('ends_at') ?? $this->getAttribute('scheduled_at');

        return $ends !== null && $ends->isBefore($asOf ?? Carbon::now());
    }

    /** Has anybody said yes who had to? */
    public function hasQuorum(): bool
    {
        return $this->quorum()['accepted'] > 0;
    }

    /**
     * Accepted against required, counting only the roles a quorum depends on.
     *
     * Computed from the loaded participants when they are there and queried when they are not, so a
     * list screen that eager-loaded them does not fire a query per row.
     *
     * @return array{accepted: int, needed: int}
     */
    public function quorum(): array
    {
        $roles = array_values(array_filter(
            MeetingParticipantRole::cases(),
            static fn (MeetingParticipantRole $role): bool => $role->countsInQuorum(),
        ));

        $values = array_map(static fn (MeetingParticipantRole $role): string => $role->value, $roles);

        if ($this->relationLoaded('participants')) {
            $counting = $this->participants->filter(
                static fn (MeetingParticipant $p): bool => in_array($p->getRawOriginal('role'), $values, true),
            );

            return [
                'accepted' => $counting->where('response', MeetingResponse::Accepted)->count(),
                'needed' => $counting->count(),
            ];
        }

        return [
            'accepted' => $this->participants()
                ->whereIn('role', $values)
                ->where('response', MeetingResponse::Accepted->value)
                ->count(),
            'needed' => $this->participants()->whereIn('role', $values)->count(),
        ];
    }

    /** Is this person in the room? The live question §9.4 asks, never a snapshot. */
    public function includes(User|int $user): bool
    {
        return $this->participants()
            ->where('user_id', $user instanceof User ? $user->getKey() : $user)
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Collaborator\Collaborator::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /** The meeting this one replaced. A chain, not a fan — `uq_me_resched` says so. */
    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    public function rescheduledTo(): HasOne
    {
        return $this->hasOne(self::class, 'rescheduled_from_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', MeetingStatus::Scheduled->value);
    }

    public function scopeUpcoming(Builder $query, ?Carbon $from = null): Builder
    {
        return $query->live()->where('scheduled_at', '>=', $from ?? Carbon::now());
    }

    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->where('scheduled_at', '<', $to)->where('ends_at', '>', $from);
    }

    /** Every meeting this person organises or attends — §9.4's scope for `meetings.view`. */
    public function scopeInvolving(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->where(function (Builder $outer) use ($id): void {
            $outer
                ->where('organizer_id', $id)
                ->orWhereHas('participants', fn (Builder $p) => $p->where('user_id', $id));
        });
    }
}
