<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Enums\DiscountMode;
use App\Enums\InvoiceStatus;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasGeneratedColumns;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Crm\Client;
use App\Models\Project\Project;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A bill sent to a client (`invoices`, §31, phase-13 §2.4).
 *
 * **It holds no cash.** Every rupee against it is a `project_payments` row owned by the spine, and the
 * three money caches here are rewritten by `InvoiceService::recomputePaid()` from one canonical query.
 * Nothing anywhere may `increment()` them: an independent counter is how an invoice and its receipts
 * start disagreeing, and by the time somebody notices there is no way to tell which was right.
 *
 * **`status` is derived, never typed.** `recomputeStatus()` is a pure function of five stored facts, so
 * the same answer comes back whether a controller asks, a refund asks, or the nightly job asks.
 *
 * `invoice_number` is NULL while the invoice is a draft — which is what makes the series gap-free, since
 * an abandoned draft consumes nothing. The screens show {@see draftReference()} instead.
 *
 * @property InvoiceStatus $status
 * @property DiscountMode $discount_mode
 * @property string $total_amount
 * @property string $paid_amount
 * @property string $balance_amount
 */
class Invoice extends Model
{
    use Blameable;
    use HasGeneratedColumns;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'invoices';

    /**
     * Deliberately empty: every write goes through `InvoiceService`, which is what recalculates the
     * totals, assigns the number under a lock and re-derives the status in one transaction. A
     * mass-assigned invoice would have none of that and would look perfectly normal in the table.
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
            'branch_id' => 'integer',
            'client_id' => 'integer',
            'project_id' => 'integer',
            'replaces_invoice_id' => 'integer',
            'payment_method_id' => 'integer',
            'issued_by' => 'integer',
            'cancelled_by' => 'integer',
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'payment_terms_days' => 'integer',
            'status' => InvoiceStatus::class,
            'discount_mode' => DiscountMode::class,
            'tax_rate' => 'decimal:4',
            'discount_rate' => 'decimal:4',
            'discount_fixed' => 'decimal:2',
            'subtotal_amount' => 'decimal:2',
            'item_discount_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_discount_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'round_off_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
            'issued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'last_sent_at' => 'immutable_datetime',
            'sent_count' => 'integer',
            'last_reminder_at' => 'immutable_datetime',
            'reminder_count' => 'integer',
            'viewed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * The one column §31's "discount" prints and every report sums.
     *
     * @return list<string>
     */
    public function generatedColumns(): array
    {
        return ['total_discount_amount'];
    }

    /**
     * Never in a response body: the token is the key to the public view, and a leaked one is a bill
     * anybody can read.
     *
     * @var list<string>
     */
    protected $hidden = ['public_token'];

    public function moduleSlug(): string
    {
        return 'invoices';
    }

    protected function activityModule(): ?string
    {
        return 'invoices';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return ['invoice_number', 'client_id', 'project_id', 'status', 'issue_date', 'due_date',
            'subtotal_amount', 'total_discount_amount', 'tax_amount', 'total_amount', 'paid_amount',
            'balance_amount', 'cancellation_reason'];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen shows
    |--------------------------------------------------------------------------
    */

    /**
     * What to call it before it has a number.
     *
     * A draft has no `invoice_number` on purpose, so the screens need something to print that is
     * obviously not an invoice number — "DRAFT-14" rather than a blank cell somebody might read as a
     * missing value.
     */
    protected function draftReference(): Attribute
    {
        return Attribute::get(fn (): string => (string) ($this->invoice_number ?? 'DRAFT-'.$this->getKey()));
    }

    /**
     * How overdue it is today, in days. Zero or negative means not yet due.
     */
    public function daysOverdue(?Carbon $asOf = null): int
    {
        $asOf ??= Carbon::now();

        return (int) $asOf->startOfDay()->diffInDays($this->due_date->startOfDay(), false) * -1;
    }

    /**
     * Has any money been received against it? The question `cancel()` and `update()` both ask, and the
     * reason they ask is the same: a document somebody has paid against is not a draft any more,
     * whatever its status says.
     */
    public function hasReceipts(): bool
    {
        return Money::compare((string) $this->paid_amount, Money::ZERO) !== 0
            || Money::compare((string) $this->refunded_amount, Money::ZERO) !== 0;
    }

    /**
     * An overpaid invoice is `paid` with a negative balance. The register shows the credit rather than
     * hiding it, because a client who has overpaid will ask about it.
     */
    public function isOverpaid(): bool
    {
        return Money::isNegative((string) $this->balance_amount);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InvoiceStatus::Sent->value, InvoiceStatus::Partial->value, InvoiceStatus::Overdue->value,
        ]);
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * What a client may see: anything that is not a draft (§31). A cancelled invoice is included
     * deliberately — they may already be holding a copy of it, and a document that vanishes is worse
     * than one marked cancelled.
     */
    public function scopeVisibleToClient(Builder $query): Builder
    {
        return $query->whereNot('status', InvoiceStatus::Draft->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The spine's table, read-only here except for the one-way link of §6.3.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ProjectPayment::class, 'invoice_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethodOption::class, 'payment_method_id');
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_invoice_id');
    }

    public function replacedBy(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_invoice_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
