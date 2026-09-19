<?php

declare(strict_types=1);

namespace App\Models\Crm;

use App\Enums\LeadActivityType;
use App\Enums\LeadContactOutcome;
use App\Enums\LeadStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One row of a lead's timeline (phase-05 §2.2).
 *
 * **System rows are sealed.** A row whose type is a system type is always stored with `is_system = true`, and a
 * system row can be neither updated nor deleted through Eloquent (§2.2 "policy + model hook", §11 test 32). Only
 * a person's own manual note is editable, inside `crm.activity_edit_window_minutes` — that rule lives in
 * `LeadActivityPolicy` and `LeadService::updateActivity()`.
 *
 * **Isolation.** A timeline row is visible exactly when its lead is: `LeadActivityPolicy` resolves the parent with
 * {@see resolveLead()} and delegates to `LeadPolicy::view()` (404 for a lead outside §9's scope).
 *
 * **Audit.** Creating a row is itself the record, so only `updated` and `deleted` reach `activity_log`, with the
 * old and new values (§11 test 33).
 *
 * Mass assignable: the fields of the manual activity form. The lead, `is_system`, the status / user pairs, the
 * follow-up link and the related lead are written by the services with `forceFill()`.
 *
 * @property int $id
 * @property int $lead_id
 * @property LeadActivityType $type
 * @property bool $is_system
 * @property string|null $subject
 * @property string|null $body
 * @property LeadContactOutcome|null $outcome
 * @property int|null $duration_minutes
 * @property LeadStatus|null $from_status
 * @property LeadStatus|null $to_status
 * @property int|null $from_user_id
 * @property int|null $to_user_id
 * @property int|null $lead_follow_up_id
 * @property int|null $related_lead_id
 * @property Carbon $occurred_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class LeadActivity extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    /**
     * Creation is the record itself; edits and removals are the audit events (spatie `LogsActivity`).
     *
     * @var list<string>
     */
    protected static $recordEvents = ['updated', 'deleted'];

    protected $table = 'lead_activities';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'subject',
        'body',
        'outcome',
        'duration_minutes',
        'occurred_at',
        'meta',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_system' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lead_id' => 'integer',
            'type' => LeadActivityType::class,
            'is_system' => 'boolean',
            'outcome' => LeadContactOutcome::class,
            'duration_minutes' => 'integer',
            'from_status' => LeadStatus::class,
            'to_status' => LeadStatus::class,
            'from_user_id' => 'integer',
            'to_user_id' => 'integer',
            'lead_follow_up_id' => 'integer',
            'related_lead_id' => 'integer',
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (LeadActivity $activity): void {
            if ($activity->getAttribute('occurred_at') === null) {
                $activity->setAttribute('occurred_at', now());
            }

            $type = $activity->type;

            if ($type instanceof LeadActivityType && $type->isSystem()) {
                $activity->setAttribute('is_system', true);
            }
        });

        static::updating(static function (LeadActivity $activity): void {
            if ((bool) $activity->getOriginal('is_system')) {
                throw new LogicException(sprintf(
                    'Lead activity #%s is a system timeline row and cannot be edited (phase-05 §2.2).',
                    (string) $activity->getKey()
                ));
            }
        });

        static::deleting(static function (LeadActivity $activity): void {
            if ((bool) $activity->getOriginal('is_system') || (bool) $activity->getAttribute('is_system')) {
                throw new LogicException(sprintf(
                    'Lead activity #%s is a system timeline row and cannot be deleted (phase-05 §2.2).',
                    (string) $activity->getKey()
                ));
            }
        });
    }

    public function moduleSlug(): string
    {
        return 'leads';
    }

    protected function activityModule(): ?string
    {
        return 'leads';
    }

    /**
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['lead_id', ...$this->getFillable()];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isSystem(): bool
    {
        return (bool) $this->getAttribute('is_system');
    }

    public function isAuthoredBy(User|int|null $user): bool
    {
        $id = $user instanceof User ? $user->getKey() : $user;
        $author = $this->getAttribute('created_by');

        return $id !== null && $author !== null && (int) $author === (int) $id;
    }

    /**
     * Is the author's edit window (`crm.activity_edit_window_minutes`, measured from creation) still open?
     */
    public function isWithinEditWindow(int $minutes, ?CarbonInterface $now = null): bool
    {
        $createdAt = $this->created_at;

        if ($createdAt === null || $minutes <= 0) {
            return false;
        }

        return $createdAt->copy()->addMinutes($minutes)->gte($now ?? now());
    }

    /**
     * The parent lead for an authorization decision: without the visibility scope and including the trash, so
     * the policy — not an absent row — decides between 403 and 404.
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
     * @return BelongsTo<LeadFollowUp, $this>
     */
    public function followUp(): BelongsTo
    {
        return $this->belongsTo(LeadFollowUp::class, 'lead_follow_up_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /**
     * The other lead on a `duplicate_linked` row. Subject to the visibility scope.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function relatedLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'related_lead_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * The timeline order (`idx_la_feed`): newest first, id as the tie-breaker.
     *
     * @param  Builder<LeadActivity>  $query
     * @return Builder<LeadActivity>
     */
    public function scopeFeed(Builder $query): Builder
    {
        return $query->orderByDesc($query->qualifyColumn('occurred_at'))->orderByDesc($query->qualifyColumn('id'));
    }

    /**
     * @param  Builder<LeadActivity>  $query
     * @param  LeadActivityType|string|array<int, LeadActivityType|string>  $type
     * @return Builder<LeadActivity>
     */
    public function scopeOfType(Builder $query, LeadActivityType|string|array $type): Builder
    {
        $values = array_map(
            static fn (LeadActivityType|string $value): string => $value instanceof LeadActivityType ? $value->value : $value,
            is_array($type) ? $type : [$type]
        );

        return $query->whereIn($query->qualifyColumn('type'), $values);
    }

    /**
     * @param  Builder<LeadActivity>  $query
     * @return Builder<LeadActivity>
     */
    public function scopeManual(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_system'), false);
    }
}
