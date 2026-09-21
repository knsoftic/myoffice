<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Enums\PaymentMethod;
use App\Enums\ReversalType;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\FinancialRow;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The un-doing of an expense or an other-income row (`finance_reversals`, phase-13 §2.6).
 *
 * **Not the spine's `PaymentReversal`, and never to be confused with it.** That table's
 * `chk_pr_one_target` allows only a student-fee or a project payment, so it structurally cannot hold
 * these. The difference that matters is the second one: **no commission engine is involved**. An
 * expense has never produced a ledger entry, so a row here dispatches no commission job, and nothing
 * about any partner's balance moves when a supplier gives money back.
 *
 * `reversal_no` draws from the **same counter** as the spine's payment reversals, so an auditor follows
 * one voucher sequence rather than two that look alike.
 *
 * **Append-only** (D16): no `deleted_at`, a model hook that refuses deletion with a readable message,
 * and `trg_fr_no_delete` behind it for anything that goes around the model.
 *
 * @property ReversalType $type
 * @property string $amount
 */
class FinanceReversal extends Model
{
    use Blameable;
    use FinancialRow;
    use LogsActivityWithContext;

    protected $table = 'finance_reversals';

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
            'expense_id' => 'integer',
            'income_id' => 'integer',
            'performed_by' => 'integer',
            'type' => ReversalType::class,
            'amount' => 'decimal:2',
            'refund_method' => PaymentMethod::class,
            'occurred_on' => 'immutable_date',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /**
     * Append-only: there is no `updated_by` column, because there is no update to attribute.
     */
    protected static function updatedByColumn(): ?string
    {
        return null;
    }

    /**
     * Nothing. A reversal is a statement about a moment, and a statement that can be amended is not
     * evidence — a wrong one is corrected by a further row that points at the same parent.
     *
     * @return list<string>
     */
    protected function mutableColumns(): array
    {
        return [];
    }

    protected function owningService(): string
    {
        return 'FinanceReversalService';
    }

    protected function noDeleteMessage(): string
    {
        return 'Deleting a finance reversal would make money that came back disappear from every report '
            .'that already counted it, and the expense it belongs to would go back to looking like the '
            .'full amount was spent. It is corrected by a further row, never removed.';
    }

    public function moduleSlug(): string
    {
        return $this->expense_id !== null ? 'expenses' : 'income';
    }

    protected function activityModule(): ?string
    {
        return $this->moduleSlug();
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return ['reversal_no', 'expense_id', 'income_id', 'type', 'amount', 'reason',
            'refund_method', 'reference_no', 'occurred_on'];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }

    public function income(): BelongsTo
    {
        return $this->belongsTo(Income::class, 'income_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
