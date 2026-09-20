<?php

declare(strict_types=1);

namespace App\Models\Collaborator;

use App\Enums\CollaborationType;
use App\Enums\CollaboratorStatus;
use App\Enums\ReferralStatus;
use App\Models\Cms\Service;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Project\Project;
use App\Models\Project\Task;
use App\Models\User;
use App\Services\Collaborator\Exceptions\ReferralCodeLockedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A business partner (phase-08-09 §2.1, requirements §33, §34).
 *
 * **Separate from `users` (D2).** A collaborator exists before, and sometimes without, a login: an
 * application arrives, a profile is built, and the account is provisioned at approval. `user_id` is
 * nullable and unique — one panel account per partner, and a partner with none is perfectly legal.
 *
 * **Two codes, both immutable, for different reasons.** `collaborator_code` is issued once and quoted in
 * commission disputes (INV-C1). `referral_code` starts equal to it and is the **public lookup key**; it
 * stops being changeable the moment anything references the collaborator (INV-C2), because every
 * attribution row snapshotted it and would otherwise start naming something else.
 *
 * **This model holds no money.** Every balance, earning and payout figure comes from the commission
 * spine's services (INV-C7); there is no `SUM(` anywhere in this class, and no `commission_eligible`
 * column — `status->earnsCommission()` is the single expression of eligibility (INV-C4).
 *
 * @property int $id
 * @property string $collaborator_code
 * @property string $referral_code
 * @property int|null $user_id
 * @property string $name
 * @property CollaborationType $collaboration_type
 * @property CollaboratorStatus $status
 * @property Carbon|null $joining_date
 */
