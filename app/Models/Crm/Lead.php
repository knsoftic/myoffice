<?php

declare(strict_types=1);

namespace App\Models\Crm;

use App\Enums\InquirySource;
use App\Enums\LeadFollowUpStatus;
use App\Enums\LeadStatus;
use App\Models\Cms\ContactInquiry;
use App\Models\Cms\Service;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Crm\Concerns\NormalizesContacts;
use App\Models\Crm\Concerns\RelatesToLaterPhases;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One sales lead (phase-05 §2.1, requirement §18).
 *
 * **Visibility ([D-P5-8], D30).** {@see LeadVisibilityScope} is a global scope: a user without `leads.view_any`
 * only ever queries leads assigned to or created by them — on the index, the board, exports, the worklist and
 * route-model binding (a foreign id binds to nothing → 404). {@see scopeVisibleTo()} applies the same predicate
 * for a named user outside that user's request (queued exports, bulk operations).
 *
 * **Immutable number.** `lead_no` is issued once by `DocumentNumberService` (D27) and an Eloquent update that
 * changes it throws (§11 test 5). A lead cannot be linked as a duplicate of itself — refused here and by the
 * `trg_leads_not_self_duplicate_*` triggers.
 *
 * **What is not mass assignable, and why.** Every column with its own service method or its own provenance:
 * `lead_no`; `status` / `status_changed_at` / `won_at` / `lost_at` / `lost_reason` (`changeStatus()`);
 * `assigned_to` / `assigned_at` / `assigned_by` (`assign()`); `client_id` / `converted_at` / `converted_by`
 * (conversion); `follow_up_at` / `last_contacted_at` / `last_activity_at` (caches rewritten inside the owning
 * transaction); the duplicate link (`linkDuplicate()`); the referral snapshot and `referral_visit_id` ([D-P5-6],
 * never from the browser); `contact_inquiry_id` and `lead_import_id` (provenance). Services write them with
 * `forceFill()`. The three `*_normalized` columns are recomputed on every save ({@see NormalizesContacts}).
 *
 * There is no `collaborator_id` (§11 test 55, D37). `collaboratorReferrals()` is declared for the spine but is
 * read only through `ReferralRecorder` ([D-P5-1]).
 *
 * @property int $id
 * @property string $lead_no
 * @property string $name
 * @property string|null $company
 * @property string|null $email
 * @property string|null $email_normalized
 * @property string|null $phone
 * @property string|null $phone_normalized
 * @property string|null $whatsapp
 * @property string|null $whatsapp_normalized
 * @property string|null $country
 * @property string|null $country_code
 * @property int|null $service_id
 * @property string|null $interested_service
 * @property string|null $budget_amount decimal(15,2) as a string — arithmetic only through App\Support\Money
 * @property InquirySource $source
 * @property string|null $source_detail
 * @property LeadStatus $status
 * @property Carbon|null $status_changed_at
 * @property int|null $assigned_to
 * @property Carbon|null $assigned_at
 * @property int|null $assigned_by
 * @property Carbon|null $follow_up_at
 * @property Carbon|null $last_contacted_at
 * @property Carbon|null $last_activity_at
 * @property string|null $notes
 * @property string|null $lost_reason
 * @property Carbon|null $lost_at
 * @property Carbon|null $won_at
 * @property int|null $duplicate_of_lead_id
 * @property Carbon|null $duplicate_flagged_at
 * @property string|null $duplicate_note
 * @property int|null $client_id
 * @property Carbon|null $converted_at
 * @property int|null $converted_by
 * @property string|null $referral_code_captured
 * @property Carbon|null $referral_recorded_at
 * @property int|null $referral_visit_id
 * @property int|null $contact_inquiry_id
 * @property int|null $lead_import_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
