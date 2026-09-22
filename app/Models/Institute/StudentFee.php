<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\StudentFeeStatus;
use App\Enums\StudentFeeType;
use App\Models\Branch;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * What a student owes for one fee head (finance spine §2.2).
 *
 * **It never holds cash.** Six of its amount columns are caches of rows in other tables, and
 * `StudentFeeService::recomputeCaches()` is the only thing that writes them, under this row's lock. The
 * accessors below are the honest reading of each one, because a cache somebody starts treating as the
 * truth is how a fee total and a receipt total come to disagree.
 *
 * **`collaborator_id` is a display snapshot** (D37). The commission engine never reads it: it resolves
 * the referral effective on the **payment date**, because who referred somebody is a fact with a
 * timeline and this column is a convenience for printing a receipt.
 *
 * @property string $gross_amount
 * @property string $net_amount
 * @property string $paid_amount
 * @property string $balance_amount
 * @property StudentFeeType $fee_type
 * @property StudentFeeStatus $status
 */
class StudentFee extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'student_fees';

    /**
     * Only what a fee form legitimately says. Every amount is written by `StudentFeeService` through
     * `Money`, and `generation_key` is composed by the generator — a caller that could set it could
     * defeat `uq_sf_generation`, which is the whole duplicate guard for scheduled charges.
     *
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'student_id',
        'student_admission_id',
        'course_id',
        'batch_id',
        'fee_type',
        'title',
        'due_date',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'student_id' => 'integer',
            'student_admission_id' => 'integer',
            'course_id' => 'integer',
            'batch_id' => 'integer',
            'collaborator_id' => 'integer',
            'fee_type' => StudentFeeType::class,
            'status' => StudentFeeStatus::class,
            // decimal:2 — string in, string out. A money column that becomes a float has already lost.
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'scholarship_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
            'has_installment_plan' => 'boolean',
            'installment_count' => 'integer',
            'due_date' => 'immutable_date',
            'cancelled_at' => 'immutable_datetime',
            'cancelled_by' => 'integer',
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

    /*
    |--------------------------------------------------------------------------
    | Questions the rest of the system asks
    |--------------------------------------------------------------------------
    */

    /**
     * Can this charge still take money?
     */
    public function isOpen(): bool
    {
        return $this->status->isOpen() && ! $this->trashed();
    }

    /**
     * Is the student ahead rather than behind? A negative balance is an advance, not an error.
     */
    public function isOverpaid(): bool
    {
        return bccomp((string) $this->balance_amount, '0.00', 2) === -1;
    }

    /**
     * What is still expected against this charge — the **denominator** an entitlement's proportional
     * release divides by. Never negative: an overpayment does not increase what was collectible.
     */
    public function collectibleAmount(): string
    {
        return bccomp((string) $this->net_amount, '0.00', 2) === 1
            ? (string) $this->net_amount
            : '0.00';
    }

    /**
     * Money the business actually holds against this charge, net of anything given back.
     */
    public function netReceived(): string
    {
        return bcsub((string) $this->paid_amount, (string) $this->refunded_amount, 2);
    }

    public function isOverdue(Carbon $on): bool
    {
        return $this->due_date !== null
            && $this->isOpen()
            && $this->due_date->lessThan($on->copy()->startOfDay());
    }

    /**
     * Charges a user may see. Branch scoping is Phase 15's; this is the narrowing every screen shares.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            StudentFeeStatus::Pending->value,
            StudentFeeStatus::Partial->value,
            StudentFeeStatus::Overdue->value,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (spine §2.2)
    |--------------------------------------------------------------------------
    */

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class, 'collaborator_id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(StudentAdmission::class, 'student_admission_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    /** phase-18 2.2 - the log of every time this student was told about this charge. */
    public function reminders(): HasMany
    {
        return $this->hasMany(StudentFeeReminder::class, 'student_fee_id')->orderByDesc('sent_at');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(StudentFeeInstallment::class, 'student_fee_id')->orderBy('installment_no');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(StudentFeePayment::class, 'student_fee_id');
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(StudentFeeDiscount::class, 'student_fee_id')->orderBy('effective_on');
    }

    /**
     * The commission trail on this charge — what a partner's "why was I paid this" question reads.
     */
    public function commissionEntries(): HasMany
    {
        return $this->hasMany(CollaboratorCommissionLedgerEntry::class, 'student_fee_id');
    }
}
