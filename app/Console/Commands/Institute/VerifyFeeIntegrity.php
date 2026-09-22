<?php

declare(strict_types=1);

namespace App\Console\Commands\Institute;

use App\Enums\InstallmentStatus;
use App\Enums\ReceivedPaymentStatus;
use App\Enums\StudentFeeStatus;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeeDiscount;
use App\Models\Institute\StudentFeeInstallment;
use App\Models\Institute\StudentFeePayment;
use App\Services\Institute\StudentFeeService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `fees:verify-plan-integrity` — the nightly proof (phase-18 §6.3, §10.4).
 *
 * Daily at 02:10. It re-derives PI-1 and all six cache columns of §2.3 from the rows underneath and
 * **reports**; it does not repair. That is the spine's reconciliation discipline, and the reason for it
 * is simple: a cache that the nightly job silently fixes is a bug that never gets found. The drift is
 * the evidence. `RecomputeStudentFeeCaches` is the repair, run deliberately by somebody who has looked.
 *
 * Scope is bounded the way §10.4 says: every charge with a live plan, plus anything with a receipt in
 * the last 60 days. A full-table pass every night would grow without limit and be switched off.
 *
 * The exit code is non-zero on drift, so a scheduler or CI treats it as the alarm it is.
 */
#[AsCommand(name: 'fees:verify-plan-integrity')]
final class VerifyFeeIntegrity extends Command
{
    protected $signature = 'fees:verify-plan-integrity
        {--days=60 : Also check charges with a receipt this recently}
        {--all : Check every charge, however old}
        {--fee= : Check one fee number}';

    protected $description = 'Re-derive PI-1 and the fee cache columns and report any drift (repairs nothing)';

    public function handle(StudentFeeService $fees): int
    {
        $charges = $this->scope();

        if ($charges->isEmpty()) {
            $this->info('Nothing in scope.');

            return self::SUCCESS;
        }

        $problems = [];

        foreach ($charges as $charge) {
            foreach ($this->check($charge, $fees) as $problem) {
                $problems[] = $problem;
            }
        }

        $this->line(sprintf('%d charge%s checked.', $charges->count(), $charges->count() === 1 ? '' : 's'));

        if ($problems === []) {
            $this->info('Every cache matches its rows and PI-1 holds on every plan.');

            return self::SUCCESS;
        }

        $this->error(sprintf('%d problem%s found:', count($problems), count($problems) === 1 ? '' : 's'));

        foreach (array_slice($problems, 0, 40) as $problem) {
            $this->line('  '.$problem);
        }

        if (count($problems) > 40) {
            $this->line(sprintf('  … and %d more', count($problems) - 40));
        }

        $this->newLine();
        $this->line('Nothing was repaired. Run `fees:recompute-caches --fee=…` once you have looked at why.');

        activity('student_fees')
            ->withProperties(['checked' => $charges->count(), 'problems' => array_slice($problems, 0, 100)])
            ->log('fees.integrity_drift');

        return self::FAILURE;
    }

    /**
     * @return Collection<int, StudentFee>
     */
    private function scope(): Collection
    {
        if ($this->option('fee') !== null) {
            return StudentFee::query()->where('fee_number', (string) $this->option('fee'))->get();
        }

        if ((bool) $this->option('all')) {
            return StudentFee::query()->get();
        }

        $since = Carbon::now()->subDays(max(1, (int) $this->option('days')));

        return StudentFee::query()
            ->where(function ($query) use ($since): void {
                $query->where('has_installment_plan', true)
                    ->orWhereIn('id', StudentFeePayment::query()
                        ->where('recorded_at', '>=', $since)
                        ->select('student_fee_id'));
            })
            ->get();
    }

    /**
     * @return list<string>
     */
    private function check(StudentFee $charge, StudentFeeService $fees): array
    {
        $problems = [];
        $label = (string) $charge->fee_number;

        $discounts = StudentFeeDiscount::query()->where('student_fee_id', $charge->getKey())->get();

        $bucket = static fn (bool $scholarship): string => Money::negate(Money::sum($discounts
            ->filter(static fn (StudentFeeDiscount $d): bool => $d->type->isScholarship() === $scholarship)
            ->map(static fn (StudentFeeDiscount $d): string => (string) $d->amount)
            ->all()));

        $live = StudentFeePayment::query()
            ->where('student_fee_id', $charge->getKey())
            ->whereNot('status', ReceivedPaymentStatus::Voided->value)
            ->get(['amount', 'refunded_amount']);

        $expected = [
            'discount_amount' => $bucket(false),
            'scholarship_amount' => $bucket(true),
            'paid_amount' => Money::sum($live->map(static fn ($p): string => (string) $p->amount)->all()),
            'refunded_amount' => Money::sum($live->map(static fn ($p): string => (string) $p->refunded_amount)->all()),
        ];

        $expected['net_amount'] = Money::sub(
            Money::sub((string) $charge->gross_amount, $expected['discount_amount']),
            $expected['scholarship_amount'],
        );

        $expected['balance_amount'] = Money::sub(
            $expected['net_amount'],
            Money::sub($expected['paid_amount'], $expected['refunded_amount']),
        );

        foreach ($expected as $column => $value) {
            if (Money::compare((string) $charge->{$column}, $value) !== 0) {
                $problems[] = sprintf('%s: %s is %s, rows say %s', $label, $column, (string) $charge->{$column}, $value);
            }
        }

        // The status is derived from the caches, so it is checked against what the **rows** imply
        // rather than against what the cached columns say — otherwise a drifted cache and a drifted
        // status would agree with each other and both look fine.
        $shadow = (clone $charge)->forceFill($expected);
        $derived = $fees->deriveStatus($shadow);

        if ($charge->status !== $derived && $charge->status !== StudentFeeStatus::Cancelled) {
            $problems[] = sprintf('%s: status is %s, rows imply %s', $label, $charge->status->value, $derived->value);
        }

        $lines = StudentFeeInstallment::query()
            ->where('student_fee_id', $charge->getKey())
            ->whereNot('status', InstallmentStatus::Cancelled->value)
            ->get();

        // [D18-10]: PI-1 is a statement about a schedule that can still be paid off, and an overpaid
        // charge has none. See StudentFeeService::assertPlanIntegrity() for the whole argument.
        if ($lines->isEmpty() || Money::isNegative((string) $charge->balance_amount)) {
            return $problems;
        }

        $scheduled = Money::sub(
            Money::sum($lines->map(static fn ($l): string => (string) $l->amount)->all()),
            Money::sum($lines->map(static fn ($l): string => (string) $l->waived_amount)->all()),
        );

        if (Money::compare($scheduled, $expected['net_amount']) !== 0) {
            $problems[] = sprintf(
                '%s: PI-1 — live lines less waivers come to %s, net fee is %s',
                $label,
                $scheduled,
                $expected['net_amount'],
            );
        }

        if ((int) $charge->installment_count !== $lines->count()) {
            $problems[] = sprintf('%s: installment_count is %d, live lines are %d', $label, (int) $charge->installment_count, $lines->count());
        }

        return $problems;
    }
}
