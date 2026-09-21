<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Enums\PaymentMethodType;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasGeneratedColumns;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Institute\StudentFeePayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A configured way to take or send money (`payment_methods`, §32, phase-13 §2.2).
 *
 * **Named `PaymentMethodOption`, not `PaymentMethod`.** `App\Enums\PaymentMethod` already owns that
 * name, and it is the more important of the two: the enum value written onto a payment row is the
 * snapshot of record and must never change meaning. This model is the *configuration* around it —
 * presentation, ordering, activation, gateway wiring — so renaming "Bank transfer" to "Bank transfer —
 * HBL" changes every dropdown and no history. A model that shadowed the enum's name would invite
 * exactly the confusion the separation exists to prevent.
 *
 * `config_encrypted` holds gateway keys. It is `$hidden`, it never appears in an activity diff, and no
 * screen renders it — a `view_financial` that could unmask it would make the encryption decorative.
 *
 * @property PaymentMethodEnum $code
 * @property PaymentMethodType $type
 */
class PaymentMethodOption extends Model
{
    use Blameable;
    use HasGeneratedColumns;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'payment_methods';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code', 'name', 'description', 'type', 'is_online', 'gateway_driver', 'is_test_mode',
        'supports_refund', 'requires_reference', 'instructions', 'usable_for', 'is_active',
        'is_default', 'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => PaymentMethodEnum::class,
            'type' => PaymentMethodType::class,
            'is_online' => 'boolean',
            'is_test_mode' => 'boolean',
            'supports_refund' => 'boolean',
            'requires_reference' => 'boolean',
            'usable_for' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
            // Laravel's encrypted:array: keys and secrets go in as a map and come back as one, so
            // nothing can log "the config" as a single readable string.
            'config_encrypted' => 'encrypted:array',
        ];
    }

    /**
     * Never in a response body, never in an export, never in an exception payload.
     *
     * @var list<string>
     */
    protected $hidden = ['config_encrypted'];

    /**
     * Carries `uq_pm_default`: at most one live default, enforced by the database rather than a
     * clear-all-others loop that can half-fail and leave two.
     *
     * @return list<string>
     */
    public function generatedColumns(): array
    {
        return ['default_guard'];
    }

    public function moduleSlug(): string
    {
        return 'payment_methods';
    }

    protected function activityModule(): ?string
    {
        return 'payment_methods';
    }

    /**
     * The activity diff never carries the config. A changed gateway secret logs **that** it changed,
     * and a diff that carried the old and new keys would put both in a table more people can read than
     * the form they were typed into.
     *
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return ['code', 'name', 'type', 'is_online', 'gateway_driver', 'is_test_mode',
            'supports_refund', 'requires_reference', 'usable_for', 'is_active', 'is_default', 'sort_order'];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    /**
     * May this method be offered on that kind of form?
     *
     * One list rather than six booleans: a payout destination and an invoice payment option are
     * genuinely different questions, and six columns would be six places for the answer to drift.
     */
    public function isUsableFor(string $context): bool
    {
        return $this->is_active && ! $this->trashed() && in_array($context, (array) $this->usable_for, true);
    }

    /**
     * Is a gateway actually wired, or is this a manual method wearing the label?
     */
    public function isLiveGateway(): bool
    {
        return $this->is_online && filled($this->gateway_driver) && ! $this->is_test_mode;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeUsableFor(Builder $query, string $context): Builder
    {
        return $query->active()->whereJsonContains('usable_for', $context)->orderByDesc('is_default')->orderBy('sort_order');
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function projectPayments(): HasMany
    {
        return $this->hasMany(ProjectPayment::class, 'payment_method_id');
    }

    public function studentFeePayments(): HasMany
    {
        return $this->hasMany(StudentFeePayment::class, 'payment_method_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'payment_method_id');
    }

    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class, 'payment_method_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
