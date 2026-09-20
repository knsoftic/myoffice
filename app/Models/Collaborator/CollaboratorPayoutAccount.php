<?php

declare(strict_types=1);

namespace App\Models\Collaborator;

use App\Enums\PayoutMethod;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasGeneratedColumns;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reusable, encrypted payout destination (finance spine §2.15, requirement §55).
 *
 * **Kept out of the payout row on purpose.** A bank account is typed once and snapshotted at each
 * payment; re-typing an IBAN per request is both a data-entry risk and a money risk, and the one error
 * it produces is money sent to the wrong person.
 *
 * **`details_encrypted` never leaves this object** (INV-C6). It is `$hidden`, it is excluded from the
 * activity diff, and no ability anywhere reveals it — which is why `collaborator_payout_accounts` has no
 * `view_financial` permission: there is nothing to unmask. Only `account_last4` is ever rendered.
 *
 * Soft deletes **are** allowed: this is a contact detail, not a movement. A paid payout keeps its own
 * encrypted snapshot, so removing the account never makes a past transfer unexplainable.
 *
 * @property PayoutMethod $method
 * @property array<string, mixed>|null $details_encrypted
 */
class CollaboratorPayoutAccount extends Model
{
    use Blameable;
    use HasGeneratedColumns;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'collaborator_payout_accounts';

    /**
     * The label and the bank name are the only things a form types directly. The details go through
     * `CollaboratorPayoutAccountService`, which is what splits the last four digits out and encrypts
     * the rest.
     *
     * @var list<string>
     */
    protected $fillable = [
        'label',
        'account_title',
        'bank_name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collaborator_id' => 'integer',
            'method' => PayoutMethod::class,
            // Laravel's `encrypted:array`: the account number, IBAN, branch code or mobile number go in
            // as a map and come back as one. Never a string, so nothing can accidentally log "the
            // account" as a single readable value.
            'details_encrypted' => 'encrypted:array',
            'is_default' => 'boolean',
            'is_verified' => 'boolean',
            'verified_by' => 'integer',
            'verified_at' => 'immutable_datetime',
        ];
    }

    /**
     * Never in a response body, never in an export, never in an exception payload (INV-C6).
     *
     * @var list<string>
     */
    protected $hidden = ['details_encrypted'];

    /**
     * Carries `uq_cpacc_default`: exactly one default account per collaborator, enforced by the database rather than a clear-all-others loop that can half-fail.
     *
     * @return list<string>
     */
    public function generatedColumns(): array
    {
        return ['default_guard'];
    }

    public function moduleSlug(): string
    {
        return 'collaborator_payout_accounts';
    }

    protected function activityModule(): ?string
    {
        return 'collaborator_payout_accounts';
    }

    /**
     * What an activity diff may carry. `details_encrypted` is absent, so a change to an account number
     * records **that** it changed without recording what it changed to — which is the whole of §55.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['label', 'method', 'account_title', 'bank_name', 'account_last4',
            'is_default', 'is_verified', 'status'];
    }

    /**
     * The trait's four defaults plus the encrypted destination. Restated rather than merged into a
     * `parent::` call: the method comes from a trait, not a parent class, so there is nothing to call.
     *
     * @return array<int, string>
     */
    protected function activitySecretAttributes(): array
    {
        return ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'details_encrypted'];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen shows
    |--------------------------------------------------------------------------
    */

    /**
     * The only rendering of an account anywhere in the system.
     */
    public function maskedAccount(): string
    {
        return $this->account_last4 === null ? '••••' : '••••'.$this->account_last4;
    }

    /**
     * May money be sent here? `collaborator.payout_account_verification_required` decides whether an
     * unverified account is usable — a business that pays small amounts to many partners may reasonably
     * say yes.
     */
    public function isUsable(): bool
    {
        if ($this->status !== 'active' || $this->trashed()) {
            return false;
        }

        return $this->is_verified || ! (bool) setting('collaborator.payout_account_verification_required', true);
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class, 'collaborator_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(CollaboratorPayout::class, 'payout_account_id');
    }
}
