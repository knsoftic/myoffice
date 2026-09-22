<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Fees;

use App\DataObjects\Institute\DiscountData;
use App\Enums\FeeDiscountType;
use App\Enums\InstallmentStatus;
use App\Enums\StudentFeeStatus;
use App\Models\Institute\StudentFeeDiscount;
use App\Services\Institute\Exceptions\FeeRuleException;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Fees\Concerns\BuildsFees;
use Tests\TestCase;

/**
 * PH18-12 … PH18-18 — reducing what a student owes (phase-18 §6.5, §6.3.3, §11.2).
 *
 * **The append-only rule is the spine of this file.** A discount row is never edited and never
 * deleted, because the net fee on the day a payment arrived has to stay answerable: a commission was
 * computed from it, and money may already have moved. Every "correction" here is a new row.
 */
final class FeeDiscountTest extends TestCase
{
    use BuildsFees;
    use InteractsWithRbac;
    use RefreshDatabase;

    /**
     * PH18-12 — a discount after a payment changes nothing that already happened.
     *
     * The existing ledger entry is not recomputed, not adjusted, not touched. Under the default `paid`
     * base each receipt earns on what that receipt brought in, so history stays history.
     */
    #[Test]
    public function a_discount_applied_after_a_payment_leaves_the_earlier_rows_alone(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge('30000.00', actor: $actor);

        $this->receive($charge, '10000.00');

        $before = $charge->refresh()->only(['paid_amount', 'refunded_amount']);

        $this->discount($charge, '5000.00');

        $charge->refresh();

        $this->assertSame('25000.00', (string) $charge->net_amount);
        $this->assertSame('15000.00', (string) $charge->balance_amount);
        $this->assertSame($before['paid_amount'], (string) $charge->paid_amount, 'Money received does not move.');
        $this->assertSame(StudentFeeStatus::Partial, $charge->status);
        $this->assertCachesMatchRows($charge);
    }

    /**
     * PH18-13 — a discount redistributes a live plan from the FAR END.
     *
     * The reduction comes off the latest unpaid installments, not the next one due: a student may
     * already have arranged next week's payment, and moving that is a phone call nobody wanted.
     */
    #[Test]
    public function a_discount_redistributes_a_live_plan_and_keeps_pi1(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge('30000.00', actor: $actor);
        $lines = $this->plan($charge, 3, actor: $actor);

        $this->receive($charge, (string) $lines->first()->amount, $lines->first());

        $this->discount($charge, '5000.00');

        $this->assertSame(
            ['10000.00', '10000.00', '5000.00'],
            $this->amountsOf($charge),
            'The last line absorbs it; the paid one and the next one due are untouched.',
        );

        $this->assertPlanIntegrity($charge);
        $this->assertCachesMatchRows($charge);
    }

    /**
     * PH18-13 — a discount larger than the remaining schedule cancels lines and leaves an advance.
     *
     * The unconsumed remainder is **not** forced onto the paid line. Money already received is
     * evidence, not a slot; the charge simply ends up with a negative balance, which is an advance.
     */
    #[Test]
    public function a_large_discount_cancels_lines_and_leaves_the_charge_in_advance(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge('30000.00', actor: $actor);
        $lines = $this->plan($charge, 3, actor: $actor);

        $this->receive($charge, (string) $lines->first()->amount, $lines->first());

        $this->discount($charge, '25000.00');

        $charge->refresh();

        $this->assertSame('5000.00', (string) $charge->net_amount);
        $this->assertSame('-5000.00', (string) $charge->balance_amount, 'A negative balance is an advance.');
        $this->assertSame(StudentFeeStatus::Overpaid, $charge->status);

        $cancelled = $charge->installments()->where('status', InstallmentStatus::Cancelled->value)->count();

        $this->assertSame(2, $cancelled, 'The two unpaid lines were cancelled rather than zeroed.');
        $this->assertPlanIntegrity($charge);
        $this->assertCachesMatchRows($charge);
    }