class Collaborator extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'collaborators';

    /**
     * What a collaborator form may carry. `collaborator_code`, `referral_code`, `status`, `user_id` and
     * the four stamp columns each have their own service method and are written with `forceFill()`
     * there — a form that could reach any of them would be a form that could rewrite history.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'company_name',
        'photo_path',
        'email',
        'phone',
        'whatsapp',
        'country',
        'address',
        'collaboration_type',
        'joining_date',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'collaboration_type' => CollaborationType::class,
            'status' => CollaboratorStatus::class,
            'joining_date' => 'date',
            'status_changed_at' => 'datetime',
            'status_changed_by' => 'integer',
            'applied_at' => 'datetime',
            'approved_at' => 'datetime',
            'approved_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (Collaborator $collaborator): void {
            // getRawOriginal(), not getOriginal(): the latter applies casts, and a compared enum never
            // equals the string beside it.
            if ($collaborator->isDirty('collaborator_code')
                && $collaborator->getRawOriginal('collaborator_code') !== null) {
                throw new LogicException(sprintf(
                    'Collaborator #%s: collaborator_code is issued once and is quoted in commission '
                    .'disputes (phase-08-09 INV-C1).',
                    (string) $collaborator->getKey()
                ));
            }

            // The referral code is changeable, but only through CollaboratorCodeService, which checks
            // whether anything references it first (INV-C2). Reaching it any other way is a mistake.
            if ($collaborator->isDirty('referral_code')
                && $collaborator->getRawOriginal('referral_code') !== null
                && ! $collaborator->referralCodeChangeIsAuthorised) {
                throw ReferralCodeLockedException::because(
                    (string) $collaborator->getRawOriginal('referral_code'),
                    'a referral code changes only through CollaboratorCodeService::changeReferralCode()'
                );
            }
        });

        // INV-C5, at the model rather than only in the policy. `Gate::before` allows a Super Admin
        // everything and never reaches `CollaboratorPolicy::forceDelete()`, so a policy alone would be a
        // guarantee with an exception in it — and the one person able to make that mistake is the one
        // whose mistakes nothing else catches. The RESTRICT foreign keys from the ledger, the payouts
        // and the attributions are the last line; this is the one that says why.
        static::deleting(static function (Collaborator $collaborator): void {
            if (! $collaborator->isForceDeleting()) {
                return;
            }

            throw new LogicException(sprintf(
                'Collaborator %s is never destroyed. Commission entries, payouts and attributions all '
                .'point back at this row, and deleting it would make those unreadable. Deactivate them '
                .'instead — the commission engine already treats an inactive partner as earning nothing '
                .'(phase-08-09 INV-C5).',
                (string) $collaborator->collaborator_code,
            ));
        });
    }

    /**
     * Set by `CollaboratorCodeService` for the one save that legitimately moves the referral code.
     */
    public bool $referralCodeChangeIsAuthorised = false;

    public function moduleSlug(): string
    {
        return 'collaborators';
    }

    protected function activityModule(): ?string
    {
        return 'collaborators';
    }

    /**
     * Every activity row about a collaborator is stamped with their id, so §60's feed is a filtered view
     * over the one audit store rather than a second table (D13, §2.5).
     */
    protected function activityCollaboratorId(): ?int
    {
        $id = $this->getKey();

        return $id === null ? null : (int) $id;
    }

    /**
     * The default is `$fillable`, which would log the profile and miss everything that matters. The four
     * columns below are not fillable **because** they each have their own service method, and they are
     * exactly the ones a commission dispute asks about: the public code, the state that decides whether
     * money is earned, the reason it changed, and who got a login.
     *
     * `collaborator_code` is absent because it cannot change (INV-C1), and `notes` is absent because it
     * is internal and is never shown in the partner's own feed.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'name', 'company_name', 'photo_path', 'email', 'phone', 'whatsapp', 'country', 'address',
            'collaboration_type', 'joining_date',
            'referral_code', 'status', 'status_reason', 'user_id', 'approved_at', 'approved_by',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Questions the rest of the phase asks
    |--------------------------------------------------------------------------
    */

    /**
     * Is this partner currently earning? The commission engine asks this and nothing else (INV-C4).
     */
    public function earnsCommission(): bool
    {
        return $this->status->earnsCommission() && ! $this->trashed();
    }

    /**
     * May this partner reach their panel?
     */
    public function canLogin(): bool
    {
        return $this->user_id !== null && $this->status->canLogin() && ! $this->trashed();
    }

    /**
     * The name a screen shows: the company when there is one, because that is how the business refers to
     * an agency, with the contact person beside it.
     */
    public function displayName(): string
    {
        return $this->company_name !== null && $this->company_name !== ''
            ? $this->company_name
            : $this->name;
    }

    /**
     * A `pending` application older than the business's alert window.
     */
    public function isStaleApplication(): bool
    {
        if ($this->status !== CollaboratorStatus::Pending || $this->applied_at === null) {
            return false;
        }

        $days = (int) setting('collaborator.pending_application_alert_days', 3);

        return $this->applied_at->lessThan(now()->subDays($days));
    }

    /**
     * The collaborators a user may see. There is no per-row ownership here: a collaborator is visible to
     * anybody who may see the module, and the narrow picker endpoint of §9 is what a Sales Executive
     * uses instead of the full list.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->can('collaborators.view_any')) {
            return $query;
        }

        // A collaborator viewing their own record through the panel.
        return $query->where('user_id', $user->getKey());
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (§2.1)
    |--------------------------------------------------------------------------
    */

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function statusChanger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function skills(): HasMany
    {
        return $this->hasMany(CollaboratorSkill::class, 'collaborator_id')->orderBy('sort_order');
    }

    /**
     * The services this partner offers, out of Phase 4's catalogue (§2.3).
     *
     * **Not `withTimestamps()`.** The pivot has a `created_at` and deliberately no `updated_at` — the row
     * has nothing to update — and `withTimestamps()` would add `updated_at` to the pivot columns and then
     * write it, because it takes that column name from the *parent* model. `CollaboratorService::syncServices()`
     * supplies the stamp instead.
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'collaborator_service')
            ->withPivot('created_at');
    }

    public function referralVisits(): HasMany
    {
        return $this->hasMany(CollaboratorReferralVisit::class, 'collaborator_id');
    }

    /**
     * Projects this partner works **on** — through Phase 6's `project_members`, because there is no
     * `project_collaborator` table (F-2.7). Different from the projects they *referred*, which the
     * spine's attribution rows answer.
     */
    public function assignedProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')
            ->wherePivotNull('deleted_at')
            ->wherePivotNotNull('collaborator_id');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_collaborator_id');
    }

    /*
    |--------------------------------------------------------------------------
    | The commission spine (phase-10-12 §2.3)
    |--------------------------------------------------------------------------
    |
    | Every edge below RESTRICTs back to this row, which is what INV-C5 rests on:
    | a partner with financial history is never destroyed, because the ledger,
    | the payouts and the attributions would all stop being explainable.
    |
    */

    /**
     * The cache of everything this partner has earned. One per collaborator, created by the observer in
     * the same transaction as the record itself (INV-C3) — never lazily by a payment path.
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(CollaboratorWallet::class, 'collaborator_id');
    }

    public function commissionRules(): HasMany
    {
        return $this->hasMany(CollaboratorCommissionSetting::class, 'collaborator_id');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(CollaboratorCommissionEntitlement::class, 'collaborator_id');
    }

    /**
     * The spine itself. **Nothing outside `CollaboratorWalletService` and `CollaboratorStatementService`
     * may sum this** (INV-26): a balance computed in a report is a second definition of what somebody
     * is owed, and two definitions eventually disagree.
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CollaboratorCommissionLedgerEntry::class, 'collaborator_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(CollaboratorReferral::class, 'collaborator_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(CollaboratorPayout::class, 'collaborator_id');
    }

    public function payoutAccounts(): HasMany
    {
        return $this->hasMany(CollaboratorPayoutAccount::class, 'collaborator_id');
    }

    public function payoutAllocations(): HasMany
    {
        return $this->hasMany(CollaboratorPayoutAllocation::class, 'collaborator_id');
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(CollaboratorWalletReconciliation::class, 'collaborator_id');
    }

    /**
     * The projects this partner **referred**, through the attribution rows — different from
     * {@see assignedProjects()}, which is the projects they work on. Only `active` rows count, because
     * a superseded attribution credits nobody.
     */
    public function referredProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'collaborator_referrals', 'collaborator_id', 'project_id')
            ->wherePivot('status', ReferralStatus::Active->value)
            ->withPivot(['referral_code', 'referral_date', 'effective_from', 'effective_to', 'commission_eligible']);
    }

    public function referredClients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'collaborator_referrals', 'collaborator_id', 'client_id')
            ->wherePivot('status', ReferralStatus::Active->value)
            ->withPivot(['referral_code', 'referral_date', 'effective_from', 'effective_to', 'commission_eligible']);
    }

    public function referredLeads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'collaborator_referrals', 'collaborator_id', 'lead_id')
            ->wherePivot('status', ReferralStatus::Active->value)
            ->withPivot(['referral_code', 'referral_date', 'effective_from', 'effective_to', 'commission_eligible']);
    }
}
