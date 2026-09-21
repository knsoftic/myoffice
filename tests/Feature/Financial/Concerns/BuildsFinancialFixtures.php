<?php

declare(strict_types=1);

namespace Tests\Feature\Financial\Concerns;

use App\DataObjects\Collaborator\RuleData;
use App\DataObjects\Finance\PaymentResult;
use App\DataObjects\Finance\RecordPaymentData;
use App\Enums\CollaborationType;
use App\Enums\CollaboratorStatus;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionScope;
use App\Enums\PaymentMethod;
use App\Enums\ReferralSource;
use App\Enums\ReferralSubject;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentFeeType;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Collaborator\CollaboratorWallet;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeeInstallment;
use App\Models\Institute\StudentFeePayment;
use App\Services\Collaborator\CommissionReconciliationService;
use App\Services\Collaborator\CommissionRuleService;
use App\Services\Collaborator\ReferralService;
use App\Services\Finance\PaymentService;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Fixtures and the shared assertion for the financial acceptance suite (phase-10-12 §11).
 *
 * **`assertWalletMatchesLedger()` is the helper every money test ends with**, and it runs the nightly
 * reconciler's own eight checks (spine §6.5.3) against the same derivation every screen reads
 * (INV-26). That was the point of one shared helper: when `CommissionReconciliationService` landed,
 * this body became a call to it and every test in the suite gained the other seven checks without one
 * of them being edited.
 */
trait BuildsFinancialFixtures
{
    private int $fixtureSequence = 0;

    /**
     * An active partner with a student commission rule in force.
     */
    protected function partner(
        string $rate = '10.0000',
        ?string $fixedAmount = null,
        array $rule = [],
    ): Collaborator {
        $this->fixtureSequence++;
        $suffix = str_pad((string) $this->fixtureSequence, 4, '0', STR_PAD_LEFT);

        $collaborator = new Collaborator;
        $collaborator->forceFill([
            'collaborator_code' => 'COL-T'.$suffix,
            'referral_code' => 'acc'.$suffix,
            'name' => 'Acceptance Partner '.$suffix,
            'collaboration_type' => CollaborationType::ReferralPartner->value,
            'status' => CollaboratorStatus::Active->value,
        ])->save();

        $collaborator->refresh();

        app(CommissionRuleService::class)->createVersion(
            $collaborator,
            $rule['scope'] ?? CommissionScope::Student,
            new RuleData(
                scope: $rule['scope'] ?? CommissionScope::Student,
                calculationType: $fixedAmount === null
                    ? CommissionCalculationType::Percentage
                    : CommissionCalculationType::Fixed,
                effectiveFrom: Carbon::parse($rule['from'] ?? '2020-01-01'),
                rate: $fixedAmount === null ? $rate : null,
                fixedAmount: $fixedAmount,
                release: $rule['release'] ?? null,
                baseOverride: $rule['base'] ?? null,
                isEnabled: $rule['enabled'] ?? true,
                minPaymentAmount: $rule['min'] ?? null,
                maxCommissionAmount: $rule['max'] ?? null,
                appliesToFeeTypes: $rule['fee_types'] ?? null,
            ),
            'Acceptance fixture',
        );

        return $collaborator;
    }

