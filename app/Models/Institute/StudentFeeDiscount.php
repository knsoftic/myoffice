<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\FeeDiscountType;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\FinancialRow;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One reduction ever granted against a fee (finance spine §2.4).
 *
 * **Append-only** (D16, D19). A wrong discount is undone by a `reversal` row that points at it, never by
 * an edit — which is what lets the engine answer "what was the net fee on the date payment X arrived"
 * without mutating anything.
 *
 * **`amount` is a SIGNED delta**, not a magnitude: reductions negative, a `correction` positive, a
 * `reversal` the opposite sign of the row it undoes. One column and one sign convention, so the net is
 * a SUM rather than a case analysis.
 *
 * `approved_by_name` sits beside the foreign key on purpose: the approver's user row may be deleted
 * years later, and "approved by" with nothing after it is the sentence a dispute turns on.
 *
 * @property string $amount
 * @property FeeDiscountType $type
 */
class StudentFeeDiscount extends Model
{
    use Blameable;
    use FinancialRow;
    use LogsActivityWithContext;

    protected $table = 'student_fee_discounts';

    /**
     * Nothing. Every column is written by `StudentFeeService` with `forceFill()` after it has composed
     * the signed amount, the idempotency key and the approver snapshot together.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_fee_id' => 'integer',
            'type' => FeeDiscountType::class,
            'amount' => 'decimal:2',
            'percentage' => 'decimal:4',
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
            'effective_on' => 'immutable_date',
            'reverses_discount_id' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'student_fees';
    }

    protected function activityModule(): ?string
    {
        return 'student_fees';
    }

    /**
     * Nothing about a granted discount changes. Approving one is part of granting it, and undoing one
     * is a new row — so the whitelist is empty and says so.
     *
     * @return list<string>
     */
    protected function mutableColumns(): array
    {
        return [];
    }

    protected function owningService(): string
    {
        return 'App\\Services\\Institute\\StudentFeeService';
    }

    protected function noDeleteMessage(): string
    {
        return 'A discount is reversed by a new row that points at it, never deleted: the net fee on the '
            .'date a payment arrived has to stay reconstructible (D16).';
    }

    /**
     * Does this row reduce what the student owes?
     */
    public function isReduction(): bool
    {
        return bccomp((string) $this->amount, '0.00', 2) === -1;
    }

    /**
     * The positive magnitude, for a screen that shows "3,000 off" rather than "-3,000".
     */
    public function magnitude(): string
    {
        $amount = (string) $this->amount;

        return str_starts_with($amount, '-') ? substr($amount, 1) : $amount;
    }

    /**
     * Which of the charge's two caches this row belongs to. Scholarships are budgeted and reported
     * separately, which is the only reason the distinction exists at all.
     */
    public function cacheColumn(): string
    {
        return $this->type->isScholarship() ? 'scholarship_amount' : 'discount_amount';
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(StudentFee::class, 'student_fee_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_discount_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_discount_id');
    }
}
