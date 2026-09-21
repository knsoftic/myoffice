<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\DataObjects\Collaborator\MarkPaidData;
use App\DataObjects\Collaborator\PayoutRequestData;
use App\DataObjects\Collaborator\StatementData;
use App\DataObjects\Collaborator\StatementFilters;
use App\DataObjects\Finance\RefundData;
use App\Enums\LedgerEntryPurpose;
use App\Enums\PayoutMethod;
use App\Enums\ReversalType;
use App\Models\Collaborator\Collaborator;
use App\Services\Collaborator\CollaboratorStatementService;
use App\Services\Collaborator\CommissionReversalService;
use App\Services\Collaborator\PayoutService;
use App\Services\Finance\PaymentService;
use App\Support\DateRange;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\TestCase;

/**
 * The statement, and the proof it carries (phase-10-12 §11 FT-44, spine §6.5.5, §56).
 *
 * The identity `opening + credits − debits − payouts = closing` is the whole test file. It is asserted
 * on a quiet month, on a month with every kind of movement in it, across a period boundary, on a range
 * with nothing in it at all, and against a ledger deliberately corrupted so that it **cannot** hold —
 * because a proof that can only succeed proves nothing.
 */
final class StatementTest extends TestCase
{
    use BuildsFinancialFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting('collaborator.referral_system_enabled', true);
        $this->setting('collaborator.automatic_commission_enabled', true);
        $this->setting('collaborator.commission_approval_mode', 'automatic');
        $this->setting('collaborator.student_commission_base', 'paid');
        $this->setting('collaborator.commission_hold_days', 0);
        $this->setting('collaborator.minimum_payout', '0.00');
        $this->setting('collaborator.payout_auto_approve_below', '0.00');
        $this->setting('collaborator.payout_single_inflight', true);
        $this->setting('collaborator.payout_request_enabled', true);
        $this->setting('collaborator.statement_show_technical_rows', false);
        $this->setting('finance.backdate_limit_days', 3650);
        $this->setting('finance.payout_reference_required', true);
    }

    /*
    |--------------------------------------------------------------------------
    | The identity
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_month_with_every_kind_of_movement_still_balances(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '500000.00');

        $first = $this->receive($fee, '100000.00', ['on' => '2026-03-02'])->payment;
        $this->receive($fee, '50000.00', ['on' => '2026-03-09', 'key' => 'r2']);

        // A refund in the same month, and a payout in the same month.
        app(PaymentService::class)->refund($first->refresh(), new RefundData(
            amount: '40000.00',
            reason: 'Partial refund',
            type: ReversalType::PartialRefund,
            refundedOn: Carbon::parse('2026-03-15'),
        ));

        $actor = $this->createSuperAdmin();
        $payouts = app(PayoutService::class);

        $payout = $payouts->createFor($partner, new PayoutRequestData(
            requestedAmount: '5000.00', method: PayoutMethod::BankTransfer,
        ), $actor);

        $payouts->approve($payout, $actor);
        $payouts->markPaid($payout->refresh(), new MarkPaidData(
            transactionId: 'BANK-ST-1',
            paidOn: Carbon::parse('2026-03-20'),
        ), $actor);

        $statement = $this->statement($partner, '2026-03-01', '2026-03-31');

        // 10,000 + 5,000 earned, 4,000 reversed, 5,000 paid out.
        $this->assertSame('0.00', $statement->opening);
        $this->assertSame('15000.00', $statement->credits);
        $this->assertSame('4000.00', $statement->debits);
        $this->assertSame('5000.00', $statement->payouts);
        $this->assertSame('6000.00', $statement->closing);

        $this->assertTrue($statement->balances(), $statement->proof());
        $lines = $statement->lines;

        $this->assertSame('6000.00', $lines[count($lines) - 1]->balance,
            'The last running balance is the closing balance, by construction.');

        $this->assertWalletMatchesLedger($partner);
    }

    /** The closing balance of one period is the opening balance of the next — that is the whole point. */
    #[Test]
    public function one_period_closes_where_the_next_one_opens(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '500000.00');

        $this->receive($fee, '100000.00', ['on' => '2026-03-02']);
        $this->receive($fee, '60000.00', ['on' => '2026-04-11', 'key' => 'april']);

        $march = $this->statement($partner, '2026-03-01', '2026-03-31');
        $april = $this->statement($partner, '2026-04-01', '2026-04-30');

        $this->assertSame('10000.00', $march->closing);
        $this->assertSame($march->closing, $april->opening);
        $this->assertSame('16000.00', $april->closing);
    }

    /** §8.7's empty state: an empty statement still states the balances. */
    #[Test]
    public function a_range_with_nothing_in_it_still_reports_the_balances(): void
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '500000.00'), '100000.00', ['on' => '2026-03-02']);

        $statement = $this->statement($partner, '2026-06-01', '2026-06-30');

        $this->assertTrue($statement->isEmpty());
        $this->assertSame('10000.00', $statement->opening);
        $this->assertSame('10000.00', $statement->closing);
        $this->assertStringContainsString('both', $statement->emptyMessage());
        $this->assertTrue($statement->balances());
    }

    /**
     * The refusal, which is the part that matters.
     *
     * The closing balance is computed from the movements inside the window; the check re-derives the
     * same balance as an opening balance one day later, from a different query over different rows.
     * With the schema intact the two cannot disagree — which is the point, and also why this test has
     * to perturb one of them to see the guard work at all.
     */
    #[Test]
    public function it_throws_rather_than_render_a_statement_that_does_not_balance(): void
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '500000.00'), '100000.00', ['on' => '2026-03-02']);

        $broken = new class(app(DatabaseManager::class)) extends CollaboratorStatementService
        {
            protected function openingAt(int $collaboratorId, string $date): string
            {
                // One paisa out, on the re-derivation only. A seventh `LedgerEntryPurpose` counted by
                // the ledger sum and by neither of the statement's two lists would do exactly this.
                return Money::add(parent::openingAt($collaboratorId, $date), $date > '2026-03-31' ? '0.01' : '0.00');
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/does not balance/');

        $broken->build($partner, DateRange::custom('2026-03-01', '2026-03-31'), StatementFilters::none());
    }

    /**
     * And the reason the guard almost never fires: every purpose is counted by one side or the other.
     *
     * This is the test that catches a seventh purpose on the day it is added, rather than the day a
     * partner is handed a statement that refuses to render.
     */
    #[Test]
    public function every_ledger_purpose_is_counted_by_the_statement(): void
    {
        $counted = array_merge(
            CollaboratorStatementService::CREDIT_PURPOSES,
            CollaboratorStatementService::DEBIT_PURPOSES,
        );

        foreach (LedgerEntryPurpose::cases() as $purpose) {
            $this->assertContains($purpose, $counted, sprintf(
                '`%s` is summed into a balance by the ledger but by neither of the two purpose lists '
                .'this statement counts, so every statement covering one would refuse to render.',
                $purpose->value,
            ));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The five §56 lines and the filters
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_five_lines_subtotal_separately(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '500000.00');
        $payment = $this->receive($fee, '100000.00', ['on' => '2026-03-02'])->payment;

        app(PaymentService::class)->refund($payment->refresh(), new RefundData(
            amount: '30000.00',
            reason: 'Partial refund',
            type: ReversalType::PartialRefund,
            refundedOn: Carbon::parse('2026-03-15'),
        ));

        $statement = $this->statement($partner, '2026-03-01', '2026-03-31');

        $this->assertSame('10000.00', $statement->subtotal('student_commissions'));
        $this->assertSame('0.00', $statement->subtotal('project_commissions'));
        $this->assertSame('3000.00', $statement->subtotal('reversals'),
            'A reversal subtotals as a positive magnitude under a heading that already says it comes off.');
        $this->assertSame('0.00', $statement->subtotal('payouts'));

        $this->assertCount(1, $statement->group('student_commissions'));
        $this->assertCount(1, $statement->group('reversals'));
    }

    /** Filters narrow the rows; the balances are the partner's balances either way. */
    #[Test]
    public function a_filter_narrows_the_rows_and_never_the_balances(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '500000.00');
        $payment = $this->receive($fee, '100000.00', ['on' => '2026-03-02'])->payment;

        app(PaymentService::class)->refund($payment->refresh(), new RefundData(
            amount: '30000.00',
            reason: 'Partial refund',
            type: ReversalType::PartialRefund,
            refundedOn: Carbon::parse('2026-03-15'),
        ));

        $unfiltered = $this->statement($partner, '2026-03-01', '2026-03-31');
        $filtered = $this->statement($partner, '2026-03-01', '2026-03-31',
            new StatementFilters(purpose: LedgerEntryPurpose::Reversal));

        $this->assertCount(2, $unfiltered->lines);
        $this->assertCount(1, $filtered->lines);
        $this->assertTrue($filtered->isFiltered);

        $this->assertSame($unfiltered->opening, $filtered->opening);
        $this->assertSame($unfiltered->closing, $filtered->closing);
        $this->assertSame('7000.00', $filtered->closing);

        $this->assertSame('7000.00', $filtered->lines[0]->balance,
            'The visible row carries its true running balance, taken before the filter was applied.');
    }

    /** `collaborator.statement_show_technical_rows` hides what the partner did not cause. */
    #[Test]
    public function technical_rows_are_hidden_by_default_and_shown_on_request(): void
    {
        // A manual adjustment is dated today by construction — it is a decision somebody is making
        // now, not a movement of money that happened on some other date — so the window is today.
        $today = Carbon::now(Format::timezone())->toDateString();

        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '500000.00');
        $this->receive($fee, '100000.00', ['on' => $today]);

        $this->actingAs($this->createSuperAdmin());
        app(CommissionReversalService::class)->adjust($partner, '250.00', 'Goodwill after a billing error');

        $hidden = $this->statement($partner, $today, $today);
        $shown = $this->statement($partner, $today, $today, new StatementFilters(showTechnicalRows: true));

        $this->assertCount(1, $hidden->lines);
        $this->assertCount(2, $shown->lines);

        $this->assertSame('10250.00', $hidden->closing,
            'Hiding a row never changes a balance — the money is still the partner’s.');
        $this->assertSame($hidden->closing, $shown->closing);
    }

    /*
    |--------------------------------------------------------------------------
    | The published figure (F-4.8, ND-6)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_accrued_total_is_what_the_statement_balances_to(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '500000.00');
        $payment = $this->receive($fee, '100000.00', ['on' => '2026-03-02'])->payment;

        app(PaymentService::class)->refund($payment->refresh(), new RefundData(
            amount: '30000.00',
            reason: 'Partial refund',
            type: ReversalType::PartialRefund,
            refundedOn: Carbon::parse('2026-03-15'),
        ));

        $range = DateRange::custom('2026-03-01', '2026-03-31');
        $statement = app(CollaboratorStatementService::class)->build($partner, $range);

        $this->assertSame(
            Money::sub($statement->credits, $statement->debits),
            app(CollaboratorStatementService::class)->commissionAccruedTotal($partner, $range),
            'One figure, computed once: credits − debits is exactly what the published total means.',
        );
    }

    /** ND-6: the company figure equals the sum of the partner figures, because it is one query. */
    #[Test]
    public function the_company_wide_total_equals_the_sum_of_the_partners(): void
    {
        $range = DateRange::custom('2026-03-01', '2026-03-31');
        $service = app(CollaboratorStatementService::class);

        $partners = [];

        foreach (['10.0000', '7.5000', '12.2500'] as $index => $rate) {
            $partner = $this->partner($rate);
            $this->receive($this->charge($partner, '500000.00'), '100000.00',
                ['on' => '2026-03-0'.($index + 2), 'key' => 'p'.$index]);

            $partners[] = $partner;
        }

        $sum = Money::ZERO;

        foreach ($partners as $partner) {
            $sum = Money::add($sum, $service->commissionAccruedTotal($partner, $range));
        }

        $this->assertSame('29750.00', $sum);
        $this->assertSame($sum, $service->commissionAccruedTotal(null, $range));
    }

    #[Test]
    public function asking_for_every_commission_ever_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/never the question being asked/');

        app(CollaboratorStatementService::class)->commissionAccruedTotal(null, null);
    }

    /**
     * §6.5.5's reproducibility claim: a back-dated receipt lands in the period it belongs to.
     */
    #[Test]
    public function a_back_dated_receipt_is_reported_in_the_period_it_is_dated(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '500000.00');

        // Keyed in today, dated in February.
        $this->receive($fee, '100000.00', ['on' => '2026-02-20']);

        $february = $this->statement($partner, '2026-02-01', '2026-02-28');
        $march = $this->statement($partner, '2026-03-01', '2026-03-31');

        $this->assertSame('10000.00', $february->credits);
        $this->assertSame('0.00', $march->credits);
        $this->assertSame('10000.00', $march->opening,
            'March opens with what February closed at, so the money is counted once and in one place.');
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    private function statement(
        Collaborator $partner,
        string $from,
        string $to,
        ?StatementFilters $filters = null,
    ): StatementData {
        return app(CollaboratorStatementService::class)->build(
            $partner,
            DateRange::custom($from, $to),
            $filters,
        );
    }
}
