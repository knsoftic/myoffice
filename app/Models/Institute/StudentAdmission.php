<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\AdmissionStage;
use App\Enums\DeliveryMode;
use App\Enums\PreferredTiming;
use App\Models\Branch;
use App\Models\Collaborator\Collaborator;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * One student's admission to one course (`student_admissions`, §69, phase-14-17 §2.15).
 *
 * **This row carries §68's pipeline, and the column is called `stage`** ([D-IN-17]). `students.status`
 * already exists and advances beside it; two columns named `status` on two tables joined in every query
 * is how a screen comes to read the wrong one. Phase 18 asks for `student_admissions.status`, so a
 * `status` accessor aliases `stage` and Phase 18's code compiles unchanged — one column, two spellings.
 *
 * **It is also the spine's default commission document, which is why the figures freeze.** Phase 18
 * stamps `figures_locked_at` on the first charge; from that moment a commission has been computed from
 * these numbers and money may already have moved, so editing them would silently change what a partner
 * earned. `updateFigures()` refuses after the lock and a correction becomes a fee adjustment, which
 * leaves its own row (INV-I2).
 *
 * **Four money columns are caches only Phase 18 may write.** `charged_amount`, `paid_amount`,
 * `refunded_amount` and `balance_amount` are sums of `student_fees` and its receipts. The `updating`
 * hook below refuses a write to any of them outside `StudentFeeService::withinServiceContext()`, so a
 * screen that "just updates the balance" cannot exist — the whole point of a cache is that it is
 * re-derivable, and a second writer is what makes one stop being so.
 */
class StudentAdmission extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'student_admissions';

    /** The four caches Phase 18 owns. Anything else writing them is a defect, not a shortcut. */
    public const FEE_CACHE_COLUMNS = ['charged_amount', 'paid_amount', 'refunded_amount', 'balance_amount'];

    /**
     * `admission_number`, `stage` and the four caches are absent: the number is issued by the numbering
     * service, the stage moves only through `AdmissionService`, and the caches belong to Phase 18.
     *
     * @var list<string>
     */
    protected $fillable = [
        'branch_id', 'student_id', 'course_id', 'batch_id',
        'student_application_id', 'course_inquiry_id',
        'admission_date', 'counselor_id', 'delivery_mode', 'preferred_timing',
        'course_fee', 'admission_fee', 'registration_fee',
        'discount_amount', 'scholarship_amount', 'discount_reason',
        'payment_method', 'monthly_fee',
        'installment_plan_requested', 'requested_installments', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'course_id' => 'integer',
            'batch_id' => 'integer',
            'branch_id' => 'integer',
            'counselor_id' => 'integer',
            'collaborator_id' => 'integer',
            'stage' => AdmissionStage::class,
            'delivery_mode' => DeliveryMode::class,
            'preferred_timing' => PreferredTiming::class,
            'admission_date' => 'date',
            'registration_date' => 'date',
            'activated_on' => 'date',
            'completed_on' => 'date',
            'figures_locked_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'installment_plan_requested' => 'boolean',
            'requested_installments' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The four caches are Phase 18's to write, and this is where that is enforced rather than
        // asked politely in a docblock. `withinServiceContext()` is published by Phase 18 §6.1 (F-4.6);
        // until it ships, no code can be inside it, so the guard simply refuses — which is correct,
        // because nothing should be writing these columns before the service that owns them exists.
        static::updating(static function (StudentAdmission $admission): void {
            $touched = array_intersect(self::FEE_CACHE_COLUMNS, array_keys($admission->getDirty()));

            if ($touched === [] || $admission->feeServiceIsWriting()) {
                return;
            }

            throw new LogicException(sprintf(
                'student_admissions.%s is a cache of the fee rows and is written only by '
                .'StudentFeeService. Change the charges or the receipts; the cache follows.',
                implode(', ', $touched),
            ));
        });
    }

    public function moduleSlug(): string
    {
        return 'admissions';
    }

    protected function activityModule(): ?string
    {
        return 'admissions';
    }

    /**
     * Every agreed figure is logged, because "what did we agree" is the question a discount dispute
     * turns on, and the answer has to survive the row being edited.
     *
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'stage', 'admission_date', 'registration_date', 'batch_id', 'counselor_id',
            'course_fee', 'admission_fee', 'registration_fee',
            'discount_amount', 'scholarship_amount', 'discount_reason',
            'total_amount', 'net_payable', 'monthly_fee',
            'installment_plan_requested', 'requested_installments',
            'cancellation_reason', 'withdrawal_reason',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    /**
     * Phase 18 §13.1 spells this `status`; §68 and this table call it `stage`. Same column.
     */
    public function getStatusAttribute(): AdmissionStage
    {
        return $this->stage;
    }

    public function setStatusAttribute(AdmissionStage|string $value): void
    {
        $this->attributes['stage'] = $value instanceof AdmissionStage ? $value->value : $value;
    }

    /** Are the agreed figures frozen? (INV-I2) */
    public function figuresAreLocked(): bool
    {
        return $this->figures_locked_at !== null;
    }

    /**
     * `total_amount` as arithmetic rather than as a column read, for the form's live preview. The
     * stored column is what the service wrote through `Money`; this is the same sum, so a form that
     * disagrees with the row is visible immediately rather than after the charge.
     */
    public function computedTotal(): string
    {
        return Money::sum(
            (string) $this->course_fee,
            (string) $this->admission_fee,
            (string) $this->registration_fee,
        );
    }

    public function computedNetPayable(): string
    {
        $net = Money::sub(
            Money::sub($this->computedTotal(), (string) $this->discount_amount),
            (string) $this->scholarship_amount,
        );

        // Never below free: `chk_sadm_discount_ceiling` forbids it in the table, and the preview says
        // the same thing before the form is submitted rather than after.
        return Money::compare($net, Money::ZERO) < 0 ? Money::ZERO : $net;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Still running. Reads `active_guard`, the generated column `uq_sadm_live` depends on, so the scope
     * and the unique index can never disagree about what "live" means.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNotNull('active_guard');
    }

    public function scopeAtStage(Builder $query, AdmissionStage|string $stage): Builder
    {
        return $query->where('stage', $stage instanceof AdmissionStage ? $stage->value : $stage);
    }

    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        if ($branchId === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($branchId): void {
            $q->where('branch_id', $branchId)->orWhereNull('branch_id');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(StudentApplication::class, 'student_application_id');
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(CourseInquiry::class, 'course_inquiry_id');
    }

    public function counselor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counselor_id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Is Phase 18's fee service the one writing? Asked through the container so this model carries no
     * compile-time dependency on a class three phases away.
     */
    private function feeServiceIsWriting(): bool
    {
        $service = 'App\\Services\\Institute\\StudentFeeService';

        return class_exists($service)
            && method_exists($service, 'withinServiceContext')
            && (bool) $service::withinServiceContext();
    }
}
