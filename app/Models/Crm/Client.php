<?php

declare(strict_types=1);

namespace App\Models\Crm;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Enums\InquirySource;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Crm\Concerns\NormalizesContacts;
use App\Models\Crm\Concerns\RelatesToLaterPhases;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The client master (phase-05 §2.7, requirement §19).
 *
 * **Immutable code.** `client_code` ("Client ID") is issued once by `DocumentNumberService` (D27); an Eloquent
 * update that changes it throws (§11 test 5), and its UNIQUE index spans the trash so it is never re-issued.
 * `display_name` is an accessor (`company_name ?: name`), not a column.
 *
 * **Billing address.** With `billing_same_as_address` on, `billing_address` is nulled on every save, so the two
 * fields can never disagree (§6.7, §11 test 57).
 *
 * **Portal.** `user_id` is the primary portal login (D2, UNIQUE). Access also needs `portal_enabled` and a status
 * whose `canUsePortal()` is true ({@see canUsePortal()}). `App\Support\ClientContext` resolves the signed-in user's
 * client for every `/client` request; {@see resolvePortalFor()} applies the identical rule to a named user for
 * code that is not running as that user (policies asked about another user, queued notifications).
 *
 * **What is not mass assignable, and why.** `client_code`; `user_id` and the portal pair (`enablePortal()` /
 * `disablePortal()`); `status` / `status_reason` / `status_changed_at` (`changeStatus()`); `account_manager_id`
 * (`assignAccountManager()`); `lead_id` and the referral snapshot (conversion and `ReferralRecorder`, [D-P5-6]).
 * Services write them with `forceFill()`. The client portal's profile form is narrower still — the
 * `UpdateClientProfileRequest` whitelist of §6.9 — and `notes` is internal: hidden from serialisation and never
 * rendered on the panel.
 *
 * There is no `collaborator_id` (§11 test 55, D37). The relations to Phase 6 / 10 / 13 / 22 tables are declared
 * as the contract asks but are only ever traversed through the capability contracts ([D-P5-1]).
 *
 * @property int $id
 * @property string $client_code
 * @property int|null $user_id
 * @property ClientType $client_type
 * @property string $name
 * @property string|null $company_name
 * @property string|null $email
 * @property string|null $email_normalized
 * @property string|null $phone
 * @property string|null $phone_normalized
 * @property string|null $whatsapp
 * @property string|null $whatsapp_normalized
 * @property string|null $website
 * @property string|null $industry
 * @property string|null $about
 * @property string|null $logo_path
 * @property string|null $address
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postal_code
 * @property string|null $country
 * @property string|null $country_code
 * @property bool $billing_same_as_address
 * @property string|null $billing_address
 * @property bool $tax_registered
 * @property string|null $tax_number
 * @property string|null $sales_tax_number
 * @property string|null $cnic
 * @property bool $tax_exempt
 * @property string|null $tax_rate_override decimal(8,4) percentage
 * @property string|null $withholding_tax_rate decimal(8,4) percentage
 * @property string|null $tax_notes
 * @property string|null $currency
 * @property int|null $payment_terms_days
 * @property ClientStatus $status
 * @property string|null $status_reason
 * @property Carbon|null $status_changed_at
 * @property bool $portal_enabled
 * @property Carbon|null $portal_invited_at
 * @property int|null $account_manager_id
 * @property InquirySource|null $source
 * @property int|null $lead_id
 * @property string|null $referral_code_captured
 * @property Carbon|null $referral_recorded_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property-read string $display_name
 */
