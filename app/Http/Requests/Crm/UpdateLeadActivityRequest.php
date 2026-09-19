<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Crm\LeadActivity;

/**
 * Edit a manual timeline entry — `admin.leads.activities.update`, `can:update,activity` (phase-05 §6.1
 * `updateActivity()`, test 33).
 *
 * The same fields as `StoreLeadActivityRequest`. The policy refuses a system row outright and applies the
 * `crm.activity_edit_window_minutes` window (an author may edit their own note inside it; `leads.edit` may edit
 * another person's); the service re-checks both.
 */
final class UpdateLeadActivityRequest extends StoreLeadActivityRequest
{
    public function authorize(): bool
    {
        $activity = $this->boundModel('activity', LeadActivity::class);

        return $activity instanceof LeadActivity && $this->actorCan('update', $activity);
    }
}
