<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\SalaryStructureStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use App\Services\Hr\Exceptions\ImmutableSalaryStructureException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of one employee's salary (phase-07 §2.19, requirement §28, HR-10).
 *
 * **A raise is never an UPDATE.** The open version is closed at `effective_from - 1 day` and a successor
 * carries `version + 1` and `supersedes_id`. That is what lets a payroll run from last March still point
 * at the numbers that were true last March — editing the row in place would rewrite the past silently and
 * every slip that referenced it would start lying.
 *
 * {@see UPDATABLE} is therefore short on purpose: a version's money, dates and lines are frozen the moment
 * it exists, and only its status, its closing date, its successor link and its approval may move.
 * `uq_ss_open` over the generated guard enforces **at most one open version per employee** in the
 * database, so the rule survives a service somebody bypasses.
 *
 * `change_reason` is **mandatory**. A salary that changed with no recorded reason is exactly what an audit
 * cannot resolve a year later.
 *
 * `net_salary_estimate` is display only — **no slip ever reads it**. A slip's net is the sum of its own
 * stored component rows (HR-13).
 */
class SalaryStructure extends Model
{
    use Blameable;
    use LogsActivityWithContext;

    /** The only columns a written version may ever move (HR-10). */
    public const UPDATABLE = [
        'status',
        'effective_to',
        'superseded_by_id',
        'approved_by',
        'approved_at',
        'updated_at',
        'updated_by',
    ];

    protected $table = 'salary_structures';

    /**
     * Deliberately empty: `SalaryStructureService::createVersion()` writes every column explicitly, and
     * nothing else writes this table at all.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'scheduled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'version' => 'integer',
            'supersedes_id' => 'integer',
            'superseded_by_id' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'basic_salary' => 'decimal:2',
            'gross_salary' => 'decimal:2',
            'total_deduction_amount' => 'decimal:2',
            'net_salary_estimate' => 'decimal:2',
            'status' => SalaryStructureStatus::class,
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (SalaryStructure $structure): void {
            $touched = array_values(array_diff(array_keys($structure->getDirty()), self::UPDATABLE));

            if ($touched !== []) {
                throw ImmutableSalaryStructureException::version($structure->getKey() ?? 'new', $touched);
            }
        });

        static::deleting(static function (SalaryStructure $structure): void {
            throw ImmutableSalaryStructureException::version(
                $structure->getKey() ?? 'new',
                ['the row itself — a version is superseded, never deleted'],
            );
        });
    }

    public function moduleSlug(): string
    {
        return 'salary_structures';
    }

    protected function activityModule(): ?string
    {
        return 'salary_structures';
    }

    /**
     * Is this the version in force, or the one about to be?
     */
    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function components(): HasMany
    {
        return $this->hasMany(SalaryStructureComponent::class, 'salary_structure_id')->orderBy('sort_order');
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollRunItem::class, 'salary_structure_id');
    }
}