    /**
     * PH18-14 — a waiver moves both sides of PI-1 at once, so it needs no redistribution.
     *
     * That is the whole difference between a waiver and a discount: a waiver reduces `net_amount` by
     * exactly the amount it parks in the line's `waived_amount`, and a discount moves only one side.
     */
    #[Test]
    public function a_waiver_reduces_the_net_fee_and_keeps_pi1(): void
    {
        $actor = $this->createUserWithPermissions([
            'installments.change_status', 'fee_discounts.approve', 'fee_discounts.create',
        ]);

        $charge = $this->charge('30000.00', actor: $actor);
        $lines = $this->plan($charge, 3, actor: $actor);
        $target = $lines->last();

        $this->fees()->waiveInstallment($target, '4000.00', 'Hardship', $actor);

        $charge->refresh();
        $target->refresh();

        $this->assertSame('26000.00', (string) $charge->net_amount);
        $this->assertSame('4000.00', (string) $target->waived_amount);
        // §6.4.2: `settled = paid + waived`, and anything settled above zero is `partial`. A line
        // with 4,000 of its 10,000 released is partly dealt with, which is what the word says.
        $this->assertSame(InstallmentStatus::Partial, $target->status, 'Partly waived is partial, not pending.');
        $this->assertPlanIntegrity($charge);

        // Waiving the rest moves the line to `waived`.
        $this->fees()->waiveInstallment($target->refresh(), '6000.00', 'Hardship, the rest', $actor);

        $this->assertSame(InstallmentStatus::Waived, $target->refresh()->status);
        $this->assertSame('20000.00', (string) $charge->refresh()->net_amount);
        $this->assertPlanIntegrity($charge);
        $this->assertCachesMatchRows($charge);
    }

    /** PH18-14 — a waiver can only release what is still owed. */
    #[Test]
    public function a_waiver_larger_than_the_line_is_refused(): void
    {
        $actor = $this->createUserWithPermissions([
            'installments.change_status', 'fee_discounts.approve', 'fee_discounts.create',
        ]);

        $charge = $this->charge('30000.00', actor: $actor);
        $line = $this->plan($charge, 3, actor: $actor)->first();

        try {
            $this->fees()->waiveInstallment($line, '12000.00', 'Too much', $actor);
            $this->fail('A waiver larger than the line must be refused.');
        } catch (FeeRuleException $e) {
            $this->assertStringContainsString('10,000.00', implode(' ', array_merge(...array_values($e->errors()))));
        }

        $this->assertSame('0.00', (string) $line->refresh()->waived_amount, 'Nothing was written.');
        $this->assertSame('30000.00', (string) $charge->refresh()->net_amount);
    }

    /**
     * PH18-15 — a percentage discount stores BOTH the percentage and the amount it worked out to.
     *
     * Storing only the percentage would mean the amount is re-derived from whatever `gross_amount`
     * happens to be later; storing only the amount would lose what was actually agreed. The row keeps
     * both, and neither is ever recomputed.
     */
    #[Test]
    public function a_percentage_discount_stores_both_the_percentage_and_the_amount(): void
    {
        $actor = $this->createUserWithPermissions(['fee_discounts.approve', 'fee_discounts.create']);
        $charge = $this->charge('30000.00', actor: $actor);

        $this->fees()->addDiscount($charge, new DiscountData(
            type: FeeDiscountType::PercentageDiscount,
            reason: 'Early payment',
            percentage: '10.0000',
            approvedBy: (int) $actor->getKey(),
        ), $actor);

        $row = StudentFeeDiscount::query()->firstOrFail();

        $this->assertSame('10.0000', (string) $row->percentage);
        $this->assertSame('-3000.00', (string) $row->amount, 'The computed amount is stored, not re-derived.');
        $this->assertSame('27000.00', (string) $charge->refresh()->net_amount);
        $this->assertCachesMatchRows($charge);
    }

