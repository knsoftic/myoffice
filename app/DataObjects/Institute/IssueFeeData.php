<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use App\Enums\StudentFeeType;
use App\Support\Money;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * One charge to raise (phase-18 §6.1 `issue()`).
 *
 * **`grossAmount` may not be zero, and the refusal lives here rather than in the service.** §6.1 says a
 * zero charge is refused — the honest way to say "this student owes nothing for this head" is to raise
 * the charge and record a discount, which leaves a row saying who decided that and why. A zero charge
 * leaves nothing at all and later reads as an administrative slip.
 *
 * `generationKey` is composed by the two generators (`structure:{admission}:{head}`,
 * `monthly:{admission}:{YYYY-MM}`) and is **never** accepted from a form — `uq_sf_generation` is the
 * whole duplicate guard for scheduled charges, and a caller who could set it could defeat it. It is not
 * in `StudentFee::$fillable` for the same reason.
 */
final readonly class IssueFeeData
{
    public function __construct(
        public int $studentId,
        public StudentFeeType $feeType,
        public string $grossAmount,
        public ?int $studentAdmissionId = null,
        public ?int $courseId = null,
        public ?int $batchId = null,
        public ?int $branchId = null,
        public ?string $title = null,
        public ?CarbonInterface $dueDate = null,
        public ?string $notes = null,
        /** Composed by a generator; never read from a request. */
        public ?string $generationKey = null,
    ) {
        if (Money::compare(Money::of($this->grossAmount), Money::ZERO) !== 1) {
            throw new InvalidArgumentException(
                'A charge of zero or less is not a charge. Raise the head and record a discount, so the '
                .'reason the student owes nothing is on the record.'
            );
        }
    }

    public function amount(): string
    {
        return Money::of($this->grossAmount);
    }

    /** The title a slip prints when the caller gave none — the head's own label. */
    public function resolvedTitle(): string
    {
        return $this->title !== null && trim($this->title) !== ''
            ? trim($this->title)
            : $this->feeType->label();
    }
}
