<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Fees;

use App\Enums\InstallmentInterval;
use App\Enums\InstallmentStatus;
use App\Enums\RemainderPlacement;
use App\Services\Institute\Exceptions\FeeRuleException;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Fees\Concerns\BuildsFees;
use Tests\TestCase;

/**
 * PH18-07 … PH18-11 — the arithmetic this phase owns (phase-18 §6.2, §6.3, §11.2).
 *
 * **The sums are compared as STRINGS.** `assertSame('30000.00', ...)` and not `assertEquals(30000.0)`:
 * the whole point of `App\Support\Money` is that money never becomes a float, and a test that casts to
 * one to compare it has stopped testing the thing it is named after. A plan that is one paisa short is
 * a charge that can never reach `paid`, and a float comparison would call it fine.
 */
final class InstallmentPlanTest extends TestCase
{
    use BuildsFees;
    use InteractsWithRbac;
    use RefreshDatabase;

    /** PH18-07 — the lines sum exactly to the net fee. */
    #[Test]
    public function installment_amounts_sum_exactly_to_the_net_fee(): void
    {
        $actor = $this->createSuperAdmin();

        foreach ([['10000.00', 3], ['25000.00', 7], ['33333.33', 9], ['100.01', 2], ['0.05', 5], ['47500.00', 12]] as [$net, $count]) {
            $charge = $this->charge($net, actor: $actor);
            $lines = $this->plan($charge, $count, actor: $actor);

            $this->assertCount($count, $lines, sprintf('%s over %d lines.', $net, $count));
            $this->assertSame($net, Money::sum($this->amountsOf($charge)), sprintf('%s must split exactly.', $net));

            foreach ($this->amountsOf($charge) as $amount) {
                $this->assertSame(1, Money::compare($amount, '0.00'), 'Every line is payable.');
            }

            $dates = $charge->refresh()->installments()->orderBy('installment_no')->pluck('due_date');

            foreach ($dates as $index => $date) {
                if ($index > 0) {
                    $this->assertTrue($date->greaterThan($dates[$index - 1]), 'Due dates move forward.');
                }
            }

            $this->assertPlanIntegrity($charge);
        }
    }

    /**
     * PH18-07, the refusals.
     *
     * `0.04 / 5` matters more than it looks: `Money::distribute()` splits it perfectly well into
     * `0.00 0.01 0.01 0.01 0.01`, and that first line is a row the student can never pay and
     * `chk_sfi_amount` would reject anyway. The calculator refuses it with the two numbers in the
     * sentence rather than letting the database refuse it with a constraint name.
     */
    #[Test]
    public function a_plan_that_cannot_be_split_is_refused_with_the_numbers_in_it(): void
    {
        $actor = $this->createSuperAdmin();

        foreach ([['0.04', 5, 'less than one paisa each'], ['10000.00', 1, 'between 2 and 60'], ['10000.00', 61, 'between 2 and 60']] as [$net, $count, $expected]) {
            $charge = $this->charge($net, actor: $actor);

            try {
                $this->calculator()->generate((string) $charge->net_amount, $count, Carbon::today()->addMonth(), InstallmentInterval::Monthly);
                $this->fail(sprintf('%s over %d lines should be refused.', $net, $count));
            } catch (FeeRuleException $e) {
                $this->assertStringContainsString(
                    $expected,
                    implode(' ', array_merge(...array_values($e->errors()))),
                    'The refusal says which two numbers disagree.',
                );
            }

            $this->assertSame(0, $charge->refresh()->installments()->count(), 'Nothing was written.');
        }
    }

    /** PH18-08 — the remainder lands where the setting says, every time. */
    #[Test]
    public function remainder_placement_is_deterministic(): void
    {
        $first = Carbon::parse('2026-03-01');

        $last = $this->calculator()->generate('10000.00', 3, $first, InstallmentInterval::Monthly, RemainderPlacement::Last);
        $front = $this->calculator()->generate('10000.00', 3, $first, InstallmentInterval::Monthly, RemainderPlacement::First);

        $amounts = static fn (array $lines): array => array_map(static fn ($l): string => $l->amount, $lines);

        $this->assertSame(['3333.33', '3333.33', '3333.34'], $amounts($last));
        $this->assertSame(['3333.34', '3333.33', '3333.33'], $amounts($front));

        // Fifty runs, because "deterministic" is the claim and one run cannot make it.
        for ($i = 0; $i < 50; $i++) {
            $this->assertSame(
                ['3333.33', '3333.33', '3333.34'],
                $amounts($this->calculator()->generate('10000.00', 3, $first, InstallmentInterval::Monthly, RemainderPlacement::Last)),
            );
        }
    }