    /** PH18-16 — a discount can never take the net fee below zero. */
    #[Test]
    public function a_discount_can_never_exceed_the_fee(): void
    {
        $actor = $this->createUserWithPermissions(['fee_discounts.approve', 'fee_discounts.create']);
        $charge = $this->charge('30000.00', actor: $actor);

        $this->discount($charge, '20000.00', approver: $actor, actor: $actor);

        try {
            $this->discount($charge, '15000.00', FeeDiscountType::Scholarship, approver: $actor, actor: $actor);
            $this->fail('20,000 + 15,000 against a 30,000 gross must be refused.');
        } catch (FeeRuleException $e) {
            $this->assertStringContainsString(
                'more than the gross fee',
                implode(' ', array_merge(...array_values($e->errors()))),
            );
        }

        $charge->refresh();

        $this->assertSame('10000.00', (string) $charge->net_amount, 'The first discount stands; the second was refused.');
        $this->assertSame('0.00', (string) $charge->scholarship_amount);
        $this->assertCachesMatchRows($charge);
    }

    /**
     * PH18-16 — and the database refuses it too, on a raw write.
     *
     * `chk_sf_discount_ceiling` is the backstop the service's own check sits in front of. Both are
     * asserted because the service's version produces a sentence somebody can act on and the CHECK
     * produces a constraint name — and only one of them survives a seeder or an import.
     */
    #[Test]
    public function the_database_refuses_a_net_fee_below_zero(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge('30000.00', actor: $actor);

        $this->expectException(QueryException::class);

        $charge->forceFill([
            'discount_amount' => '20000.00',
            'scholarship_amount' => '15000.00',
        ])->save();
    }

