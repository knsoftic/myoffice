<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use App\Enums\FeeDiscountType;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A reduction (or a correction) to one charge (phase-18 §6.5).
 *
 * **The caller gives a magnitude; the service decides the sign.** `student_fee_discounts.amount` is a
 * signed delta to net — reductions negative, a `correction` positive, a `reversal` the opposite sign of
 * the row it undoes — so the net fee is a `SUM` rather than a case analysis. Asking the caller for the
 * sign as well would mean every screen had to know the convention, and one screen eventually would not.
 *
 * **Amount or percentage, never both.** A percentage discount stores the percentage *and* the computed
 * amount (§6.5), so the arithmetic is never re-done against a gross that has since changed. Accepting
 * both from the caller would let the two disagree at the door.
 *
 * `idempotencyKey` is one ULID per opened modal, and `uq_sfd_idem` turns a double-submitted form into a
 * no-op instead of two discounts.
 */
final readonly class DiscountData
{
    public function __construct(
        public FeeDiscountType $type,
        public string $reason,
        /** The magnitude, unsigned. Null when `percentage` is given. */
        public ?string $amount = null,
        /** 0 < percentage <= 100, `decimal(8,4)`. Null when `amount` is given. */
        public ?string $percentage = null,
        public ?int $approvedBy = null,
        public ?CarbonInterface $effectiveOn = null,
        public ?string $idempotencyKey = null,
        /** Set only by `reverseDiscount()`. */
        public ?int $reversesDiscountId = null,
    ) {
        if (trim($this->reason) === '') {
            throw new InvalidArgumentException(
                'A discount changes what a student owes, so the reason is mandatory — it is what the '
                .'figure is explained by when somebody asks about it months later.'
            );
        }

        if ($this->amount === null && $this->percentage === null) {
            throw new InvalidArgumentException('A discount needs either an amount or a percentage.');
        }

        if ($this->amount !== null && $this->percentage !== null) {
            throw new InvalidArgumentException(
                'Give an amount or a percentage, not both: two figures that can disagree are two figures '
                .'that eventually will.'
            );
        }

        if ($this->amount !== null && Money::compare(Money::of($this->amount), Money::ZERO) !== 1) {
            throw new InvalidArgumentException(
                'A discount of zero is not a discount, and `chk_sfd_nonzero` refuses it. Pass the '
                .'magnitude; the service applies the sign.'
            );
        }

        if ($this->percentage !== null && (bccomp($this->percentage, '0', 4) !== 1 || bccomp($this->percentage, '100', 4) === 1)) {
            throw new InvalidArgumentException(
                'A percentage discount is above zero and at most 100 — `chk_sfd_pct` refuses anything else.'
            );
        }
    }

    public function key(): string
    {
        return $this->idempotencyKey ?? (string) Str::ulid();
    }

    /**
     * The magnitude this row reduces (or, for a correction, restores) — resolved against the charge's
     * gross when the caller gave a percentage.
     */
    public function magnitudeAgainst(string $gross): string
    {
        return $this->amount !== null
            ? Money::of($this->amount)
            : Money::percentage($gross, $this->percentage ?? '0');
    }

    /**
     * The signed delta the row stores. `correction` is the only type that raises net back up, and it is
     * capped elsewhere so it can only ever undo an over-discount (§6.5).
     */
    public function signedAgainst(string $gross): string
    {
        $magnitude = $this->magnitudeAgainst($gross);

        return $this->type === FeeDiscountType::Correction
            ? $magnitude
            : Money::negate($magnitude);
    }
}
