<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\EmploymentType;
use App\Enums\LeaveAccrualMethod;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One kind of leave (phase-07 §2.12, requirement §27).
 *
 * Every rule a business might have lives here as **data**: how the quota accrues, whether it carries
 * forward and for how long, how much notice it needs, whether half days are allowed, whether weekends and
 * holidays inside a range count against it, which employment types may use it, how many approval levels it
 * goes through.
 *
 * `color` is a plain column rather than an enum method, because leave types are seeded and then edited by
 * the business — the calendar's colour has to be editable too.
 *
 * `excludes_weekends` / `excludes_holidays` are what make "five calendar days cost three quota days"
 * explainable: the skipped dates are still written as rows, marked not counted.
 */
class LeaveType extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'leave_types';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'annual_quota_days',
        'is_paid',
        'accrual_method',
        'accrual_days_per_month',
        'accrue_from_joining',
        'carry_forward_enabled',
        'max_carry_forward_days',
        'carry_forward_expiry_months',
        'max_consecutive_days',
        'min_notice_days',
        'allow_half_day',
        'allow_negative_balance',
        'requires_attachment',
        'attachment_required_after_days',
        'excludes_weekends',
        'excludes_holidays',
        'applies_to_employment_types',
        'allowed_on_probation',
        'approval_levels',
        'is_encashable',
        'color',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'annual_quota_days' => 'decimal:2',
            'is_paid' => 'boolean',
            'accrual_method' => LeaveAccrualMethod::class,
            'accrual_days_per_month' => 'decimal:2',
            'accrue_from_joining' => 'boolean',
            'carry_forward_enabled' => 'boolean',
            'max_carry_forward_days' => 'decimal:2',
            'carry_forward_expiry_months' => 'integer',
            'max_consecutive_days' => 'integer',
            'min_notice_days' => 'integer',
            'allow_half_day' => 'boolean',
            'allow_negative_balance' => 'boolean',
            'requires_attachment' => 'boolean',
            'attachment_required_after_days' => 'integer',
            'excludes_weekends' => 'boolean',
            'excludes_holidays' => 'boolean',
            'applies_to_employment_types' => 'array',
            'allowed_on_probation' => 'boolean',
            'approval_levels' => 'integer',
            'is_encashable' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * May somebody on this employment type use this leave type? A null list means everybody.
     */
    public function appliesTo(EmploymentType $type): bool
    {
        $allowed = $this->applies_to_employment_types;

        return ! is_array($allowed) || $allowed === [] || in_array($type->value, $allowed, true);
    }

    /**
     * Does a negative balance pass, for this type? The per-type switch wins over the global one, because
     * a business that allows unpaid sick leave rarely means to allow unpaid annual leave too.
     */
    public function allowsNegative(): bool
    {
        return $this->allow_negative_balance
            || (bool) setting('hr.leave_negative_balance_allowed', false);
    }

    public function moduleSlug(): string
    {
        return 'leave_types';
    }

    protected function activityModule(): ?string
    {
        return 'leave_types';
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'leave_type_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class, 'leave_type_id');
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(LeaveBalanceTransaction::class, 'leave_type_id');
    }
}
