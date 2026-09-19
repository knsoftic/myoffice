<?php

declare(strict_types=1);

namespace App\Contracts\Projects;

use App\DataObjects\Crm\ProjectDraftData;
use App\Models\Crm\LeadConversion;

/**
 * The project hand-off of a lead conversion (phase-05 §6.4 step 8, [D-P5-1], D28).
 *
 * Bound to `App\Support\Projects\NullProjectCreator` until Phase 6 rebinds it. While `isAvailable()` is false the
 * conversion wizard renders no project control and the hand-off route answers 404 — never a fake project id.
 *
 * `createFromLead()` runs inside the conversion's transaction and must honour `collaborator_id` and
 * `referral_code` on the draft: the project is the table that will earn commission (§45), so this is the point
 * at which attribution has to reach it. It returns the new `projects.id`.
 */
interface ProjectCreator
{
    public function isAvailable(): bool;

    public function createFromLead(LeadConversion $conversion, ProjectDraftData $draft): int;
}
