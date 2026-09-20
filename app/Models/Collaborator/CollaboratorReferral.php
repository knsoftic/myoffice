<?php

declare(strict_types=1);

namespace App\Models\Collaborator;

use App\Enums\CommissionScope;
use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use App\Enums\ReferralSubject;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\FinancialRow;
use App\Models\Concerns\HasGeneratedColumns;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Project\Project;
use App\Models\User;
use App\Services\Collaborator\ReferralService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Who referred whom, on what authority, and for which window of time (finance spine §2.8).
 *
 * **Versioned, not overwritten.** Changing a subject's collaborator supersedes: the old row keeps a
 * closed window and gains a forward pointer, so a ledger entry can always prove which attribution
 * caused it even after an admin switches partners. **No existing ledger row is ever re-pointed**
 * (INV-18) — that is the difference between an audit trail and a current-value column.
 *
 * **`effectiveOn()`, not `current()`, is what the engine calls.** A payment is resolved against its own
 * value date, so a receipt back-dated into a previous partner's window still credits that partner.
 * Asking "who is the current collaborator" would quietly re-attribute every back-dated receipt.
 *
 * `superseded_by_id` is a **navigation pointer, not a guarantee** (ND-12): one winner may supersede
 * several rows, and nothing in this class may assume otherwise.
 *
 * @property ReferralSubject $subject_type
 * @property ReferralStatus $status
 */
class CollaboratorReferral extends Model
{
    use Blameable;
    use FinancialRow;
    use HasGeneratedColumns;
    use LogsActivityWithContext;

    protected $table = 'collaborator_referrals';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collaborator_id' => 'integer',
            'subject_type' => ReferralSubject::class,
            'student_id' => 'integer',
            'project_id' => 'integer',
            'client_id' => 'integer',
            'lead_id' => 'integer',
            'commission_for' => CommissionScope::class,
            'referral_source' => ReferralSource::class,
            'referral_date' => 'immutable_date',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'commission_eligible' => 'boolean',
            'status' => ReferralStatus::class,
            'referral_visit_id' => 'integer',
            'previous_referral_id' => 'integer',
            'superseded_by_id' => 'integer',
            'superseded_at' => 'immutable_datetime',
            'changed_by' => 'integer',
        ];
    }

    /**
     * The IP and the user agent are evidence the register may show; they are never part of an activity
     * diff, because an attribution change is about *who*, not about which browser somebody used.
     *
     * @var list<string>
     */
    protected $hidden = ['user_agent'];

    /**
     * Carries the four `uq_cr_*_current` indexes — `1` while the row is active, NULL otherwise, so superseded rows stack freely beneath a unique index.
     *
     * @return list<string>
     */
    public function generatedColumns(): array
    {
        return ['current_guard'];
    }

    public function moduleSlug(): string
    {
        return 'collaborator_referrals';
    }

    protected function activityModule(): ?string
    {
        return 'collaborator_referrals';
    }

    /**
     * Only what **closes** a row. The collaborator, the subject, the code, the source and the start date
     * are what the attribution *is*, and changing one would rewrite the meaning of every commission it
     * has already earned.
     *
     * @return list<string>
     */
    protected function mutableColumns(): array
    {
        return [
            'status', 'effective_to', 'commission_eligible',
            'superseded_by_id', 'superseded_at', 'change_reason', 'changed_by', 'notes',
        ];
    }

    protected function owningService(): string
    {
        return 'App\\Services\\Collaborator\\ReferralService';
    }

    protected function noDeleteMessage(): string
    {
        return 'An attribution is superseded, never deleted: a commission entry has to stay '
            .'explainable years after the partner changed (INV-R4).';
    }

    /*
    |--------------------------------------------------------------------------
    | Questions the engine asks
    |--------------------------------------------------------------------------
    */

    /**
     * Does this row credit its collaborator for a payment dated `$on`?
     *
     * Three things at once: the window covers the date, the row is not revoked, and commission has not
     * been switched off for it. `commission_eligible = false` stops **future** commission while keeping
     * every past entry, which is what a partner who left on good terms looks like.
     */
    public function coversDate(Carbon $on): bool
    {
        $date = $on->copy()->startOfDay();

        if ($this->effective_from->greaterThan($date)) {
            return false;
        }

        if ($this->effective_to !== null && $this->effective_to->lessThan($date)) {
            return false;
        }

        return $this->status !== ReferralStatus::Revoked && $this->commission_eligible;
    }

    /**
     * The subject's id, whichever of the four columns carries it. `chk_cr_one_subject` guarantees
     * exactly one is set.
     */
    public function subjectId(): ?int
    {
        $value = $this->getAttribute($this->subject_type->column());

        return $value === null ? null : (int) $value;
    }

    /**
     * Rows whose **window** contains this date, whatever they say about earning.
     *
     * The date predicate lives here once and nowhere else, and it deliberately filters on nothing but
     * the dates: the resolver needs to *see* a revoked row covering the day in order to let it block an
     * older window that also covers it. A query that hid revoked rows would silently fall through to a
     * predecessor whose credit had already been decided away.
     */
    public function scopeCoveringDate(Builder $query, Carbon $date): Builder
    {
        $on = $date->copy()->startOfDay()->toDateString();

        return $query
            ->whereDate('effective_from', '<=', $on)
            ->where(static fn (Builder $inner): Builder => $inner
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $on));
    }

    /**
     * Rows that could govern a payment on this date — the window, plus the two things that stop a row
     * earning at all. The reason `idx_cr_student_from` and `idx_cr_project_from` exist.
     *
     * {@see ReferralService::effectiveOnSubject()} deliberately does not
     * use this: it needs the losing rows too, so that the newest decision about a day wins even when
     * that decision was "nobody".
     */
    public function scopeEffectiveOn(Builder $query, Carbon $date): Builder
    {
        return $query
            ->coveringDate($date)
            ->where('commission_eligible', true)
            ->whereIn('status', [ReferralStatus::Active->value, ReferralStatus::Superseded->value]);
    }

    /**
     * The one row occupying a subject's active slot. Useful for a screen; **not** what the engine calls.
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', ReferralStatus::Active->value);
    }

    public function scopeForSubject(Builder $query, ReferralSubject $type, int $id): Builder
    {
        return $query->where('subject_type', $type->value)->where($type->column(), $id);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (spine §2.8)
    |--------------------------------------------------------------------------
    */

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class, 'collaborator_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(CollaboratorReferralVisit::class, 'referral_visit_id');
    }

    public function previousReferral(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_referral_id');
    }

    /**
     * The row that replaced this one. `belongsTo`, not `hasOne`: several rows may point at one winner
     * (ND-12), so the edge is only meaningful in this direction.
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /**
     * Every row this one superseded — the losing candidates and the predecessor together.
     */
    public function superseded(): HasMany
    {
        return $this->hasMany(self::class, 'superseded_by_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(CollaboratorCommissionEntitlement::class, 'collaborator_referral_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CollaboratorCommissionLedgerEntry::class, 'collaborator_referral_id');
    }
}
