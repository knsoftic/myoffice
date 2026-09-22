<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\InstallmentStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One line of a payment schedule (finance spine §2.3).
 *
 * **A schedule line is a promise, not money.** The commission engine never reads this table; receipts
 * point *at* it, which is what makes "commission follows the actual installment payment" (§77) provable
 * rather than asserted.
 *
 * `paid_amount` may legitimately exceed `amount`: over-allocating a line is allowed and the excess lands
 * on the charge as an advance. `PaymentService` caps the allocation instead of the database refusing the
 * money, because refusing to record a payment the business took is worse than an untidy number.
 *
 * @property string $amount
 * @property string $paid_amount
 * @property InstallmentStatus $status
 */
class StudentFeeInstallment extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'student_fee_installments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'installment_no',
        'amount',
        'due_date',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_fee_id' => 'integer',
            'installment_no' => 'integer',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'waived_amount' => 'decimal:2',
            'due_date' => 'immutable_date',
            'paid_on' => 'immutable_date',
            'status' => InstallmentStatus::class,
        ];
    }

    public function moduleSlug(): string
    {
        return 'installments';
    }

    protected function activityModule(): ?string
    {
        return 'installments';
    }

    /**
     * What is still owed on this line, after anything waived. Never negative.
     */
    public function remaining(): string
    {
        $settled = bcadd((string) $this->paid_amount, (string) $this->waived_amount, 2);
        $remaining = bcsub((string) $this->amount, $settled, 2);

        return bccomp($remaining, '0.00', 2) === 1 ? $remaining : '0.00';
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen() && ! $this->trashed();
    }

    /**
     * Has any money landed here? A line that has is never rebuilt and never soft-deleted — the policy
     * asks this.
     */
    public function holdsMoney(): bool
    {
        return bccomp((string) $this->paid_amount, '0.00', 2) === 1;
    }

    public function isOverdue(Carbon $on): bool
    {
        return $this->isOpen() && $this->due_date->lessThan($on->copy()->startOfDay());
    }

    public function scopeDueBy(Builder $query, Carbon $date): Builder
    {
        return $query->whereDate('due_date', '<=', $date->toDateString());
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(StudentFee::class, 'student_fee_id');
    }

    /** phase-18 2.2. */
    public function reminders(): HasMany
    {
        return $this->hasMany(StudentFeeReminder::class, 'student_fee_installment_id')->orderByDesc('sent_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(StudentFeePayment::class, 'student_fee_installment_id');
    }
}