    /**
     * PH18-08 — no float anywhere in the calculator.
     *
     * A static scan rather than a behavioural one: the arithmetic is exact today, and this is what
     * stops somebody making it approximately exact tomorrow with a `round()` that looks harmless.
     */
    #[Test]
    public function the_calculator_contains_no_float_arithmetic(): void
    {
        $source = (string) file_get_contents(app_path('Services/Institute/InstallmentPlanCalculator.php'));
        $stripped = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

        foreach (['(float)', 'floatval', 'fmod', 'round('] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $stripped,
                sprintf('`%s` in the calculator would make an exact split approximate.', $forbidden),
            );
        }
    }

    /**
     * PH18-09 — a monthly plan never overflows a month.
     *
     * Carbon's plain `addMonth()` on 31 January gives 3 March, because it adds one to the month and
     * lets the 31st overflow February. A plan built on the 31st would then drift a day further every
     * short month. `InstallmentInterval::Monthly` clamps instead.
     */
    #[Test]
    public function monthly_due_dates_never_overflow_a_month(): void
    {
        $lines = $this->calculator()->generate('40000.00', 4, Carbon::parse('2026-01-31'), InstallmentInterval::Monthly);

        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
            array_map(static fn ($l): string => $l->dueDate->toDateString(), $lines),
        );

        // A leap year, because 2026 is not one and 28 vs 29 February is the case the clamp exists for.
        $leap = $this->calculator()->generate('20000.00', 2, Carbon::parse('2028-01-31'), InstallmentInterval::Monthly);
        $this->assertSame('2028-02-29', $leap[1]->dueDate->toDateString());

        $fortnightly = $this->calculator()->generate('30000.00', 3, Carbon::parse('2026-03-01'), InstallmentInterval::Fortnightly);
        $this->assertSame(
            ['2026-03-01', '2026-03-15', '2026-03-29'],
            array_map(static fn ($l): string => $l->dueDate->toDateString(), $fortnightly),
        );

        $weekly = $this->calculator()->generate('30000.00', 3, Carbon::parse('2026-03-01'), InstallmentInterval::Weekly);
        $this->assertSame(
            ['2026-03-01', '2026-03-08', '2026-03-15'],
            array_map(static fn ($l): string => $l->dueDate->toDateString(), $weekly),
        );
    }

    /**
     * PH18-10 — [D18-4]: a plan cannot be built once money has arrived.
     *
     * R-2 records why this is a refusal rather than a plan over the remaining balance: the alternative
     * would either break the spine's "line sum = net" rule or write `student_fee_installment_id` onto
     * an existing payment row, which INV-8 forbids.
     */
    #[Test]
    public function a_plan_cannot_be_built_after_money_is_received(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge('30000.00', actor: $actor);

        $this->receive($charge, '5000.00');

        try {
            $this->plan($charge, 3, actor: $actor);
            $this->fail('A plan on a part-paid charge must be refused.');
        } catch (FeeRuleException $e) {
            $message = implode(' ', array_merge(...array_values($e->errors())));
            $this->assertStringContainsString('5,000.00', $message, 'The refusal names what has already been received.');
        }

        $this->assertSame(0, $charge->refresh()->installments()->count(), 'Nothing was written.');

        // And once the receipt is voided, the plan builds — the rule is about money on the charge,
        // not about the charge having ever seen a receipt.
        $this->payments()->void($charge->payments()->firstOrFail(), 'Test void');

        $lines = $this->plan($charge->refresh(), 3, actor: $actor);

        $this->assertCount(3, $lines);
        $this->assertPlanIntegrity($charge);
    }

    /** PH18-11 — the lines have to sum to the net fee, and a duplicate number is impossible. */
    #[Test]
    public function a_plan_whose_lines_do_not_sum_to_the_net_fee_is_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge('30000.00', actor: $actor);

        $short = $this->calculator()->generate('29999.99', 3, Carbon::today()->addMonth(), InstallmentInterval::Monthly);

        try {
            $this->fees()->buildInstallmentPlan($charge, $short, $actor);
            $this->fail('Lines summing to 29,999.99 against a 30,000.00 net fee must be refused.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('PI-1', $e->getMessage());
        }

        $this->assertSame(0, $charge->refresh()->installments()->count(), 'Nothing was written.');

        $exact = $this->plan($charge, 3, actor: $actor);

        $this->assertCount(3, $exact);
        $this->assertPlanIntegrity($charge);
    }

    /**
     * PH18-11, D50 — a rebuild continues from `MAX + 1` and never reuses a number.
     *
     * The result reads 1, 2, 5, 6, which looks like a bug and is the opposite: reusing 3 would make
     * "the third installment" ambiguous in exactly the conversation where it matters.
     */
    #[Test]
    public function a_rebuild_keeps_paid_lines_and_never_reuses_a_number(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge('30000.00', actor: $actor);
        $lines = $this->plan($charge, 3, actor: $actor);

        $paidLine = $lines->first();
        $this->receive($charge, (string) $paidLine->amount, $paidLine);

        $replacement = $this->calculator()->generate(
            netAmount: Money::sub('30000.00', (string) $paidLine->amount),
            count: 2,
            firstDueOn: Carbon::today()->addMonths(4),
            interval: InstallmentInterval::Monthly,
            startNumber: 4,
        );

        $this->fees()->rebuildInstallmentPlan($charge->refresh(), $replacement, 'Student asked to re-spread it', $actor);

        $charge->refresh();

        $numbers = $charge->installments()
            ->whereNot('status', InstallmentStatus::Cancelled->value)
            ->orderBy('installment_no')
            ->pluck('installment_no')
            ->all();

        $this->assertSame([1, 4, 5], $numbers, 'The paid line keeps its number and the new ones continue past the cancelled.');
        $this->assertPlanIntegrity($charge);
        $this->assertCachesMatchRows($charge);
    }

    /** A rebuild changes somebody's schedule, so it takes a reason. */
    #[Test]
    public function a_rebuild_without_a_reason_is_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge('30000.00', actor: $actor);
        $this->plan($charge, 3, actor: $actor);

        $before = $this->amountsOf($charge);

        $this->expectException(FeeRuleException::class);

        try {
            $this->fees()->rebuildInstallmentPlan(
                $charge->refresh(),
                $this->calculator()->generate('30000.00', 2, Carbon::today()->addMonth(), InstallmentInterval::Monthly, startNumber: 4),
                '',
                $actor,
            );
        } finally {
            $this->assertSame($before, $this->amountsOf($charge), 'The existing plan is untouched.');
        }
    }
}
