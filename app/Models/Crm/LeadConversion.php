<?php

declare(strict_types=1);

namespace App\Models\Crm;

use App\Enums\LeadConversionType;
use App\Enums\LeadDuplicateMatchType;
use App\Enums\LeadStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Crm\Concerns\HasGeneratedGuards;
use App\Models\Crm\Concerns\RelatesToLaterPhases;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The immutable record of one lead conversion (phase-05 §2.4, §107).
 *
 * **Append-only evidence** (D19): no `deleted_at`. An Eloquent delete always throws, and an update may touch only
 * {@see MUTABLE_COLUMNS} — superseding the conversion, and the two links a later phase fills in (the project the
 * hand-off created, the spine referral the attribution was copied from). A superseded conversion stays
 * superseded. A wrong conversion is corrected by superseding it and recording a new one (§12.2 Q8), never by
 * editing or deleting it.
 *
 * `active_guard` (STORED, `1` while `superseded_at IS NULL`) carries `UNIQUE uq_lc_lead_active(lead_id,
 * active_guard)`: one live conversion per lead, so a double submit returns the existing row (§11 test 45). It is
 * never written by Eloquent.
 *
 * `lead_snapshot` is every §18 field at the moment of conversion; later edits to the lead or the client never
 * change it (§11 test 46). `budget_amount` is a `decimal(15,2)` snapshot string.
 *
 * Mass assignable: the snapshot fields written once at insert. The lead, the client, `converted_by`, the two
 * deferred links and the supersede pair are written by `LeadConversionService` with `forceFill()`.
 *
 * @property int $id
 * @property int $lead_id
 * @property LeadConversionType $conversion_type
 * @property int|null $client_id
 * @property bool $created_client
 * @property LeadDuplicateMatchType|null $matched_by
 * @property int|null $project_id
 * @property LeadStatus $from_status
 * @property array<string, mixed> $lead_snapshot
 * @property array<string, string>|null $field_map
 * @property string|null $budget_amount
 * @property string|null $referral_code
 * @property int|null $collaborator_referral_id
 * @property Carbon $converted_at
 * @property int|null $converted_by
 * @property string|null $notes
 * @property Carbon|null $superseded_at
 * @property string|null $supersede_reason
 * @property int|null $active_guard STORED generated — read only
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class LeadConversion extends Model
{
    use Blameable;
    use HasGeneratedGuards;
    use LogsActivityWithContext;
    use RelatesToLaterPhases;

    /** The columns §2.4 lets an update change; anything else throws. */
    public const MUTABLE_COLUMNS = [
        'superseded_at',
        'supersede_reason',
        'project_id',
        'collaborator_referral_id',
        'updated_by',
        'updated_at',
    ];

    /** Phase 6's project model (CLAUDE.md §2 `app/Models/Project/`). */
    public const PROJECT_MODEL = 'App\\Models\\Project\\Project';

    /** The spine's referral model (phase-10-12 §2: `app/Models/Collaborator/`). */
    public const COLLABORATOR_REFERRAL_MODEL = 'App\\Models\\Collaborator\\CollaboratorReferral';

    protected $table = 'lead_conversions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversion_type',
        'created_client',
        'matched_by',
        'from_status',
        'lead_snapshot',
        'field_map',
        'budget_amount',
        'referral_code',
        'converted_at',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'created_client' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lead_id' => 'integer',
            'conversion_type' => LeadConversionType::class,
            'client_id' => 'integer',
            'created_client' => 'boolean',
            'matched_by' => LeadDuplicateMatchType::class,
            'project_id' => 'integer',
            'from_status' => LeadStatus::class,
            'lead_snapshot' => 'array',
            'field_map' => 'array',
            'budget_amount' => 'decimal:2',
            'collaborator_referral_id' => 'integer',
            'converted_at' => 'datetime',
            'converted_by' => 'integer',
            'superseded_at' => 'datetime',
            'active_guard' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (LeadConversion $conversion): void {
            $illegal = array_diff(array_keys($conversion->getDirty()), self::MUTABLE_COLUMNS);

            if ($illegal !== []) {
                throw new LogicException(sprintf(
                    'Lead conversion #%s is append-only evidence (D19); %s may not change (phase-05 §2.4).',
                    (string) $conversion->getKey(),
                    implode(', ', $illegal)
                ));
            }

            if ($conversion->isDirty('superseded_at') && $conversion->getOriginal('superseded_at') !== null) {
                throw new LogicException(sprintf(
                    'Lead conversion #%s is already superseded and stays superseded (phase-05 §2.4).',
                    (string) $conversion->getKey()
                ));
            }
        });

        static::deleting(static function (LeadConversion $conversion): never {
            throw new LogicException(sprintf(
                'Lead conversion #%s is append-only evidence (no deleted_at, D19) and cannot be deleted; supersede it '
                .'instead (phase-05 §2.4).',
                (string) $conversion->getKey()
            ));
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
        return [
            'lead_id', 'conversion_type', 'client_id', 'created_client', 'matched_by', 'project_id', 'from_status',
            'budget_amount', 'referral_code', 'collaborator_referral_id', 'converted_at', 'converted_by',
            'superseded_at', 'supersede_reason',
        ];
    }

    /**
     * @return list<string>
     */
    protected function generatedGuardColumns(): array
    {
        return ['active_guard'];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Not superseded — the conversion the lead's current state rests on.
     */
    public function isActive(): bool
    {
        return $this->getAttribute('superseded_at') === null;
    }

    /**
     * The parent lead for an authorization decision: without the visibility scope and including the trash.
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
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    /**
     * The project the hand-off created (Phase 6).
     *
     * @return BelongsTo<Model, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo($this->laterPhaseModel(self::PROJECT_MODEL, 'Phase 6'), 'project_id');
    }

    /**
     * The spine referral row the attribution was copied from (Phase 10).
     *
     * @return BelongsTo<Model, $this>
     */
    public function collaboratorReferral(): BelongsTo
    {
        return $this->belongsTo(
            $this->laterPhaseModel(self::COLLABORATOR_REFERRAL_MODEL, 'the Phase 10 spine set'),
            'collaborator_referral_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<LeadConversion>  $query
     * @return Builder<LeadConversion>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('superseded_at'));
    }

    /**
     * @param  Builder<LeadConversion>  $query
     * @return Builder<LeadConversion>
     */
    public function scopeSuperseded(Builder $query): Builder
    {
        return $query->whereNotNull($query->qualifyColumn('superseded_at'));
    }
}
