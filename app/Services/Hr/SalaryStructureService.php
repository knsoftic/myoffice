<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\SalaryComponentCalculation;
use App\Enums\SalaryComponentGroup;
use App\Enums\SalaryComponentType;
use App\Enums\SalaryStructureStatus;
use App\Models\Hr\Employee;
use App\Models\Hr\PayrollRunItem;
use App\Models\Hr\SalaryComponent;
use App\Models\Hr\SalaryStructure;
use App\Models\Hr\SalaryStructureComponent;
use App\Services\Hr\Exceptions\HrRuleException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Salary structures as immutable versions (phase-07 §6.2, HR-10, HR-11).
 *
 * **A rate is never UPDATEd.** A raise closes the open version at the day before the new one starts and
 * inserts version + 1 pointing back at it. That is the whole design: a slip generated in March keeps
 * pointing at the version that was in force in March, and "what was Ali earning last year?" is a lookup
 * rather than an archaeology exercise. `uq_ss_open` makes at most one open version per employee a
 * database fact rather than a service's good intentions.
 *
 * **`employees.current_gross_salary` is a cache** (HR-11) written only from here. No payroll figure is
 * ever read from it — a slip reads the structure version it actually used — because the cache exists for
 * a list screen and a list screen is not evidence.
 *
 * Two refusals worth naming: back-dating into a **locked** payroll period (§6.10 #18), which would strand
 * a paid slip behind a structure that no longer says what it was paid from; and an `effective_from` that
 * overlaps a closed version, which would make "which version applied on the 14th?" ambiguous.
 */
class SalaryStructureService
{
    /**
     * Add a version (§6.2). The reason is mandatory — a salary that changed without one is the first thing
     * anybody asks about and the last thing anybody can reconstruct.
     *
     * @param  list<array{salary_component_id: int, rate?: string, amount?: string, sort_order?: int}>  $components
     */
    public function createVersion(
        Employee $employee,
        Carbon $effectiveFrom,
        string $basicSalary,
        array $components,
        string $reason,
        ?int $actorId = null,
    ): SalaryStructure {
        $effectiveFrom = $effectiveFrom->copy()->startOfDay();
        $basicSalary = Money::round($basicSalary);

        if (trim($reason) === '') {
            throw HrRuleException::reasonRequired(
                'change_reason',
                'Say why this version exists — "annual increment 2026", "promotion to senior". A salary '
                .'that changed for no recorded reason is unexplainable later.'
            );
        }

        if (Money::isNegative($basicSalary)) {
            throw HrRuleException::refuse('basic_salary', 'A basic salary cannot be negative.');
        }

        return DB::transaction(function () use (
            $employee, $effectiveFrom, $basicSalary, $components, $reason, $actorId
        ): SalaryStructure {
            $open = SalaryStructure::query()
                ->where('employee_id', $employee->getKey())
                ->whereNull('effective_to')
                ->whereIn('status', [SalaryStructureStatus::Scheduled, SalaryStructureStatus::Active])
                ->lockForUpdate()
                ->first();

            $previous = SalaryStructure::query()
                ->where('employee_id', $employee->getKey())
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            if ($previous !== null && $effectiveFrom->lessThanOrEqualTo($previous->effective_from)) {
                throw HrRuleException::refuse('effective_from', sprintf(
                    'Version %d already starts on %s. A new version starts after the one it replaces — '
                    .'otherwise two versions would be in force on the same day.',
                    (int) $previous->version,
                    $previous->effective_from->toDateString()
                ));
            }

            $this->assertNoLockedItemBeyond($employee, $effectiveFrom);

            if ($open !== null) {
                $open->forceFill([
                    'effective_to' => $effectiveFrom->copy()->subDay()->toDateString(),
                    'status' => SalaryStructureStatus::Superseded,
                ])->save();
            }

            $lines = $this->resolveLines($components, $basicSalary);

            $structure = new SalaryStructure;
            $structure->forceFill([
                'employee_id' => $employee->getKey(),
                'version' => ($previous->version ?? 0) + 1,
                'supersedes_id' => $open?->getKey(),
                'effective_from' => $effectiveFrom->toDateString(),
                'effective_to' => null,
                'basic_salary' => $basicSalary,
                'gross_salary' => $lines['gross'],
                'total_deduction_amount' => $lines['deductions'],
                'net_salary_estimate' => Money::round(Money::sub($lines['gross'], $lines['deductions'])),
                'status' => $effectiveFrom->greaterThan(now()->startOfDay())
                    ? SalaryStructureStatus::Scheduled
                    : SalaryStructureStatus::Active,
                'change_reason' => trim($reason),
                'approved_by' => $actorId,
                'approved_at' => $actorId === null ? null : now(),
            ]);

            try {
                $structure->save();
            } catch (UniqueConstraintViolationException) {
                // `uq_ss_open` (HR-10) — one open version per employee, decided by the database.
                throw HrRuleException::refuse('effective_from',
                    'This employee already has an open salary version. Reload the screen: somebody else '
                    .'saved a change while this form was open.');
            }

            if ($open !== null) {
                $open->forceFill(['superseded_by_id' => $structure->getKey()])->save();
            }

            foreach ($lines['rows'] as $row) {
                $component = new SalaryStructureComponent;
                $component->forceFill($row + ['salary_structure_id' => $structure->getKey()])->save();
            }

            // HR-11 — the cache, written from here and nowhere else.
            $employee->forceFill(['current_gross_salary' => $lines['gross']])->save();

            return $structure->fresh();
        });
    }

    /**
     * The version in force on a date — what payroll calls, with the **period end**, never `now()`.
     *
     * Using `now()` would make a run generated on the 3rd of next month read next month's structure for
     * last month's salary, which is exactly the kind of error that is invisible until an increment lands.
     */
    public function effectiveOn(Employee $employee, Carbon $date): ?SalaryStructure
    {
        $date = $date->copy()->startOfDay();

        return SalaryStructure::query()
            ->with('components')
            ->where('employee_id', $employee->getKey())
            // `scheduled` counts: a version starting in July genuinely governs August, and "what will
            // August pay?" is a question the payroll screen asks every month.
            ->whereIn('status', [
                SalaryStructureStatus::Scheduled,
                SalaryStructureStatus::Active,
                SalaryStructureStatus::Superseded,
                SalaryStructureStatus::Expired,
            ])
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date->toDateString()))
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Activate every scheduled version whose start date has arrived — the nightly command's one job.
     */
    public function activateDue(?Carbon $on = null): int
    {
        $on = ($on ?? now())->copy()->startOfDay();

        return SalaryStructure::query()
            ->where('status', SalaryStructureStatus::Scheduled)
            ->whereDate('effective_from', '<=', $on->toDateString())
            ->update([
                'status' => SalaryStructureStatus::Active->value,
                'updated_at' => now(),
            ]);
    }

    /**
     * Withdraw a version that has not started yet.
     *
     * Only while `scheduled` and only while nothing references it: once a slip has been generated against
     * a version, the version is evidence and the remedy is a new version, not a deletion.
     */
    public function cancel(SalaryStructure $structure, string $reason): SalaryStructure
    {
        if (trim($reason) === '') {
            throw HrRuleException::reasonRequired('change_reason', 'Say why the scheduled change is being withdrawn.');
        }

        if ($structure->status !== SalaryStructureStatus::Scheduled) {
            throw HrRuleException::refuse('status', sprintf(
                'This version is %s, not scheduled. A version that has already been in force is history; '
                .'change the salary by adding the next version instead.',
                $structure->status->label()
            ));
        }

        if (PayrollRunItem::query()->where('salary_structure_id', $structure->getKey())->exists()) {
            throw HrRuleException::refuse('id',
                'A salary slip was already generated from this version, so it is evidence. Supersede it '
                .'with a new version instead.');
        }

        return DB::transaction(function () use ($structure): SalaryStructure {
            $structure->forceFill([
                'status' => SalaryStructureStatus::Cancelled,
                'effective_to' => $structure->effective_from->toDateString(),
            ])->save();

            $previous = $structure->supersedes_id === null
                ? null
                : SalaryStructure::query()->find($structure->supersedes_id);

            if ($previous !== null) {
                // Re-open the version this one had closed, so the employee is not left with no structure.
                $previous->forceFill([
                    'effective_to' => null,
                    'superseded_by_id' => null,
                    'status' => SalaryStructureStatus::Active,
                ])->save();

                $previous->employee?->forceFill([
                    'current_gross_salary' => (string) $previous->gross_salary,
                ])->save();
            }

            return $structure;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Turn the chosen components into snapshot rows and the two totals.
     *
     * The **snapshot** is the point: a component retired next year cannot change what a structure written
     * this year says it contains (§6.10 #14).
     *
     * @param  list<array<string, mixed>>  $components
     * @return array{rows: list<array<string, mixed>>, gross: string, deductions: string}
     */
    private function resolveLines(array $components, string $basicSalary): array
    {
        $rows = [];
        $earnings = [];
        $deductions = [];
        $seen = [];
        $sort = 0;

        foreach ($components as $chosen) {
            $component = SalaryComponent::query()->find($chosen['salary_component_id'] ?? null);

            if ($component === null) {
                continue;
            }

            if (isset($seen[$component->getKey()])) {
                throw HrRuleException::refuse('components', sprintf(
                    '%s appears twice in this structure. One line per component.',
                    $component->name
                ));
            }

            $seen[$component->getKey()] = true;

            $group = $component->component_group;

            if ($group->isSystem()) {
                throw HrRuleException::refuse('components', sprintf(
                    '%s is produced by payroll itself, from attendance or from the advance ledger. A '
                    .'hand-entered line under the same total could never be explained.',
                    $component->name
                ));
            }

            $calculation = $component->calculation_type;
            $rate = Money::round((string) ($chosen['rate'] ?? $component->default_rate), 4);
            $amount = Money::round((string) ($chosen['amount'] ?? $component->default_amount));

            $resolved = match ($calculation) {
                SalaryComponentCalculation::PercentageOfBasic => Money::round(Money::percentage($basicSalary, $rate)),
                SalaryComponentCalculation::PercentageOfGross => $amount,
                default => $amount,
            };

            $rows[] = [
                'salary_component_id' => (int) $component->getKey(),
                'component_code' => $component->code,
                'component_name' => $component->print_label ?: $component->name,
                'component_group' => $group->value,
                'side' => $group->side()->value,
                'calculation_type' => $calculation->value,
                'rate' => $rate,
                'amount' => $resolved,
                'is_taxable' => $component->is_taxable,
                'affects_gross' => $component->affects_gross,
                'sort_order' => (int) ($chosen['sort_order'] ?? $sort),
            ];

            if ($group->side() === SalaryComponentType::Earning) {
                if ($calculation === SalaryComponentCalculation::PercentageOfGross) {
                    // §6.6 step 3 computes these against the pass-1 gross at payroll time; the structure's
                    // own gross cannot include a percentage of itself.
                    continue;
                }

                $earnings[] = $resolved;
            } else {
                $deductions[] = $resolved;
            }

            $sort++;
        }

        $gross = Money::round(Money::add($basicSalary, $earnings === [] ? Money::zero() : Money::sum($earnings)));

        return [
            'rows' => $rows,
            'gross' => $gross,
            'deductions' => $deductions === [] ? Money::zero() : Money::round(Money::sum($deductions)),
        ];
    }

    /**
     * Refuse a version that would strand a locked payroll item (§6.10 #18).
     */
    private function assertNoLockedItemBeyond(Employee $employee, Carbon $effectiveFrom): void
    {
        $item = PayrollRunItem::query()
            ->with('run')
            ->where('employee_id', $employee->getKey())
            ->whereHas('run', fn ($query) => $query
                ->whereNotNull('locked_at')
                ->whereDate('period_end', '>=', $effectiveFrom->toDateString()))
            ->first();

        if ($item !== null) {
            throw HrRuleException::refuse('effective_from', sprintf(
                'Payroll run %s is locked and covers %s, which this change would reach back into. The '
                .'remedy for a paid month is a correction run, not a rewritten salary history.',
                $item->run?->run_number ?? '(a locked run)',
                $item->run?->period_end?->format('F Y') ?? 'that period'
            ));
        }
    }

    /**
     * The component list a structure form offers — everything a human may choose.
     *
     * @return Collection<int, SalaryComponent>
     */
    public function selectableComponents()
    {
        return SalaryComponent::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->reject(fn (SalaryComponent $component): bool => $component->component_group->isSystem())
            ->values();
    }

    /**
     * The groups a structure may carry, for the form's grouping.
     *
     * @return list<SalaryComponentGroup>
     */
    public function selectableGroups(): array
    {
        return array_values(array_filter(
            SalaryComponentGroup::cases(),
            fn (SalaryComponentGroup $group): bool => ! $group->isSystem()
        ));
    }
}
