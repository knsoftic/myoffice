<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\Models\Crm\Lead;

/**
 * What the conversion wizard shows before anything is written (phase-05 §6.4 `preview()`).
 *
 *   · `clientFields` — the proposed client form, pre-filled from the lead;
 *   · `fieldMap` — lead field => client field, stored on the conversion when it is confirmed;
 *   · `duplicates` — matches against existing clients and contacts, for a human to pick from;
 *   · `attribution` — the referral that will be carried forward (null when there is none);
 *   · `projectHandOffAvailable` — whether `ProjectCreator` is bound (the project step renders only then).
 */
final readonly class ConversionPreview
{
    /**
     * @param  array<string, mixed>  $clientFields
     * @param  array<string, string>  $fieldMap
     * @param  array{code: string|null, recorded: bool, recorded_at: string|null, collaborator_id: int|null, collaborator_name: string|null}|null  $attribution
     */
    public function __construct(
        public Lead $lead,
        public bool $isWon,
        public bool $alreadyConverted,
        public array $clientFields,
        public array $fieldMap,
        public DuplicateReport $duplicates,
        public ?array $attribution,
        public bool $projectHandOffAvailable,
    ) {}

    /**
     * The lead is not `won` yet: the wizard must offer "mark as won and convert".
     */
    public function requiresPromotion(): bool
    {
        return ! $this->isWon;
    }
}
