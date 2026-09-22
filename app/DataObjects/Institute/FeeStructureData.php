<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use App\Enums\StudentFeeType;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * What the fee-structure wizard agreed before anything is written (phase-18 §6.1 `generateStructure()`).
 *
 * **The head order is fixed and lives here** (§6.1.1): `admission_fee`, `registration_fee`,
 * `course_fee`, then anything extra in the order the caller listed it. Two runs of the generator have
 * to produce the same `fee_number` sequence and the same slip, or a re-print after a re-generation is a
 * different document describing the same money.
 *
 * `$replaceUnpaid` is the only way to regenerate over an existing head, and even then the generator
 * refuses any head that already holds a receipt — a charge with money against it is not a draft.
 */
final readonly class FeeStructureData
{
    /**
     * @param  array<string, string>  $amounts  head value => gross amount; heads with '0.00' are dropped
     * @param  array<string, CarbonInterface>  $dueDates  head value => due date; missing falls back to the setting
     */
    public function __construct(
        public array $amounts,
        public array $dueDates = [],
        public bool $copyAdmissionDiscount = true,
        public bool $replaceUnpaid = false,
        public ?string $idempotencyKey = null,
        public ?int $approvedBy = null,
    ) {
        if ($this->amounts === []) {
            throw new InvalidArgumentException('A fee structure needs at least one head with an amount.');
        }

        foreach ($this->amounts as $head => $amount) {
            if (StudentFeeType::tryFrom((string) $head) === null) {
                throw new InvalidArgumentException(sprintf('[%s] is not a fee head.', (string) $head));
            }

            if (Money::compare(Money::of($amount), Money::ZERO) === -1) {
                throw new InvalidArgumentException(sprintf('The %s amount is negative.', (string) $head));
            }
        }
    }

    /**
     * The heads to raise, in §6.1.1's fixed order, with zero-amount heads dropped.
     *
     * A zero head is not an error and not a zero charge — it is a head the institute does not charge
     * this student, and the honest record of that is its absence.
     *
     * @return list<array{type: StudentFeeType, amount: string}>
     */
    public function heads(): array
    {
        $order = [
            StudentFeeType::AdmissionFee->value,
            StudentFeeType::RegistrationFee->value,
            StudentFeeType::CourseFee->value,
        ];

        $extras = array_values(array_diff(array_keys($this->amounts), $order));
        $resolved = [];

        foreach ([...$order, ...$extras] as $head) {
            if (! array_key_exists($head, $this->amounts)) {
                continue;
            }

            $amount = Money::of($this->amounts[$head]);

            if (Money::compare($amount, Money::ZERO) !== 1) {
                continue;
            }

            $resolved[] = ['type' => StudentFeeType::from($head), 'amount' => $amount];
        }

        return $resolved;
    }

    public function dueDateFor(StudentFeeType $head): ?CarbonInterface
    {
        return $this->dueDates[$head->value] ?? null;
    }

    public function key(): string
    {
        return $this->idempotencyKey ?? (string) Str::ulid();
    }

    /** What the wizard's proof line compares against `student_admissions.net_payable`. */
    public function grossTotal(): string
    {
        return Money::sum(array_map(
            static fn (array $head): string => $head['amount'],
            $this->heads(),
        ));
    }
}
