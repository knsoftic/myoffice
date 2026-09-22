<?php

declare(strict_types=1);

namespace App\Jobs\Institute;

use App\Models\Institute\Batch;
use App\Models\Institute\StudentAdmission;
use App\Services\Institute\Exceptions\FeeRuleException;
use App\Services\Institute\StudentFeeService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Raise one month's fees for one batch (phase-18 §10.2).
 *
 * **A month already charged returns null and is counted, never thrown.** This runs over every active
 * admission of a batch, night after night, so "already generated" is the overwhelmingly common answer —
 * an exception there would make the normal path the error path and bury a real failure in the noise.
 * The guard underneath is `uq_sf_generation` on `monthly:{admission}:{YYYY-MM}`: an INSERT that loses
 * the race is the check, so two workers and a retried job all produce one charge (§6.1.3, F-3.15).
 *
 * The amount comes from the admission's own `monthly_fee`, falling back to the course's. If neither
 * carries one the admission is skipped and said so — guessing a monthly fee from a course fee and a
 * duration would be inventing a number somebody will be asked to pay.
 */
final class GenerateMonthlyFeeCharges implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 86400;

    public function __construct(
        public readonly int $batchId,
        public readonly string $month,
    ) {
        $this->afterCommit();
        $this->onQueue('financial');
    }

    public function uniqueId(): string
    {
        return sprintf('monthly-fees:%d:%s', $this->batchId, $this->month);
    }

    public function handle(StudentFeeService $fees): void
    {
        $batch = Batch::query()->whereKey($this->batchId)->first();

        if ($batch === null) {
            return;
        }

        $period = CarbonImmutable::parse($this->month.'-01');

        $admissions = StudentAdmission::query()
            ->where('batch_id', $batch->getKey())
            ->whereNull('cancelled_at')
            ->whereNull('withdrawn_at')
            ->get();

        $raised = 0;
        $skipped = 0;
        $noAmount = 0;

        foreach ($admissions as $admission) {
            $amount = $this->amountFor($admission, $batch);

            if ($amount === null) {
                $noAmount++;

                continue;
            }

            try {
                $charge = $fees->generateMonthlyCharge($admission, $period, $amount);
            } catch (FeeRuleException) {
                // A month before the admission started, or more than a year out. Both are refusals the
                // service makes deliberately and neither is worth failing the batch's whole run for.
                $skipped++;

                continue;
            } catch (Throwable $e) {
                // Anything else is a real problem and should reach `failed_jobs` with its context.
                report($e);

                throw $e;
            }

            $charge === null ? $skipped++ : $raised++;
        }

        activity('student_fees')
            ->withProperties([
                'batch_id' => $batch->getKey(),
                'batch_code' => $batch->code,
                'month' => $this->month,
                'raised' => $raised,
                'already_charged' => $skipped,
                'no_monthly_fee' => $noAmount,
            ])
            ->log('fees.monthly_generated');
    }

    /**
     * The admission's own figure wins over the course's: it is what this student agreed, and a course
     * whose price changed later must not silently re-price an existing admission.
     */
    private function amountFor(StudentAdmission $admission, Batch $batch): ?string
    {
        foreach ([$admission->monthly_fee, $batch->course?->monthly_fee] as $candidate) {
            if ($candidate !== null && Money::compare(Money::of((string) $candidate), Money::ZERO) === 1) {
                return Money::of((string) $candidate);
            }
        }

        return null;
    }
}
