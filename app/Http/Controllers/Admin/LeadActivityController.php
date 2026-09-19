<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Crm\Concerns\RespondsForCrm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreLeadActivityRequest;
use App\Http\Requests\Crm\UpdateLeadActivityRequest;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadActivity;
use App\Services\Crm\LeadService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The lead timeline's manual entries — `admin.leads.activities.*` (phase-05 §2.2, §6.1 `recordActivity()` /
 * `updateActivity()` / `deleteActivity()`, §8.3, tests 32-33), `module:leads`.
 *
 * `{lead}` binds through the lead visibility scope, so a lead the user cannot see is a 404 before anything runs;
 * `{activity}` must belong to that lead or the action is a 404 too (never a 403 that confirms the id exists). A system
 * row is never editable or deletable: `LeadActivityPolicy` refuses it, and `LeadService` refuses it again.
 */
final class LeadActivityController extends Controller
{
    use RespondsForCrm;

    public function __construct(
        private readonly LeadService $leads,
    ) {}

    public function store(StoreLeadActivityRequest $request, Lead $lead): Response
    {
        $this->authorize('update', $lead);
        $this->authorize('logActivity', $lead);

        return $this->attempt($request, function () use ($request, $lead): Response {
            $activity = $this->leads->recordActivity($lead, $request->toData());

            return $this->done(
                $request,
                'The activity was logged.',
                redirect()->route('admin.leads.show', ['lead' => $lead, 'tab' => 'timeline']),
                ['id' => (int) $activity->getKey(), 'lead_id' => (int) $lead->getKey()],
            );
        });
    }

    public function update(UpdateLeadActivityRequest $request, Lead $lead, LeadActivity $activity): Response
    {
        $this->assertBelongs($lead, $activity);
        $this->authorize('update', $activity);

        return $this->attempt($request, function () use ($request, $lead, $activity): Response {
            $this->leads->updateActivity($activity, $request->toData());

            return $this->done(
                $request,
                'The activity was updated.',
                redirect()->route('admin.leads.show', ['lead' => $lead, 'tab' => 'timeline']),
                ['id' => (int) $activity->getKey()],
            );
        });
    }

    public function destroy(Request $request, Lead $lead, LeadActivity $activity): Response
    {
        $this->assertBelongs($lead, $activity);
        $this->authorize('delete', $activity);

        return $this->attempt($request, function () use ($request, $lead, $activity): Response {
            $this->leads->deleteActivity($activity);

            return $this->done(
                $request,
                'The activity was removed from the timeline.',
                redirect()->route('admin.leads.show', ['lead' => $lead, 'tab' => 'timeline']),
                ['id' => (int) $activity->getKey()],
            );
        });
    }

    private function assertBelongs(Lead $lead, LeadActivity $activity): void
    {
        $this->abortUnlessVisible((int) $activity->lead_id === (int) $lead->getKey());
    }
}
