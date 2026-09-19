<?php

declare(strict_types=1);

namespace App\Support\Projects;

use App\Contracts\Projects\ProjectCreator;
use App\DataObjects\Crm\ProjectDraftData;
use App\Models\Crm\LeadConversion;
use LogicException;

/**
 * The project hand-off before Phase 6 exists (phase-05 [D-P5-1], E13, F9).
 *
 * Unavailable: the conversion wizard renders no project step and the hand-off route answers 404. Calling
 * `createFromLead()` anyway is a programming error — it throws rather than inventing a project id.
 */
final class NullProjectCreator implements ProjectCreator
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function createFromLead(LeadConversion $conversion, ProjectDraftData $draft): int
    {
        throw new LogicException('Projects are not available yet: no ProjectCreator is bound (Phase 6).');
    }
}