#[ScopedBy([LeadVisibilityScope::class])]
class Lead extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use NormalizesContacts;
    use RelatesToLaterPhases;
    use SoftDeletes;

    /** The spine's referral model (phase-10-12 §2: `app/Models/Collaborator/`). */
    public const COLLABORATOR_REFERRAL_MODEL = 'App\\Models\\Collaborator\\CollaboratorReferral';

    protected $table = 'leads';

    /**
     * What a lead form (create, edit, import mapping) may carry. See the class docblock for the rest.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'company',
        'email',
        'phone',
        'whatsapp',
        'country',
        'country_code',
        'service_id',
        'interested_service',
        'budget_amount',
        'source',
        'source_detail',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'new',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_id' => 'integer',
            'budget_amount' => 'decimal:2',
            'source' => InquirySource::class,
            'status' => LeadStatus::class,
            'status_changed_at' => 'datetime',
            'assigned_to' => 'integer',
            'assigned_at' => 'datetime',
            'assigned_by' => 'integer',
            'follow_up_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'lost_at' => 'datetime',
            'won_at' => 'datetime',
            'duplicate_of_lead_id' => 'integer',
            'duplicate_flagged_at' => 'datetime',
            'client_id' => 'integer',
            'converted_at' => 'datetime',
            'converted_by' => 'integer',
            'referral_recorded_at' => 'datetime',
            'referral_visit_id' => 'integer',
            'contact_inquiry_id' => 'integer',
            'lead_import_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (Lead $lead): void {
            if ($lead->isDirty('lead_no') && $lead->getOriginal('lead_no') !== null) {
                throw new LogicException(sprintf(
                    'Lead #%s: lead_no is immutable once issued (phase-05 §2.1).',
                    (string) $lead->getKey()
                ));
            }
        });

        static::saving(static function (Lead $lead): void {
            $duplicateOf = $lead->getAttribute('duplicate_of_lead_id');

            if ($duplicateOf !== null && $lead->exists && (int) $duplicateOf === (int) $lead->getKey()) {
                throw new LogicException(sprintf(
                    'Lead #%s cannot be a duplicate of itself (chk_leads_not_self_duplicate).',
                    (string) $lead->getKey()
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
     * Every §18 field plus the state columns whose old/new values §107 wants (test 86: assignee, status). The
     * normalized keys and the three activity caches are housekeeping and are not logged.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'lead_no', ...$this->getFillable(),
            'status', 'lost_reason', 'assigned_to', 'duplicate_of_lead_id', 'client_id', 'converted_at',
            'converted_by', 'referral_code_captured', 'contact_inquiry_id', 'lead_import_id',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function activityIgnoredAttributes(): array
    {
        return [
            'created_at', 'updated_at', 'updated_by', 'email_normalized', 'phone_normalized', 'whatsapp_normalized',
            'follow_up_at', 'last_contacted_at', 'last_activity_at', 'status_changed_at', 'assigned_at',
            'referral_recorded_at',
        ];
    }

    /**
     * @return array<string, array{0: string, 1: 'email'|'phone'}>
     */
    protected function normalizedContactColumns(): array
    {
        return [
            'email' => ['email_normalized', 'email'],
            'phone' => ['phone_normalized', 'phone'],
            'whatsapp' => ['whatsapp_normalized', 'phone'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * §9.1 ownership: the lead is assigned to, or was created by, the user.
     */
    public function isOwnedBy(User|int|null $user): bool
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        if ($id === null) {
            return false;
        }

        foreach (['assigned_to', 'created_by'] as $column) {
            $owner = $this->getAttribute($column);

            if ($owner !== null && (int) $owner === (int) $id) {
                return true;
            }
        }

        return false;
    }

    public function isConverted(): bool
    {
        return $this->getAttribute('converted_at') !== null;
    }

    /**
     * Is a live (not superseded) conversion recorded? Leaving `won` and soft-deleting are refused while it is.
     */
    public function hasLiveConversion(): bool
    {
        return $this->activeConversion()->exists();
    }

    /**
     * §8.2 "stale": no timeline activity for longer than `$days` (the creation time stands in until the first one).
     */
    public function isStale(int $days, ?CarbonInterface $now = null): bool
    {
        $reference = $this->last_activity_at ?? $this->created_at;

        if ($reference === null || $days <= 0) {
            return false;
        }

        return $reference->lt(($now ?? now())->copy()->subDays($days));
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * §18 "interested service". A trashed service still resolves, so a renamed or retired service keeps its label.
     *
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function converter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    /**
     * The client this lead was converted onto (a trashed client still resolves for the audit view).
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id')->withTrashed();
    }

    /**
     * The original this lead was linked to. Subject to the visibility scope like every lead query.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_lead_id');
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of_lead_id');
    }

    /**
     * §17 provenance of a website lead (Phase 4 owns the table).
     *
     * @return BelongsTo<ContactInquiry, $this>
     */
    public function contactInquiry(): BelongsTo
    {
        return $this->belongsTo(ContactInquiry::class, 'contact_inquiry_id')->withTrashed();
    }

    /**
     * @return BelongsTo<LeadImport, $this>
     */
    public function leadImport(): BelongsTo
    {
        return $this->belongsTo(LeadImport::class, 'lead_import_id')->withTrashed();
    }

    /**
     * @return HasMany<LeadActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class, 'lead_id');
    }

    /**
     * @return HasMany<LeadFollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(LeadFollowUp::class, 'lead_id');
    }

    /**
     * The single `pending` follow-up — at most one exists (uq_lfu_open); `follow_up_at` caches its time.
     *
     * @return HasOne<LeadFollowUp, $this>
     */
    public function openFollowUp(): HasOne
    {
        return $this->hasOne(LeadFollowUp::class, 'lead_id')
            ->where('status', LeadFollowUpStatus::Pending->value);
    }

    /**
     * @return HasMany<LeadConversion, $this>
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(LeadConversion::class, 'lead_id');
    }

    /**
     * The live conversion — at most one exists (uq_lc_lead_active).
     *
     * @return HasOne<LeadConversion, $this>
     */
    public function activeConversion(): HasOne
    {
        return $this->hasOne(LeadConversion::class, 'lead_id')->whereNull('superseded_at');
    }

    /**
     * The spine's attribution rows for this lead (`collaborator_referrals.lead_id`). Declared for completeness;
     * CRM code reads attribution only through `ReferralRecorder` ([D-P5-1], D37).
     *
     * @return HasMany<Model, $this>
     */
    public function collaboratorReferrals(): HasMany
    {
        return $this->hasMany($this->laterPhaseModel(self::COLLABORATOR_REFERRAL_MODEL, 'the Phase 10 spine set'), 'lead_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * §9.1 for a named user — the same predicate as the global scope, for work done outside that user's request.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return LeadVisibilityScope::forUser($query, $user);
    }

    /**
     * @param  Builder<Lead>  $query
     * @param  LeadStatus|string|array<int, LeadStatus|string>  $status
     * @return Builder<Lead>
     */
    public function scopeWithStatus(Builder $query, LeadStatus|string|array $status): Builder
    {
        $values = array_map(
            static fn (LeadStatus|string $value): string => $value instanceof LeadStatus ? $value->value : $value,
            is_array($status) ? $status : [$status]
        );

        return $query->whereIn($query->qualifyColumn('status'), $values);
    }

    /**
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn(
            $query->qualifyColumn('status'),
            array_map(static fn (LeadStatus $status): string => $status->value, LeadStatus::open())
        );
    }

    /**
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function scopeAssignedTo(Builder $query, User|int $user): Builder
    {
        return $query->where($query->qualifyColumn('assigned_to'), $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('assigned_to'));
    }
}