class Client extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use NormalizesContacts;
    use RelatesToLaterPhases;
    use SoftDeletes;

    /** Phase 6 (CLAUDE.md §2 `app/Models/Project/`). */
    public const PROJECT_MODEL = 'App\\Models\\Project\\Project';

    /** Phase 13 (CLAUDE.md §2 `app/Models/Finance/`). */
    public const INVOICE_MODEL = 'App\\Models\\Finance\\Invoice';

    /** The spine (phase-10-12 §2 `app/Models/Finance/`). */
    public const PROJECT_PAYMENT_MODEL = 'App\\Models\\Finance\\ProjectPayment';

    /** Phase 22 (CLAUDE.md §2 `app/Models/Support/`). */
    public const SUPPORT_TICKET_MODEL = 'App\\Models\\Support\\SupportTicket';

    /** Phase 22 (CLAUDE.md §2 `app/Models/Support/`). */
    public const MEETING_MODEL = 'App\\Models\\Support\\Meeting';

    /** The spine (phase-10-12 §2 `app/Models/Collaborator/`). */
    public const COLLABORATOR_REFERRAL_MODEL = 'App\\Models\\Collaborator\\CollaboratorReferral';

    protected $table = 'clients';

    /**
     * The staff client form. See the class docblock for what is written only by the services.
     *
     * @var list<string>
     */
    protected $fillable = [
        'client_type',
        'name',
        'company_name',
        'email',
        'phone',
        'whatsapp',
        'website',
        'industry',
        'about',
        'logo_path',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'country_code',
        'billing_same_as_address',
        'billing_address',
        'tax_registered',
        'tax_number',
        'sales_tax_number',
        'cnic',
        'tax_exempt',
        'tax_rate_override',
        'withholding_tax_rate',
        'tax_notes',
        'currency',
        'payment_terms_days',
        'source',
        'notes',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'client_type' => 'company',
        'billing_same_as_address' => true,
        'tax_registered' => false,
        'tax_exempt' => false,
        'status' => 'active',
        'portal_enabled' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'client_type' => ClientType::class,
            'billing_same_as_address' => 'boolean',
            'tax_registered' => 'boolean',
            'tax_exempt' => 'boolean',
            'tax_rate_override' => 'decimal:4',
            'withholding_tax_rate' => 'decimal:4',
            'payment_terms_days' => 'integer',
            'status' => ClientStatus::class,
            'status_changed_at' => 'datetime',
            'portal_enabled' => 'boolean',
            'portal_invited_at' => 'datetime',
            'account_manager_id' => 'integer',
            'source' => InquirySource::class,
            'lead_id' => 'integer',
            'referral_recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (Client $client): void {
            if ($client->isDirty('client_code') && $client->getOriginal('client_code') !== null) {
                throw new LogicException(sprintf(
                    'Client #%s: client_code is immutable once issued (phase-05 §2.7).',
                    (string) $client->getKey()
                ));
            }
        });

        static::saving(static function (Client $client): void {
            if ((bool) $client->getAttribute('billing_same_as_address')) {
                $client->setAttribute('billing_address', null);
            }
        });
    }

    public function moduleSlug(): string
    {
        return 'clients';
    }

    protected function activityModule(): ?string
    {
        return 'clients';
    }

    /**
     * Every profile, tax and commercial field plus the state columns §107 audits with old and new values
     * (test 86: status, tax number). The normalized keys are housekeeping.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'client_code', ...$this->getFillable(),
            'user_id', 'status', 'status_reason', 'portal_enabled', 'portal_invited_at', 'account_manager_id', 'lead_id',
            'referral_code_captured',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function activityIgnoredAttributes(): array
    {
        return [
            'created_at', 'updated_at', 'updated_by', 'email_normalized', 'phone_normalized', 'whatsapp_normalized',
            'status_changed_at', 'referral_recorded_at',
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
    | Accessors and helpers
    |--------------------------------------------------------------------------
    */

    /**
     * §2.7: the company name when there is one, otherwise the person's name.
     *
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(function (): string {
            $company = trim((string) $this->getAttribute('company_name'));

            return $company !== '' ? $company : (string) $this->getAttribute('name');
        });
    }

    /**
     * May this client's logins reach the panel right now? Portal switched on, a status that allows it, not trashed.
     * `EnsureClientContext` re-evaluates this on every `/client` request (§9.2).
     */
    public function canUsePortal(): bool
    {
        $status = $this->status;

        return (bool) $this->getAttribute('portal_enabled')
            && $status instanceof ClientStatus
            && $status->canUsePortal()
            && ! $this->trashed();
    }

    /**
     * The client a named user may use the portal as, or null — the rule `App\Support\ClientContext` applies to the
     * signed-in user (§6.9), for code that is not running as that user:
     *
     *   1. `clients.user_id = user` — when that binding exists it is the only candidate ("primary binding wins"),
     *      so a revoked company never falls through to a second binding;
     *   2. otherwise `client_contacts.user_id = user AND portal_access = 1`;
     * and the resolved client must pass {@see canUsePortal()}. Trashed clients and contacts bind nothing.
     */
    public static function resolvePortalFor(User|int $user): ?self
    {
        $userId = (int) ($user instanceof User ? $user->getKey() : $user);

        if ($userId <= 0) {
            return null;
        }

        $primary = static::query()->where('user_id', $userId)->first();

        if ($primary instanceof self) {
            return $primary->canUsePortal() ? $primary : null;
        }

        $contact = ClientContact::query()
            ->where('user_id', $userId)
            ->where('portal_access', true)
            ->first();

        if (! $contact instanceof ClientContact) {
            return null;
        }

        $client = static::query()->whereKey($contact->getAttribute('client_id'))->first();

        return $client instanceof self && $client->canUsePortal() ? $client : null;
    }

    public function isPrimaryPortalUser(User|int|null $user): bool
    {
        $id = $user instanceof User ? $user->getKey() : $user;
        $bound = $this->getAttribute('user_id');

        return $id !== null && $bound !== null && (int) $bound === (int) $id;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * The primary portal login (D2).
     *
     * @return BelongsTo<User, $this>
     */
    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * §94 client <-> project manager.
     *
     * @return BelongsTo<User, $this>
     */
    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    /**
     * The lead this client was first converted from. Subject to the lead visibility scope.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function originLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * @return HasMany<ClientContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class, 'client_id');
    }

    /**
     * The primary contact — at most one (uq_cc_primary).
     *
     * @return HasOne<ClientContact, $this>
     */
    public function primaryContact(): HasOne
    {
        return $this->hasOne(ClientContact::class, 'client_id')->where('is_primary', true);
    }

    /**
     * Contacts who may sign in to the panel as this client.
     *
     * @return HasMany<ClientContact, $this>
     */
    public function portalContacts(): HasMany
    {
        return $this->hasMany(ClientContact::class, 'client_id')
            ->where('portal_access', true)
            ->whereNotNull('user_id');
    }

    /**
     * @return HasMany<ClientDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(ClientDocument::class, 'client_id');
    }

    /**
     * Leads converted onto this client. Subject to the lead visibility scope.
     *
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'client_id');
    }

    /**
     * @return HasMany<LeadConversion, $this>
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(LeadConversion::class, 'client_id');
    }

    /**
     * Phase 6. Reached only through the Phase 6 capability contract and `ClientPortalRegistry` ([D-P5-1]).
     *
     * @return HasMany<Model, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany($this->laterPhaseModel(self::PROJECT_MODEL, 'Phase 6'), 'client_id');
    }

    /**
     * Phase 13. Reached only through its read model ([D-P5-1]).
     *
     * @return HasMany<Model, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany($this->laterPhaseModel(self::INVOICE_MODEL, 'Phase 13'), 'client_id');
    }

    /**
     * The spine's received project payments. Reached only through its read model ([D-P5-1]).
     *
     * @return HasMany<Model, $this>
     */
    public function projectPayments(): HasMany
    {
        return $this->hasMany($this->laterPhaseModel(self::PROJECT_PAYMENT_MODEL, 'the Phase 10 spine set'), 'client_id');
    }

    /**
     * Phase 22. Reached only through its `ClientPortalSection` ([D-P5-1]).
     *
     * @return HasMany<Model, $this>
     */
    public function supportTickets(): HasMany
    {
        return $this->hasMany($this->laterPhaseModel(self::SUPPORT_TICKET_MODEL, 'Phase 22'), 'client_id');
    }

    /**
     * Phase 22. Reached only through its `ClientPortalSection` ([D-P5-1]).
     *
     * @return HasMany<Model, $this>
     */
    public function meetings(): HasMany
    {
        return $this->hasMany($this->laterPhaseModel(self::MEETING_MODEL, 'Phase 22'), 'client_id');
    }

    /**
     * The spine's attribution rows for this client (`collaborator_referrals.client_id`). Read only through
     * `ReferralRecorder` (D37).
     *
     * @return HasMany<Model, $this>
     */
    public function collaboratorReferrals(): HasMany
    {
        return $this->hasMany($this->laterPhaseModel(self::COLLABORATOR_REFERRAL_MODEL, 'the Phase 10 spine set'), 'client_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<Client>  $query
     * @param  ClientStatus|string|array<int, ClientStatus|string>  $status
     * @return Builder<Client>
     */
    public function scopeWithStatus(Builder $query, ClientStatus|string|array $status): Builder
    {
        $values = array_map(
            static fn (ClientStatus|string $value): string => $value instanceof ClientStatus ? $value->value : $value,
            is_array($status) ? $status : [$status]
        );

        return $query->whereIn($query->qualifyColumn('status'), $values);
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public function scopeManagedBy(Builder $query, User|int $user): Builder
    {
        return $query->where($query->qualifyColumn('account_manager_id'), $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public function scopePortalEnabled(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('portal_enabled'), true);
    }
}
