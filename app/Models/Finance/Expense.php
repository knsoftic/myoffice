<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Enums\ExpenseStatus;
use App\Enums\FinanceContext;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasGeneratedColumns;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Project\Project;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money the business spent (`expenses`, §30, phase-13 §2.6).
 *
 * **Only an approved expense counts in a report.** A pending claim is somebody's assertion, not yet the
 * company's money, and a profit-and-loss statement that included it would move every time an employee
 * typed a number. `ExpenseStatus::countsInReports()` is the one expression of that rule.
 *
 * `net_amount` is a **generated** column, so every report and the P&L sum a figure that is already net
 * of refunds — none of them can forget, and the one that forgot would be the one nobody re-reads.
 *
 * `expense_date` is the value date and `recorded_at` the clock; both are always kept, because a
 * back-dated expense belongs in the period it was incurred and the difference is what explains why a
 * closed month's report moved.
 *
 * @property ExpenseStatus $status
 * @property FinanceContext $context
 * @property string $net_amount
 */
class Expense extends Model
{
    use Blameable;
    use HasGeneratedColumns;
    use LogsActivityWithContext;
    use SoftDeletes;

    /**
     * The only `source_type` today. A derived expense is one no human typed, and the pair
     * `(source_type, source_id)` is what `uq_exp_source` makes unique so a replayed event cannot post
     * a second salary bill for the same run (D44).
     */
    public const SOURCE_PAYROLL_RUN = 'payroll_run';

    protected $table = 'expenses';

    /**
     * The describable half. `expense_no`, `status`, `approval_required` and every money cache are the
     * service's, because each of them is decided under a lock or derived from something else.
     *
     * @var list<string>
     */
    protected $fillable = [
        'finance_category_id', 'branch_id', 'context', 'project_id', 'title', 'description',
        'paid_to', 'amount', 'expense_date', 'payment_method', 'payment_method_id',
        'reference_no', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'finance_category_id' => 'integer',
            'branch_id' => 'integer',
            'project_id' => 'integer',
            'payment_method_id' => 'integer',
            'source_id' => 'integer',
            'approved_by' => 'integer',
            'rejected_by' => 'integer',
            'voided_by' => 'integer',
            'corrects_expense_id' => 'integer',
            'context' => FinanceContext::class,
            'status' => ExpenseStatus::class,
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'expense_date' => 'immutable_date',
            'recorded_at' => 'immutable_datetime',
            'approval_required' => 'boolean',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function generatedColumns(): array
    {
        return ['net_amount'];
    }

    public function moduleSlug(): string
    {
        return 'expenses';
    }

    protected function activityModule(): ?string
    {
        return 'expenses';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return ['expense_no', 'finance_category_id', 'context', 'project_id', 'title', 'paid_to',
            'amount', 'refunded_amount', 'expense_date', 'payment_method', 'reference_no', 'status',
            'rejection_reason', 'void_reason'];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    /**
     * Is it a row a human typed, or one the system derived from something else?
     *
     * A derived expense has no editable fields and no delete: correcting it means correcting the thing
     * it came from, which is a payroll decision rather than a finance one.
     */
    public function isDerived(): bool
    {
        return $this->source_type !== null;
    }

    public function isFullyRefunded(): bool
    {
        return Money::compare((string) $this->refunded_amount, (string) $this->amount) >= 0;
    }

    /**
     * How much may still be refunded. The conditional UPDATE in the service is the real guard; this is
     * what the form shows so nobody types a figure that is going to be refused.
     */
    public function refundableAmount(): string
    {
        return Money::max(Money::ZERO, Money::sub((string) $this->amount, (string) $this->refunded_amount));
    }

    public function scopeCountsInReports(Builder $query): Builder
    {
        return $query->where('status', ExpenseStatus::Approved->value);
    }

    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', ExpenseStatus::Pending->value);
    }

    public function scopeForContext(Builder $query, ?FinanceContext $context): Builder
    {
        return $context === null ? $query : $query->where('context', $context->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethodOption::class, 'payment_method_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(FinanceReversal::class, 'expense_id')->orderBy('id');
    }

    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_expense_id');
    }

    public function correctedBy(): HasOne
    {
        return $this->hasOne(self::class, 'corrects_expense_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
