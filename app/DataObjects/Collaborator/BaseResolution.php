<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Enums\CommissionBase;
use App\Enums\EntitlementDocumentType;
use App\Support\Money;

/**
 * Which document a commission is promised against, and the two figures that bound it
 * (spine §6.1.4, phase-10-12 §6.3).
 *
 * **`collectibleAmount` is the release denominator, and it is never a cached `paid_amount`.** It is what
 * the business can still collect on this document — the net of a charge, the net value of a project, the
 * amount of a milestone. Every base mode is therefore "money received, bounded by a collectible figure",
 * which is what makes it impossible for commission to accrue on money nobody has paid (INV-19).
 *
 * `documentBaseAmount` is what the promise is a percentage *of*. For the `paid` base the two are the
 * same figure and the base amount is used only as the overpayment cap, which is deliberate: one shape
 * for every mode means the arithmetic below has no mode-specific branch to get wrong.
 */
final readonly class BaseResolution
{
    public function __construct(
        public EntitlementDocumentType $documentType,
        public int $documentId,
        public string $documentBaseAmount,
        public string $collectibleAmount,
        public CommissionBase $base,
    ) {}

    /**
     * Is there anything left to collect on this document for a promise that has already taken
     * `$collected` of it? Never negative: a document collected past its own net has nothing left, not a
     * negative amount left.
     */
    public function remainingCollectible(string $collected): string
    {
        return Money::max(Money::ZERO, Money::sub($this->collectibleAmount, $collected));
    }

    /**
     * The column on `collaborator_commission_entitlements` that carries this document's id.
     */
    public function documentColumn(): string
    {
        return $this->documentType->column();
    }

    /**
     * The trace figures, for `rule_snapshot` (spine §6.1.9).
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'document_type' => $this->documentType->value,
            'document_id' => $this->documentId,
            'document_base_amount' => $this->documentBaseAmount,
            'collectible_amount' => $this->collectibleAmount,
            'commission_base' => $this->base->value,
        ];
    }
}
