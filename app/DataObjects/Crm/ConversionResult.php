<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\Models\Crm\Client;
use App\Models\Crm\LeadConversion;

/**
 * The outcome of `LeadConversionService::convert()` (phase-05 §6.4).
 *
 * `created` is false when the call hit the live conversion `uq_lc_lead_active` already guarantees — a
 * double-submitted wizard — and the existing conversion is returned unchanged (test 45).
 */
final readonly class ConversionResult
{
    public function __construct(
        public LeadConversion $conversion,
        public ?Client $client,
        public bool $created,
        public bool $createdClient = false,
        public ?int $projectId = null,
    ) {}
}
