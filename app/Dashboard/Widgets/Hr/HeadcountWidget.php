<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Hr;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\EmployeeStatus;
use App\Models\Hr\Employee;
use App\Support\DateRange;
use Throwable;

/**
 * Who is on the roll, and who joined or left inside the dashboard's range (requirement §24, T57).
 *
 * **The headline is the people the business can actually call on today** — `active` plus
 * `probation`, the two states {@see EmployeeStatus::isPayrollEligible()} recognises. A plain
 * `COUNT(*)` over `employees` is a bigger and more flattering number, and it counts everybody who
 * ever resigned: a card that says "41 employees" to a business with 33 is worse than no card.
 *
 * **Movement sits above the breakdown.** "Three joined and one left this month" is what somebody
 * acts on — an induction, a handover, a final salary — while the status split is the reference
 * underneath it. Joiners and leavers follow the dashboard's range, because that is the one figure
 * here that *is* a period rather than a state.
 *
 * **Probation is called out on its own.** It is the only status with a deadline attached to it:
 * a confirmation that nobody runs is a decision the business made by forgetting.
 *
 * **One query.** Every figure below is an aggregate over the same rows of `employees`, so they are
 * counted in a single pass with conditional sums rather than by asking the table once per number —
 * `GET /admin` is measured against a query ceiling and five cards asking the obvious way is how it
 * is broken. `joining_date` and `exit_date` are `date` columns, so they are compared against plain
 * date strings: `whereDate()` on a column that is already a DATE only makes the index unusable.
 *
 * The status values come from the enum's own predicates, never from a string written here
 * (golden rule 8) — the employee list screen, the payroll eligibility check and this card have to
 * agree, and they only do that by asking the same methods.
 */
final class HeadcountWidget extends Widget
{
    public function key(): string
    {
        return 'hr_headcount';
    }

    public function title(): string
    {
        return 'Headcount';
    }

    public function icon(): string
    {
        return 'identification';
    }

    public function permission(): ?string
    {
        return 'employees.view_any';
    }

    public function module(): ?string
    {
        return 'employees';
    }

    public function group(): string
    {
        return WidgetGroup::PEOPLE;
    }

    public function sort(): int
    {
        return 10;
    }

    /**
     * The whole register, unfiltered: the card reports every state, so pre-filtering the screen to
     * one of them would answer a question the card did not ask.
     */
    public function href(): ?string
    {
        return $this->routeUrl('admin.employees.index');
    }

    public function emptyMessage(): ?string
    {
        return 'No employee records yet.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $onRoll = self::statusValues(static fn (EmployeeStatus $status): bool => $status->isPayrollEligible());
        $exited = self::statusValues(static fn (EmployeeStatus $status): bool => $status->isExited());

        $from = $range->start->toDateString();
        $to = $range->end->toDateString();

        try {
            $row = Employee::query()
                ->toBase()
                ->selectRaw(
                    'COUNT(*) as total,'
                    .' SUM(CASE WHEN '.self::inList('status', $onRoll).' THEN 1 ELSE 0 END) as on_roll,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as active,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as probation,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as suspended,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as inactive,'
                    .' SUM(CASE WHEN '.self::inList('status', $exited).' THEN 1 ELSE 0 END) as exited,'
                    .' SUM(CASE WHEN joining_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as joined,'
                    .' SUM(CASE WHEN exit_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as departed',
                    [
                        ...$onRoll,
                        EmployeeStatus::Active->value,
                        EmployeeStatus::Probation->value,
                        EmployeeStatus::Suspended->value,
                        EmployeeStatus::Inactive->value,
                        ...$exited,
                        $from,
                        $to,
                        $from,
                        $to,
                    ],
                )
                ->first();
        } catch (Throwable) {
            return ['available' => false];
        }

        return [
            'available' => true,
            // Everybody the register holds, including the people who have left — the denominator
            // the empty state is decided on, so a business with only ex-employees still sees a card
            // rather than "no employee records yet".
            'total' => (int) ($row->total ?? 0),
            'on_roll' => (int) ($row->on_roll ?? 0),
            'active' => (int) ($row->active ?? 0),
            'probation' => (int) ($row->probation ?? 0),
            'suspended' => (int) ($row->suspended ?? 0),
            'inactive' => (int) ($row->inactive ?? 0),
            'exited' => (int) ($row->exited ?? 0),
            'joined' => (int) ($row->joined ?? 0),
            'departed' => (int) ($row->departed ?? 0),
            'range_label' => $range->label(),
        ];
    }

    /**
     * The values of every status the predicate accepts — the enum decides, not a literal.
     *
     * @param  callable(EmployeeStatus): bool  $predicate
     * @return list<string>
     */
    private static function statusValues(callable $predicate): array
    {
        return array_values(array_map(
            static fn (EmployeeStatus $status): string => $status->value,
            array_filter(EmployeeStatus::cases(), $predicate),
        ));
    }

    /**
     * `status IN (?, ?)` with one placeholder per value — and a condition that is simply false when
     * the list is empty, because `IN ()` is a syntax error rather than an empty result.
     *
     * @param  list<string>  $values
     */
    private static function inList(string $column, array $values): string
    {
        if ($values === []) {
            return '1 = 0';
        }

        return $column.' IN ('.implode(', ', array_fill(0, count($values), '?')).')';
    }
}
