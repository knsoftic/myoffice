<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Enums\FinanceCategoryType;
use App\Enums\FinanceContext;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * How a spend or a receipt is classified (`finance_categories`, §30, phase-13 §2.3).
 *
 * One table for both sides, separated by `type`. `code` is the stable half — a report groups by it and
 * an admin may rename `name` freely — so "Utilities" can become "Utilities & Power" without breaking a
 * year of comparisons.
 *
 * **`salaries` is reserved (D44).** Every paid payroll run posts into it, and both deletion and
 * deactivation are refused: a missing category would silently drop payroll out of the profit-and-loss
 * statement, and a P&L missing its largest line is worse than no P&L at all.
 *
 * @property FinanceCategoryType $type
 * @property FinanceContext|null $context
 */
class FinanceCategory extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    /**
     * The one code no screen may remove or switch off (D44).
     */
    public const RESERVED_EXPENSE_CODE = 'salaries';

    protected $table = 'finance_categories';

    /**
     * @var list<string>
     */
    protected $fillable = ['type', 'code', 'name', 'description', 'context', 'is_active', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FinanceCategoryType::class,
            'context' => FinanceContext::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public static function booted(): void
    {
        static::deleting(function (self $category): void {
            if ($category->isReserved()) {
                throw new LogicException(
                    'The Salaries category is where every paid payroll run posts its expense. Removing '
                    .'it would not delete that history — it would leave it pointing at nothing, and the '
                    .'profit-and-loss statement would quietly lose its largest line.'
                );
            }
        });
    }

    public function moduleSlug(): string
    {
        return 'finance_categories';
    }

    protected function activityModule(): ?string
    {
        return 'finance_categories';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return ['type', 'code', 'name', 'description', 'context', 'is_active', 'sort_order'];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    public function isReserved(): bool
    {
        return $this->type === FinanceCategoryType::Expense
            && $this->code === self::RESERVED_EXPENSE_CODE;
    }

    /**
     * May it be switched off? A reserved category may not, for the same reason it may not be deleted:
     * a deactivated category disappears from the dropdown, and the payroll job would then have nowhere
     * to post.
     */
    public function isDeactivatable(): bool
    {
        return ! $this->isReserved();
    }

    /**
     * Has any money ever been classified under it? The policy asks before offering deletion, because
     * the foreign keys are RESTRICT and the honest answer is "deactivate it instead".
     */
    public function isInUse(): bool
    {
        return $this->expenses()->withTrashed()->exists() || $this->incomes()->withTrashed()->exists();
    }

    public function scopeOfType(Builder $query, FinanceCategoryType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Null `context` means "usable anywhere", which most categories are. Forcing a choice would make
     * every shared overhead pick a side it does not belong to.
     */
    public function scopeForContext(Builder $query, ?FinanceContext $context): Builder
    {
        if ($context === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereNull('context')
            ->orWhere('context', $context->value));
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'finance_category_id');
    }

    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class, 'finance_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
