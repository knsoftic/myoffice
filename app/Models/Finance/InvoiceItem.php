<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Enums\DiscountMode;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Project\ProjectMilestone;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billed line (`invoice_items`, §31, phase-13 §2.5).
 *
 * **No soft delete** (D19): a line belongs to its parent document. The invoice's own soft delete
 * preserves the whole thing, and only a never-issued draft may lose a line at all — a nullable
 * `deleted_at` here would accumulate orphaned draft lines that every totals query had to remember to
 * exclude, and the first query that forgot would print a total nobody could reproduce.
 *
 * Every money column is written by `InvoiceService::recalculate()` and by nothing else, so the five
 * identities of §2.7 hold by construction rather than by each caller being careful.
 *
 * @property DiscountMode $discount_mode
 * @property string $line_total
 */
class InvoiceItem extends Model
{
    use Blameable;
    use LogsActivityWithContext;

    protected $table = 'invoice_items';

    /**
     * The describable half of a line. Every money column is derived by the service from these plus the
     * invoice's own discount, so a caller can say *what* is billed and never *what it comes to*.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sort_order', 'project_milestone_id', 'description', 'details', 'unit',
        'quantity', 'unit_price', 'discount_mode', 'discount_rate', 'discount_fixed',
        'is_taxable', 'tax_rate',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_id' => 'integer',
            'sort_order' => 'integer',
            'project_milestone_id' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'discount_mode' => DiscountMode::class,
            'discount_rate' => 'decimal:4',
            'discount_fixed' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'allocated_discount_amount' => 'decimal:2',
            'is_taxable' => 'boolean',
            'tax_rate' => 'decimal:4',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function moduleSlug(): string
    {
        return 'invoices';
    }

    protected function activityModule(): ?string
    {
        return 'invoices';
    }

    /**
     * A line's own activity is deliberately thin: the invoice logs its total moving, which is the fact
     * anybody asks about. Logging every derived column on every line would bury that.
     *
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return ['description', 'quantity', 'unit_price', 'discount_mode', 'is_taxable', 'line_total'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
