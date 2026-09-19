<?php

declare(strict_types=1);

namespace App\Models\Crm;

use App\Enums\LeadContactOutcome;
use App\Enums\LeadFollowUpStatus;
use App\Enums\LeadFollowUpType;
use App\Models\Concerns\Blameable;
use App\Models\Crm\Concerns\HasGeneratedGuards;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One scheduled follow-up on a lead (phase-05 §2.3).
 *
 * **One open follow-up per lead** is a database fact: the STORED `open_guard` column is `1` only while
 * `status = pending`, under `UNIQUE uq_lfu_open(lead_id, open_guard)`. A second pending row raises 1062, which
 * `LeadFollowUpService::schedule()` turns into `FollowUpAlreadyOpenException` ([D-P5-12], §11 test 26). The
 * generated column is read-only: it is never mass assignable and never written.
 *
 * **`reminder_due_at`** is `scheduled_at - remind_before_minutes`, recomputed in PHP whenever either side changes
 * (§2.3: not a generated column). The reminder sweep reads {@see scopeDueForReminder()} under
 * `lockForUpdate()->skipLocked()` in the service.
 *
 * Mass assignable: the scheduling form (`type`, `scheduled_at`, `remind_before_minutes`, `notes`). The lead, the
 * reminder target (`assigned_to`), the status and everything the complete / reschedule / cancel / missed paths
 * stamp are written by `LeadFollowUpService` with `forceFill()`.
 *
 * @property int $id
 * @property int $lead_id
 * @property int|null $assigned_to
 * @property LeadFollowUpType $type
 * @property Carbon $scheduled_at
 * @property int $remind_before_minutes
 * @property Carbon|null $reminder_due_at
 * @property Carbon|null $reminder_sent_at
 * @property LeadFollowUpStatus $status
 * @property int|null $open_guard STORED generated — read only
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 * @property LeadContactOutcome|null $outcome
 * @property string|null $outcome_note
 * @property int|null $previous_follow_up_id
 * @property Carbon|null $rescheduled_at
 * @property string|null $cancel_reason
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class LeadFollowUp extends Model
{
    use Blameable;
    use HasGeneratedGuards;
    use SoftDeletes;

    /** The DB default of `remind_before_minutes` — the default of `crm.follow_up_reminder_minutes`. */
    public const DEFAULT_REMIND_BEFORE_MINUTES = 60;

    protected $table = 'lead_follow_ups';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'scheduled_at',
        'remind_before_minutes',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'remind_before_minutes' => self::DEFAULT_REMIND_BEFORE_MINUTES,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lead_id' => 'integer',
            'assigned_to' => 'integer',
            'type' => LeadFollowUpType::class,
            'scheduled_at' => 'datetime',
            'remind_before_minutes' => 'integer',
            'reminder_due_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'status' => LeadFollowUpStatus::class,
            'open_guard' => 'integer',
            'completed_at' => 'datetime',
            'completed_by' => 'integer',
            'outcome' => LeadContactOutcome::class,
            'previous_follow_up_id' => 'integer',
            'rescheduled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (LeadFollowUp $followUp): void {
            if (! $followUp->exists || $followUp->isDirty(['scheduled_at', 'remind_before_minutes'])) {
                $followUp->setAttribute('reminder_due_at', $followUp->computeReminderDueAt());
            }
        });
    }

    public function moduleSlug(): string
    {
        return 'leads';
    }

    /**
     * @return list<string>
     */
    protected function generatedGuardColumns(): array
    {
        return ['open_guard'];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * `scheduled_at - remind_before_minutes` (0 = remind at the scheduled time), or null without a schedule.
     */
    public function computeReminderDueAt(): ?CarbonInterface
    {
        $scheduledAt = $this->scheduled_at;

        if (! $scheduledAt instanceof CarbonInterface) {
            return null;
        }

        return $scheduledAt->copy()->subMinutes(max(0, (int) $this->getAttribute('remind_before_minutes')));
    }

    public function isOpen(): bool
    {
        return $this->status instanceof LeadFollowUpStatus && $this->status->isOpen();
    }

    /**
     * Still pending and its time has passed.
     */
    public function isOverdue(?CarbonInterface $now = null): bool
    {
        return $this->isOpen()
            && $this->scheduled_at instanceof CarbonInterface
            && $this->scheduled_at->lt($now ?? now());
    }

    public function isAssignedTo(User|int|null $user): bool
    {
        $id = $user instanceof User ? $user->getKey() : $user;
        $assignee = $this->getAttribute('assigned_to');

        return $id !== null && $assignee !== null && (int) $assignee === (int) $id;
    }

    /**
     * The parent lead for an authorization decision: without the visibility scope and including the trash, so
     * the policy decides between 403 and 404.
     */
    public function resolveLead(): ?Lead
    {
        if ($this->relationLoaded('lead') && $this->getRelation('lead') instanceof Lead) {
            /** @var Lead $loaded */
            $loaded = $this->getRelation('lead');

            if ((int) $loaded->getKey() === (int) $this->getAttribute('lead_id')) {
                return $loaded;
            }
        }

        return Lead::withoutGlobalScope(LeadVisibilityScope::class)
            ->withTrashed()
            ->find($this->getAttribute('lead_id'));
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * The person the reminder goes to.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * The follow-up this one was rescheduled from.
     *
     * @return BelongsTo<LeadFollowUp, $this>
     */
    public function previousFollowUp(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_follow_up_id')->withTrashed();
    }

    /**
     * The follow-up that replaced this one (at most one — uq_lfu_previous).
     *
     * @return HasOne<LeadFollowUp, $this>
     */
    public function nextFollowUp(): HasOne
    {
        return $this->hasOne(self::class, 'previous_follow_up_id');
    }

    /**
     * @return HasMany<LeadActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class, 'lead_follow_up_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<LeadFollowUp>  $query
     * @return Builder<LeadFollowUp>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), LeadFollowUpStatus::Pending->value);
    }

    /**
     * §6.3 `dueReminders()`: pending, not yet reminded, due at or before `$at`, oldest due first (`idx_lfu_due`).
     * The service adds `lockForUpdate()->skipLocked()` and the limit.
     *
     * @param  Builder<LeadFollowUp>  $query
     * @return Builder<LeadFollowUp>
     */
    public function scopeDueForReminder(Builder $query, CarbonInterface $at): Builder
    {
        return $query->where($query->qualifyColumn('status'), LeadFollowUpStatus::Pending->value)
            ->whereNull($query->qualifyColumn('reminder_sent_at'))
            ->whereNotNull($query->qualifyColumn('reminder_due_at'))
            ->where($query->qualifyColumn('reminder_due_at'), '<=', $at)
            ->orderBy($query->qualifyColumn('reminder_due_at'))
            ->orderBy($query->qualifyColumn('id'));
    }

    /**
     * Pending rows scheduled before `$cutoff` — `crm:follow-ups-mark-missed` passes now minus the grace window.
     *
     * @param  Builder<LeadFollowUp>  $query
     * @return Builder<LeadFollowUp>
     */
    public function scopeOverdueBefore(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query->where($query->qualifyColumn('status'), LeadFollowUpStatus::Pending->value)
            ->where($query->qualifyColumn('scheduled_at'), '<', $cutoff);
    }

    /**
     * @param  Builder<LeadFollowUp>  $query
     * @return Builder<LeadFollowUp>
     */
    public function scopeAssignedTo(Builder $query, User|int $user): Builder
    {
        return $query->where($query->qualifyColumn('assigned_to'), $user instanceof User ? $user->getKey() : $user);
    }
}
