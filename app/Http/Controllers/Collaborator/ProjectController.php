<?php

declare(strict_types=1);

namespace App\Http\Controllers\Collaborator;

use App\Enums\LedgerEntryPurpose;
use App\Enums\ReferralSubject;
use App\Http\Controllers\Collaborator\Concerns\ResolvesOwnCollaborator;
use App\Http\Controllers\Controller;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Collaborator\CollaboratorReferral;
use App\Models\Project\Project;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Projects this partner referred — `collaborator.projects.index` (§58, phase-10-12 §7.5).
 *
 * **The list is built from the referral table, not from `projects.collaborator_id`.** That column is a
 * display snapshot; the referral is the attribution of record, and the two can legitimately differ
 * after a change of attribution (§37) — at which point showing the snapshot would tell the wrong
 * partner a project was theirs.
 *
 * Each money column is its own gate. A partner who may see that they referred a project does not
 * thereby get to see what the client is paying for it: `project_client`, `project_value` and
 * `project_commission` are separate abilities, and a column nobody holds is not queried at all.
 */
final class ProjectController extends Controller
{
    use ResolvesOwnCollaborator;

    public function index(Request $request): View
    {
        $collaborator = $this->ownCollaborator($request);
        $user = $request->user();

        $showValue = (bool) $user?->can('collaborator_portal.project_value');
        $showClient = (bool) $user?->can('collaborator_portal.project_client');
        $showCommission = (bool) $user?->can('collaborator_portal.project_commission');

        $projectIds = CollaboratorReferral::query()
            ->where('collaborator_id', $collaborator->getKey())
            ->where('subject_type', ReferralSubject::Project->value)
            ->whereNotNull('project_id')
            ->pluck('project_id')
            ->unique()
            ->values();

        $projects = Project::query()
            ->whereIn('id', $projectIds)
            ->when($showClient, fn ($q) => $q->with('client:id,company_name,contact_person'))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.trim((string) $request->input('q')).'%';

                $q->where(fn ($inner) => $inner->where('name', 'like', $term)->orWhere('code', 'like', $term));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->input('status')))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('collaborator.projects.index', [
            'collaborator' => $collaborator,
            'projects' => $projects,
            'showValue' => $showValue,
            'showClient' => $showClient,
            'showCommission' => $showCommission,
            'referredCount' => $projectIds->count(),
            // One query, and only when they may see the figure at all.
            'earnedByProject' => $showCommission
                ? CollaboratorCommissionLedgerEntry::query()
                    ->where('collaborator_id', $collaborator->getKey())
                    ->whereIn('project_id', $projects->pluck('id'))
                    ->whereIn('purpose', [
                        LedgerEntryPurpose::ProjectCommission->value,
                        LedgerEntryPurpose::Reversal->value,
                        LedgerEntryPurpose::Clawback->value,
                    ])
                    ->selectRaw('project_id, COALESCE(SUM(signed_amount), 0) as earned')
                    ->groupBy('project_id')
                    ->pluck('earned', 'project_id')
                    ->map(static fn ($value): string => Money::of((string) $value))
                : collect(),
        ]);
    }
}
