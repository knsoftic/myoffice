<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

/**
 * Who is printing a fee slip, and therefore what may appear on it (phase-18 §6.7.1, §41 vs §112).
 *
 * **`studentCopy()` cannot be talked out of hiding the commission block.** The slip is the one document
 * where a partner's earnings meet a student-facing page, and §112 is unambiguous that the student must
 * never see a rate or an amount. The student-panel route constructs this object itself and no query
 * parameter reaches it, so "show the commission" is not a request anybody can make from that side —
 * the three gates (`collaborator_commissions.view_financial`, `institute.fee_slip_show_commission`, and
 * not being the student copy) are all `AND`ed in `FeeSlipBuilder`.
 *
 * The student's copy still names the **collaborator**, per §41 and Q9: the student already knows who
 * referred them. It is the money that stays hidden, not the relationship.
 */
final readonly class FeeSlipOptions
{
    private function __construct(
        public bool $isStudentCopy,
        public bool $viewerMaySeeCommission,
        public bool $isReprint,
        public int $printCount,
    ) {}

    /**
     * The staff print. The commission block still depends on the permission and the setting.
     */
    public static function forStaff(
        bool $viewerMaySeeCommission = false,
        bool $isReprint = false,
        int $printCount = 1,
    ): self {
        return new self(
            isStudentCopy: false,
            viewerMaySeeCommission: $viewerMaySeeCommission,
            isReprint: $isReprint,
            printCount: $printCount,
        );
    }

    /**
     * The student's own copy: no commission figure can reach it, whatever else is true.
     */
    public static function studentCopy(bool $isReprint = false, int $printCount = 1): self
    {
        return new self(
            isStudentCopy: true,
            viewerMaySeeCommission: false,
            isReprint: $isReprint,
            printCount: $printCount,
        );
    }

    /**
     * The third gate of §6.7.1. `FeeSlipBuilder` also checks the setting; this is the part that belongs
     * to the request rather than to configuration.
     */
    public function mayRenderCommission(): bool
    {
        return ! $this->isStudentCopy && $this->viewerMaySeeCommission;
    }

    public function watermark(): ?string
    {
        return $this->isReprint ? 'REPRINT' : null;
    }
}
