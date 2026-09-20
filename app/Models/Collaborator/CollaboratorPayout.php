<?php

declare(strict_types=1);

namespace App\Models\Collaborator;

use App\Enums\PayoutMethod;
use App\Enums\PayoutStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\FinancialRow;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A withdrawal of available balance (finance spine §2.13, requirements §54, §55).
 *
 * **The payout never computes a balance itself.** It consumes *named* ledger entries through
 * `collaborator_payout_allocations`, which is what makes "payout history preserved" and "a commission
 * is never paid twice" true at the same time. `amount` is `SUM(live allocations)` set by the service
 * and **never by the form** (INV-22) — a payout whose amount came from a request body is a payout
 * somebody can edit into anything.
 *
 * **No `deleted_at`** (D16): §120.9 requires payout history to survive. Cancelling is a status, and it
 * releases the allocations rather than deleting them, so the earnings go back to available with a
 * recorded reason.
 *
 * `account_details_encrypted` is snapshotted **at payment time**, because the account row may change
 * afterwards and a voucher has to say where the money actually went.
 *
 * @property string $amount
 * @property PayoutStatus $status
 * @property PayoutMethod $method
 */
class CollaboratorPayout extends Model
{
    use Blameable;
    use FinancialRow;
    use LogsActivityWithContext;

    protected $table = 'collaborator_payouts';

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
            'requested_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'entry_count' => 'integer',
            'method' => PayoutMethod::class,
            'payout_account_id' => 'integer',
            'account_details_encrypted' => 'encrypted:array',
            'status' => PayoutStatus::class,
            'minimum_payout_snapshot' => 'decimal:2',
            'available_at_request' => 'decimal:2',
            'requested_by' => 'integer',
            'requested_at' => 'immutable_datetime',
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
            'paid_by' => 'integer',
            'paid_at' => 'immutable_datetime',
            'paid_on' => 'immutable_date',
            'rejected_by' => 'integer',
            'rejected_at' => 'immutable_datetime',
            'cancelled_by' => 'integer',
            'cancelled_at' => 'immutable_datetime',
            'statement_from' => 'immutable_date',
            'statement_to' => 'immutable_date',
        ];
    }

    /**
     * @var list<string>
     */
    protected $hidden = ['account_details_encrypted'];

    public function moduleSlug(): string
    {
        return 'collaborator_payouts';
    }

    protected function activityModule(): ?string
    {
        return 'collaborator_payouts';
    }

    /**
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['status', 'amount', 'entry_count', 'method', 'transaction_id', 'account_last4',
            'paid_on', 'rejection_reason', 'cancellation_reason'];
    }

    /**
     * The trait's four defaults plus the encrypted destination. Restated rather than merged into a
     * `parent::` call: the method comes from a trait, not a parent class, so there is nothing to call.
     *
     * @return array<int, string>
     */
    protected function activitySecretAttributes(): array
    {
        return ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'account_details_encrypted'];
    }

    /**
     * The lifecycle and the transfer details. `requested_amount`, `collaborator_id` and the two
     * snapshots are what the request **was** and never move.
     *
     * `amount` and `entry_count` are here because they are caches of the live allocations, recomputed
     * by the service whenever an allocation is added or released — not because a form may set them.
     *
     * @return list<string>
     */
    protected function mutableColumns(): array
    {
        return [
            'amount', 'entry_count', 'status', 'notes', 'receipt_path',
            'payout_account_id', 'account_title', 'bank_name',
            'account_details_encrypted', 'account_last4', 'transaction_id',
            'approved_by', 'approved_at', 'paid_by', 'paid_at', 'paid_on',
            'rejected_by', 'rejected_at', 'rejection_reason',
            'cancelled_by', 'cancelled_at', 'cancellation_reason',
            'statement_from', 'statement_to',
        ];
    }

    protected function owningService(): string
    {
        return 'App\\Services\\Collaborator\\PayoutService';
    }

    protected function noDeleteMessage(): string
    {
        return 'Payout history survives cancellation (§120.9): cancelling is a status that releases the '
            .'allocations, not a delete.';
    }

    /*
    |--------------------------------------------------------------------------
    | Questions the screens ask
    |--------------------------------------------------------------------------
    */

    /**
     * Is this payout still holding earnings that nothing else can spend?
     */
    public function isInFlight(): bool
    {
        return $this->status->isInFlight();
    }

    public function maskedAccount(): string
    {
        return $this->account_last4 === null ? '••••' : '••••'.$this->account_last4;
    }

    /**
     * May it move to `$target`? §6.4's table, and nothing else is legal.
     */
    public function canTransitionTo(PayoutStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }

    /**
     * Was the figure the requester saw different from what the service worked out?
     *
     * Not an error — a commission can become available between opening the form and submitting it — but
     * it is logged, because a partner who asked for 50,000 and received 47,300 deserves to be told why.
     */
    public function requestDisagreedWithAvailable(): bool
    {
        return $this->available_at_request !== null
            && bccomp((string) $this->available_at_request, (string) $this->amount, 2) !== 0;
    }

    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PayoutStatus::Requested->value,
            PayoutStatus::Pending->value,
            PayoutStatus::Approved->value,
        ]);
    }

    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PayoutStatus::Paid->value,
            PayoutStatus::Rejected->value,
            PayoutStatus::Cancelled->value,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (spine §2.13)
    |--------------------------------------------------------------------------
    */

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class, 'collaborator_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CollaboratorPayoutAccount::class, 'payout_account_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CollaboratorPayoutAllocation::class, 'payout_id');
    }

    /**
     * The earnings this payout carried — through the pivot, so "exactly these commissions" is a query
     * rather than a reconstruction.
     */
    public function ledgerEntries(): BelongsToMany
    {
        return $this->belongsToMany(
            CollaboratorCommissionLedgerEntry::class,
            'collaborator_payout_allocations',
            'payout_id',
            'ledger_entry_id',
        )->withPivot(['amount', 'is_released', 'release_reason', 'released_at']);
    }
}
