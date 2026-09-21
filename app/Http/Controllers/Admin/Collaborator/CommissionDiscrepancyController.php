<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Collaborator;

use App\Enums\CollaboratorActivityEvent;
use App\Enums\EntitlementStatus;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Collaborator\CollaboratorCommissionEntitlement;
use App\Services\Collaborator\CollaboratorActivityService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Promises that released more than they turned out to be worth — `admin.commission-discrepancies.*`
 * (phase-10-12 §7.4, §8.8, spine §6.6 rows 4 and 8).
 *
 * **Nothing is clawed back automatically here, and that is the point.** A discount applied after a
 * payment, or a project value revised downward, can leave a partner holding more than the new promise
 * would have allowed. No money left the company wrongly — it was released under the figures that were
 * true at the time — so silently taking back what somebody already banked because an accountant gave a
 * discount would be worse than a reported difference.
 *
 * Two actions, both human. **Post a manual adjustment** (`collaborator_commissions.create`, on the
 * commissions screen), which writes a real ledger row with a written reason. Or **accept it**, which
 * writes a note and closes the row and touches no money at all. Without the second, `over_released_amount`
 * could never be cleared and the queue would grow for ever (spine R-6).
 */
final class CommissionDiscrepancyController extends Controller
{
    public function __construct(
        private readonly CollaboratorActivityService $activity,
    ) {}

    public function index(Request $request): View
    {
        $rows = $this->filtered($request)
            ->with([
                'collaborator' => fn ($q) => $q->withTrashed()->select([
                    'id', 'collaborator_code', 'name', 'company_name', 'deleted_at',
                ]),
                'rule:id,version,commission_for',
            ])
            ->orderByDesc('over_released_amount')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.commission-discrepancies.index', [
            'rows' => $rows,
            'openTotal' => Money::of((string) ($this->filtered($request)
                ->reorder()
                ->toBase()
                ->sum('over_released_amount') ?: Money::ZERO)),
            'openCount' => (clone $this->filtered($request))->count(),
            'canAccept' => (bool) $request->user()?->can('collaborator_commissions.approve'),
            'canAdjust' => (bool) $request->user()?->can('collaborator_commissions.create'),
            'showResolved' => $request->boolean('resolved'),
            // Why each closed one was accepted. Read from the audit trail rather than stamped onto the
            // promise: `supersede_reason` already says why the promise changed, and overwriting it
            // would trade one explanation for another.
            'acceptedReasons' => $this->acceptedReasons($rows->pluck('id')->all()),
        ]);
    }

    /**
     * Accept the difference and note why — the action that does **not** move money.
     *
     * It closes the promise and records the reason in the audit trail. It deliberately does not clear
     * `over_released_amount`: the figure is a fact about what happened, and a queue that forgets what
     * it once held cannot answer "why was this accepted" a year later. `closed_on` is what takes the
     * row off the list.
     */
    public function accept(Request $request, CollaboratorCommissionEntitlement $entitlement): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'reason.required' => 'Accepting a difference is a decision somebody made. Say why — it is the '
                .'only record of it.',
            'reason.min' => 'A few words that will still make sense to whoever reads this next year.',
        ]);

        if (Money::compare((string) $entitlement->over_released_amount, Money::ZERO) !== 1) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'There is nothing to accept on this promise.',
            ]);
        }

        CollaboratorCommissionEntitlement::allowDirectWrites(function () use ($entitlement): void {
            $entitlement->forceFill([
                'status' => EntitlementStatus::Closed->value,
                'closed_on' => now()->toDateString(),
            ])->save();
        });

        $this->activity->record(
            CollaboratorActivityEvent::CommissionReversed,
            $entitlement->collaborator,
            $entitlement,
            [
                'transition' => 'discrepancy_accepted',
                'over_released' => (string) $entitlement->over_released_amount,
                'entitlement_amount' => (string) $entitlement->entitlement_amount,
                'released_amount' => (string) $entitlement->released_amount,
            ],
            $validated['reason'],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s accepted on promise #%s. No money was moved — the reason is on the audit trail.',
                Money::format((string) $entitlement->over_released_amount),
                (string) $entitlement->getKey()),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The reason recorded when each of these promises was accepted, keyed by id.
     *
     * One query for the page, and the newest row wins: a promise accepted, reopened by a later
     * supersede and accepted again should show the decision that is currently standing.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function acceptedReasons(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Activity::query()
            ->where('subject_type', (new CollaboratorCommissionEntitlement)->getMorphClass())
            ->whereIn('subject_id', $ids)
            ->whereNotNull('reason')
            // Subject plus a reason is enough to identify it: an entitlement is the subject of exactly
            // one audited act, and matching on a JSON property would tie this screen to the shape of
            // whatever `filterProperties()` decides to keep.
            ->orderBy('id')
            ->pluck('reason', 'subject_id')
            ->all();
    }

    /**
     * @return Builder<CollaboratorCommissionEntitlement>
     */
    private function filtered(Request $request): Builder
    {
        return CollaboratorCommissionEntitlement::query()
            ->where('over_released_amount', '>', 0)
            // A closed promise has been dealt with — by an adjustment or by somebody accepting it.
            // The toggle brings them back, because "what did we decide about this one" is a real
            // question and the answer is not on any other screen.
            ->when(
                $request->boolean('resolved'),
                fn (Builder $q) => $q->whereNotNull('closed_on'),
                fn (Builder $q) => $q->whereNull('closed_on'),
            )
            ->when($request->filled('collaborator'), fn (Builder $q) => $q
                ->where('collaborator_id', (int) $request->input('collaborator')))
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('q')).'%';

                $query->whereHas('collaborator', fn ($q) => $q
                    ->withTrashed()
                    ->where(fn ($inner) => $inner
                        ->where('name', 'like', $term)
                        ->orWhere('company_name', 'like', $term)
                        ->orWhere('collaborator_code', 'like', $term)));
            });
    }
}