    /**
     * A fee charge, optionally credited to a partner from a given date.
     */
    protected function charge(
        ?Collaborator $collaborator = null,
        string $gross = '30000.00',
        array $attributes = [],
    ): StudentFee {
        $this->fixtureSequence++;
        $studentId = $attributes['student_id'] ?? (900000 + $this->fixtureSequence);

        if ($collaborator !== null) {
            app(ReferralService::class)->attachSubject(
                ReferralSubject::Student,
                (int) $studentId,
                $collaborator,
                ReferralSource::ManualSelection,
                null,
                Carbon::parse($attributes['referred_on'] ?? '2020-01-01'),
            );
        }

        $discount = $attributes['discount_amount'] ?? '0.00';
        $net = Money::sub(Money::of($gross), Money::of($discount));

        $fee = new StudentFee;
        $fee->forceFill(array_merge([
            'fee_number' => 'FS-T'.str_pad((string) $this->fixtureSequence, 5, '0', STR_PAD_LEFT),
            'student_id' => $studentId,
            'fee_type' => StudentFeeType::CourseFee->value,
            'gross_amount' => Money::of($gross),
            'discount_amount' => Money::of($discount),
            'net_amount' => $net,
            'balance_amount' => $net,
            'status' => StudentFeeStatus::Pending->value,
        ], array_diff_key($attributes, array_flip(['student_id', 'referred_on', 'discount_amount']))));
        $fee->save();

        return $fee->refresh();
    }

    /**
     * An installment line on a charge.
     */
    protected function installment(StudentFee $fee, int $number, string $amount, string $due = '2026-03-01'): StudentFeeInstallment
    {
        $line = new StudentFeeInstallment;
        $line->forceFill([
            'student_fee_id' => $fee->getKey(),
            'installment_no' => $number,
            'amount' => Money::of($amount),
            'due_date' => $due,
        ])->save();

        return $line->refresh();
    }

    /**
     * Take money — through the service, the way every real caller does.
     */
    protected function receive(StudentFee $fee, string $amount, array $options = []): PaymentResult
    {
        $this->fixtureSequence++;

        return app(PaymentService::class)->recordStudentFeePayment($fee, new RecordPaymentData(
            amount: $amount,
            method: $options['method'] ?? PaymentMethod::Cash,
            paidOn: Carbon::parse($options['on'] ?? '2026-03-10'),
            installmentId: $options['installment'] ?? null,
            idempotencyKey: $options['key'] ?? 'acc-'.$this->fixtureSequence,
            confirmDuplicate: $options['confirm_duplicate'] ?? true,
        ));
    }

    protected function entryFor(StudentFeePayment $payment): ?CollaboratorCommissionLedgerEntry
    {
        return CollaboratorCommissionLedgerEntry::query()
            ->where('student_fee_payment_id', $payment->getKey())
            ->earnings()
            ->first();
    }

    protected function walletOf(Collaborator $collaborator): ?CollaboratorWallet
    {
        return CollaboratorWallet::query()->where('collaborator_id', $collaborator->getKey())->first();
    }

    /**
     * Write a setting the way the system context does — every commission key is registry-declared and
     * therefore refused by the ordinary low-level setter.
     */
    protected function setting(string $key, mixed $value): void
    {
        settings_repo()->asSystem(fn ($settings) => $settings->set($key, $value));
    }

    /*
    |--------------------------------------------------------------------------
    | The assertion every money test ends with (phase-10-12 §11)
    |--------------------------------------------------------------------------
    */

    /**
     * All eight checks of spine §6.5.3, against this partner.
     *
     * It runs `CommissionReconciliationService::assertConsistent()` — literally what the nightly job
     * runs, reading the one derivation every screen reads. A test that computed the expected balance
     * its own way would be checking the cache against a second opinion rather than against the ledger,
     * which is exactly the situation INV-26 exists to prevent.
     *
     * `check()` and not `run()`: the assertion is a pure reader, so the suite does not fill
     * `collaborator_wallet_reconciliations` with a row per assertion per test.
     */
    protected function assertWalletMatchesLedger(Collaborator $collaborator): void
    {
        app(CommissionReconciliationService::class)->assertConsistent($collaborator);

        $this->assertSame(
            0,
            CollaboratorCommissionLedgerEntry::query()->where('amount', '<=', 0)->count(),
            'INV-2: a zero-amount ledger row must never exist — a guard that produced nothing writes a '
            .'skip reason on the payment instead.'
        );

        // `assertConsistent()` throws rather than asserting, so without this the helper would register
        // no assertion at all and PHPUnit would call a passing test risky.
        $this->addToAssertionCount(1);
    }
}
