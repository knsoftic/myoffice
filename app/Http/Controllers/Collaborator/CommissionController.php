<?php

declare(strict_types=1);

namespace App\Http\Controllers\Collaborator;

use App\Enums\CommissionStatus;
use App\Enums\LedgerEntryPurpose;
use App\Http\Controllers\Collaborator\Concerns\ResolvesOwnCollaborator;
use App\Http\Controllers\Controller;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * A partner's own commissions — `collaborator.commissions.*` (phase-10-12 §7.5, §8.11).
 *
 * **Scoped by the session, never by a request field**, and a row belonging to somebody else is a 404
 * rather than a 403: "that commission exists but is not yours" leaks that it exists.
 *
 * Which of the two money columns a partner may see is decided per commission type — a referrer of
 * students has `collaborator_portal.student_commission` and may hold nothing for projects. The query
 * is narrowed to the purposes they hold, so a project commission is not merely hidden on screen; it
 * never enters the result set.
 */
final class CommissionController extends Controller
{
    use ResolvesOwnCollaborator;

    public function index(Request $request): View
    {
        $collaborator = $this->ownCollaborator($request);
        $visible = $this->visiblePurposes($request);

        $entries = $this->filtered($request, (int) $collaborator->getKey(), $visible)
            ->latest('transaction_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('collaborator.commissions.index', [
            'collaborator' => $collaborator,
            'entries' => $entries,
            'statuses' => CommissionStatus::cases(),
            'purposes' => array_values(array_filter(
                LedgerEntryPurpose::cases(),
                static fn (LedgerEntryPurpose $p): bool => in_array($p->value, $visible, true),
            )),
            'range' => $this->range($request),
            'earnedInRange' => Money::of((string) ($this->filtered($request, (int) $collaborator->getKey(), $visible)
                ->reorder()
                ->toBase()
                ->sum('signed_amount') ?: Money::ZERO)),
        ]);
    }

    public function show(Request $request, CollaboratorCommissionLedgerEntry $entry): View
    {
        $collaborator = $this->ownCollaborator($request);

        $this->assertOwn($collaborator, $entry);

        if (! in_array($entry->purpose->value, $this->visiblePurposes($request), true)) {
            // They hold the other commission type's permission, not this one. Same answer as somebody
            // else's row: it is not theirs to see.
            abort(404);
        }

        $entry->load([
            'rule', 'entitlement',
            'studentFeePayment:id,receipt_no,amount,paid_on,student_fee_id',
            'original:id,amount,transaction_date,purpose',
            'reversals:id,reverses_entry_id,amount,purpose,transaction_date,notes',
        ]);

        return view('collaborator.commissions.show', [
            'collaborator' => $collaborator,
            'entry' => $entry,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The purposes this partner's permissions let them see.
     *
     * Reversals and clawbacks are always visible when anything is: a partner shown a credit and not the
     * debit that undid it would be reading a balance that cannot be reconciled with the one on their
     * wallet screen, which is worse than showing them the refund.
     *
     * @return list<string>
     */
    private function visiblePurposes(Request $request): array
    {
        $user = $request->user();
        $purposes = [];

        if ($user?->can('collaborator_portal.student_commission')) {
            $purposes[] = LedgerEntryPurpose::StudentCommission->value;
        }

        if ($user?->can('collaborator_portal.project_commission')) {
            $purposes[] = LedgerEntryPurpose::ProjectCommission->value;
        }

        if ($purposes === []) {
            return [];
        }

        return array_merge($purposes, [
            LedgerEntryPurpose::Reversal->value,
            LedgerEntryPurpose::Clawback->value,
            LedgerEntryPurpose::ManualAdjustment->value,
            LedgerEntryPurpose::WriteOff->value,
        ]);
    }

    /**
     * @param  list<string>  $visible
     * @return Builder<CollaboratorCommissionLedgerEntry>
     */
    private function filtered(Request $request, int $collaboratorId, array $visible): Builder
    {
        $range = $this->range($request);

        return CollaboratorCommissionLedgerEntry::query()
            ->where('collaborator_id', $collaboratorId)
            ->whereIn('purpose', $visible)
            // A cancelled commission is not shown at all here. Admin keeps the row and its reason;
            // a partner reading "cancelled — the student was not referred by you" on their own screen
            // is a conversation to have in person, not a line in a list.
            ->whereNot('status', CommissionStatus::Cancelled->value)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('purpose'), fn (Builder $q) => $q
                ->where('purpose', (string) $request->input('purpose'))
                ->whereIn('purpose', $visible))
            ->whereBetween('transaction_date', [$range->start()->toDateString(), $range->end()->toDateString()]);
    }

    private function range(Request $request): DateRange
    {
        return DateRange::make(
            $request->input('preset'),
            $request->input('from'),
            $request->input('to'),
        );
    }
}
