<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

/**
 * The document's figures after they moved (spine §6.6 rows 6-8, phase-10-12 §6.5).
 *
 * A fee discounted after a receipt exists, or a project revalued mid-engagement, changes what was
 * promised — but only for a base that promised on the *document*. The promise is re-floored on a
 * successor row rather than edited, so the original still explains every slice released under it.
 *
 * Nothing about the rule is carried here on purpose: a discount changes the fee, never the rate
 * (§6.5). Re-resolving the rule at supersede time would let today's rate silently re-price a promise
 * made under last year's.
 */
final readonly class SupersedeContext
{
    public function __construct(
        public string $documentBaseAmount,
        public string $collectibleAmount,
    ) {}

    public static function from(BaseResolution $document): self
    {
        return new self($document->documentBaseAmount, $document->collectibleAmount);
    }
}