    /**
     * PH18-17 — a discount row is append-only, at three layers.
     *
     * The model refuses the UPDATE and the DELETE, and `trg_sfd_no_delete` refuses a raw DELETE that
     * never reaches the model at all. The model throws first so the refusal arrives with an
     * explanation rather than as SQLSTATE 45000 — the spine's R-5 lesson, which is that a bare
     * constraint error is how somebody eventually "fixes" the problem by dropping the trigger.
     */
    #[Test]
    public function a_discount_row_can_be_neither_edited_nor_deleted(): void
    {
        $actor = $this->createUserWithPermissions(['fee_discounts.approve', 'fee_discounts.create']);
        $charge = $this->charge('30000.00', actor: $actor);

        $this->discount($charge, '5000.00', approver: $actor, actor: $actor);

        $row = StudentFeeDiscount::query()->firstOrFail();

        try {
            $row->forceFill(['amount' => '-1.00'])->save();
            $this->fail('A discount row must not be editable.');
        } catch (LogicException) {
            // Expected: FinancialRow refuses every column outside its whitelist.
        }

        try {
            $row->refresh()->delete();
            $this->fail('A discount row must not be deletable.');
        } catch (LogicException) {
            // Expected.
        }

        $this->assertSame('-5000.00', (string) $row->refresh()->amount, 'The row is exactly as it was.');

        // And the raw path, which never reaches the model.
        try {
            DB::table('student_fee_discounts')->where('id', $row->getKey())->delete();
            $this->fail('trg_sfd_no_delete must refuse a raw DELETE.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('45000', $e->getMessage());
        }

        $this->assertDatabaseCount('student_fee_discounts', 1);
    }

    /**
     * PH18-17 — the only correction is a reversal, and a row can be reversed once.
     */
    #[Test]
    public function a_discount_is_reversed_by_a_mirror_row_and_only_once(): void
    {
        $actor = $this->createUserWithPermissions(['fee_discounts.approve', 'fee_discounts.create']);
        $charge = $this->charge('30000.00', actor: $actor);

        $this->discount($charge, '5000.00', approver: $actor, actor: $actor);

        $original = StudentFeeDiscount::query()->firstOrFail();

        $reversal = $this->fees()->reverseDiscount($original, 'Applied to the wrong charge', $actor);

        $this->assertSame('5000.00', (string) $reversal->amount, 'The mirror of a -5,000 reduction.');
        $this->assertSame((int) $original->getKey(), (int) $reversal->reverses_discount_id);
        $this->assertSame('30000.00', (string) $charge->refresh()->net_amount, 'The fee is back where it was.');
        $this->assertSame('-5000.00', (string) $original->refresh()->amount, 'The original is untouched.');

        // `uq_sfd_reverses` makes a second reversal of the same row impossible.
        $this->expectException(QueryException::class);

        $this->fees()->reverseDiscount($original->refresh(), 'Again', $actor);
    }

    /**
     * PH18-18 — a scholarship feeds its own cache, and a referral discount earns nobody anything.
     *
     * The split exists because a scholarship is budgeted separately from a commercial discount. And a
     * `referral_discount` is a reduction **to the student**, not a payment to a partner: its only
     * commission effect is the smaller net fee under a non-`paid` base.
     */
    #[Test]
    public function a_scholarship_feeds_its_own_cache_and_a_referral_discount_creates_no_ledger_row(): void
    {
        $actor = $this->createUserWithPermissions(['fee_discounts.approve', 'fee_discounts.create']);
        $charge = $this->charge('30000.00', actor: $actor);

        $this->discount($charge, '5000.00', FeeDiscountType::Scholarship, approver: $actor, actor: $actor);
        $this->discount($charge, '2000.00', FeeDiscountType::PromotionalDiscount, approver: $actor, actor: $actor);
        $this->discount($charge, '1000.00', FeeDiscountType::ReferralDiscount, approver: $actor, actor: $actor);

        $charge->refresh();

        $this->assertSame('5000.00', (string) $charge->scholarship_amount, 'Only the scholarship is in its own bucket.');
        $this->assertSame('3000.00', (string) $charge->discount_amount, 'The other two share the discount bucket.');
        $this->assertSame('22000.00', (string) $charge->net_amount);

        // A referral discount is money the student does not pay, not money a partner earns: its only
        // commission effect is the smaller net fee under a non-`paid` base.
        $this->assertDatabaseCount('collaborator_commission_ledger_entries', 0);

        $this->assertCachesMatchRows($charge);
    }

    /**
     * [D18-8] — the approver has to hold the ability, and cannot be the creator unless they do.
     *
     * This is the segregation the spine applies to payouts, stated rather than assumed. Recording
     * somebody as the approver who could not have approved it is worse than leaving it blank.
     */
    #[Test]
    public function an_approver_who_cannot_approve_is_refused(): void
    {
        $creator = $this->createUserWithPermissions(['fee_discounts.create']);
        $bystander = $this->createUserWithPermissions(['student_fees.view']);

        $charge = $this->charge('30000.00', actor: $creator);

        try {
            $this->fees()->addDiscount($charge, new DiscountData(
                type: FeeDiscountType::FixedDiscount,
                reason: 'Signed off by somebody who cannot sign it off',
                amount: '5000.00',
                approvedBy: (int) $bystander->getKey(),
            ), $creator);
            $this->fail('An approver without the ability must be refused.');
        } catch (FeeRuleException $e) {
            $this->assertStringContainsString(
                'approval permission',
                implode(' ', array_merge(...array_values($e->errors()))),
            );
        }

        // And approving your own, without holding the ability yourself.
        try {
            $this->fees()->addDiscount($charge, new DiscountData(
                type: FeeDiscountType::FixedDiscount,
                reason: 'Signed off by me',
                amount: '5000.00',
                approvedBy: (int) $creator->getKey(),
            ), $creator);
            $this->fail('Self-approval without the ability must be refused.');
        } catch (FeeRuleException) {
            // Expected.
        }

        // Nothing was written by either attempt.
        $this->assertDatabaseCount('student_fee_discounts', 0);
        $this->assertSame('30000.00', (string) $charge->refresh()->net_amount);
    }

    /** A discount takes effect on a date that has arrived. */
    #[Test]
    public function a_future_dated_discount_is_refused(): void
    {
        $actor = $this->createUserWithPermissions(['fee_discounts.approve', 'fee_discounts.create']);
        $charge = $this->charge('30000.00', actor: $actor);

        $this->expectException(FeeRuleException::class);

        $this->fees()->addDiscount($charge, new DiscountData(
            type: FeeDiscountType::FixedDiscount,
            reason: 'Next month',
            amount: '5000.00',
            approvedBy: (int) $actor->getKey(),
            effectiveOn: now()->addWeek(),
        ), $actor);
    }
}
