<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Enums\FinanceContext;
use App\Enums\IncomeStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasGeneratedColumns;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Crm\Client;
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
 * Money in that is neither a project payment nor a student fee (`incomes`, §29, phase-13 §2.6).
 *
 * Deliberately the mirror of {@see Expense}, so one mental model covers both sides of the books. The
 * one real difference is approval: income needs none, because the money either arrived or it did not.
 *
 * **It is not a place to record a project payment or a fee.** Those belong to the spine's tables and
 * fire the commission engine; a row here fires nothing — which is exactly why putting one in the wrong
 * table would show up as a commission that silently never got paid.
 *
 * @property IncomeStatus $status
 * @property FinanceContext $context
 * @property string $net_amount
 */
class Income extends Model
{
    use Blameable;
    use HasGeneratedColumns;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'incomes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'finance_category_id', 'branch_id', 'context', 'project_id', 'client_id', 'title',
        'description', 'received_from', 'amount', 'received_on', 'payment_method',
        'payment_method_id', 'reference_no', 'notes',
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
            'client_id' => 'integer',
            'payment_method_id' => 'integer',
            'voided_by' => 'integer',
            'corrects_income_id' => 'integer',
            'context' => FinanceContext::class,
            'status' => IncomeStatus::class,
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'received_on' => 'immutable_date',
            'recorded_at' => 'immutable_datetime',
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
        return 'income';
    }

    protected function activityModule(): ?string
    {
        return 'income';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return ['income_no', 'finance_category_id', 'context', 'project_id', 'client_id', 'title',
            'received_from', 'amount', 'refunded_amount', 'received_on', 'payment_method',
            'reference_no', 'status', 'void_reason'];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    public function isFullyRefunded(): bool
    {
        return Money::compare((string) $this->refunded_amount, (string) $this->amount) >= 0;
    }

    public function refundableAmount(): string
    {
        return Money::max(Money::ZERO, Money::sub((string) $this->amount, (string) $this->refunded_amount));
    }

    public function scopeCountsInReports(Builder $query): Builder
    {
        return $query->where('status', IncomeStatus::Recorded->value);
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethodOption::class, 'payment_method_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(FinanceReversal::class, 'income_id')->orderBy('id');
    }

    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_income_id');
    }

    public function correctedBy(): HasOne
    {
        return $this->hasOne(self::class, 'corrects_income_id');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
