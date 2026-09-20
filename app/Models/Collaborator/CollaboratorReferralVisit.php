<?php

declare(strict_types=1);

namespace App\Models\Collaborator;

use App\Enums\ReferralConversionSubject;
use App\Enums\ReferralVisitOutcome;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One visitor who arrived through a referral URL (phase-08-09 §2.4, requirement §38).
 *
 * **`visit_token` is the only referral value that ever travels through the browser** (INV-R2). The
 * cookie carries it, the hidden form field carries it, and the server re-resolves the code from this
 * row. A forged token can therefore at worst name a visit that does not exist.
 *
 * The row is kept **even when the code did not resolve**, with the reason in `outcome`: "forty-one
 * people used a code that no longer exists" is a thing a business needs to be told, and a missing row
 * cannot tell it.
 *
 * One row per visitor-code pair, not per page view — `visits_count` is what grows.
 *
 * **Append-only evidence** (D19): no `deleted_at`, pruned by retention, and never pruned while anything
 * still points at it (INV-R6).
 *
 * @property string $visit_token
 * @property string $referral_code
 * @property ReferralVisitOutcome $outcome
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon $expires_at
 */
class CollaboratorReferralVisit extends Model
{
    protected $table = 'collaborator_referral_visits';

    /**
     * Deliberately empty: every column here is written by `ReferralTrackingService` from the request,
     * never from a form. There is no user-facing create path at all.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'outcome' => 'captured',
        'visits_count' => 1,
        'is_bot' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collaborator_id' => 'integer',
            'outcome' => ReferralVisitOutcome::class,
            'is_bot' => 'boolean',
            'user_id' => 'integer',
            'visits_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'expires_at' => 'datetime',
            'converted_at' => 'datetime',
            'converted_subject_type' => ReferralConversionSubject::class,
            'converted_subject_id' => 'integer',
            'converted_referral_id' => 'integer',
        ];
    }

    /**
     * The session id never leaves the server: it is kept only so a later request in the same session can
     * be tied to the visit, and nothing on a screen or in an export has any use for it. The IP is **not**
     * hidden here — §8.6 shows it, masked to `/24` unless the viewer holds
     * `collaborator_referral_visits.view_logs`, and that masking belongs to the screen, not the model.
     *
     * @var list<string>
     */
    protected $hidden = ['session_id'];

    /*
    |--------------------------------------------------------------------------
    | Questions the resolver asks
    |--------------------------------------------------------------------------
    */

    /**
     * Can this visit still win an attribution?
     *
     * Three conditions, all of them necessary: it resolved to somebody, it has not expired, and it was
     * not a crawler. An expired visit is kept as evidence and can never attribute.
     */
    public function isAttributable(): bool
    {
        return $this->outcome->attributable()
            && $this->collaborator_id !== null
            && ! $this->is_bot
            && $this->expires_at->isFuture();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function hasConverted(): bool
    {
        return $this->converted_at !== null;
    }

    /**
     * Visits that could still win, newest or oldest first depending on the attribution model.
     */
    public function scopeAttributable(Builder $query): Builder
    {
        return $query
            ->whereIn('outcome', array_column(ReferralVisitOutcome::attributableCases(), 'value'))
            ->whereNotNull('collaborator_id')
            ->where('is_bot', false)
            ->where('expires_at', '>', now());
    }

    /**
     * Is this visit still evidence something else depends on? A visit the retention sweep must not touch
     * (INV-R6) — the sweep asks this **and** walks every referencing column in `information_schema`,
     * because a list of two would silently miss the third.
     */
    public function isReferenced(): bool
    {
        return $this->converted_at !== null || $this->converted_referral_id !== null;
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class, 'collaborator_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
