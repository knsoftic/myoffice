<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Collaborator;

use App\Enums\CollaboratorActivityEvent;
use App\Http\Controllers\Controller;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorPayoutAccount;
use App\Services\Collaborator\CollaboratorActivityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where a partner's money goes — `admin.payout-accounts.*` (phase-10-12 §7.4, spine §2.15).
 *
 * **Masked, always.** `details_encrypted` is never decrypted for any screen and no ability anywhere
 * reveals it (INV-C6): staff see a label, a bank and the last four digits, which is enough to choose a
 * destination and not enough to be worth stealing. That is why the module declares no
 * `view_financial` — there is nothing here to unmask.
 *
 * **Verification is a separate, permissioned act.** `is_verified` exists so that somebody who is not
 * the partner has looked at the destination and said yes; a column nobody writes would make §55's
 * protection decorative.
 */
final class PayoutAccountController extends Controller
{
    public function __construct(
        private readonly CollaboratorActivityService $activity,
    ) {}

    public function index(Collaborator $collaborator): View
    {
        return view('admin.payout-accounts.index', [
            'collaborator' => $collaborator,
            // 50, the same ceiling the collaborator's own screen uses: **an owner `where` is not a row
            // bound** (phase-24-25 section 6.4, PRF-05), and an account is never deleted once money has
            // gone to it.
            'accounts' => CollaboratorPayoutAccount::query()
                ->where('collaborator_id', $collaborator->getKey())
                ->with('verifier:id,name')
                ->orderByDesc('is_default')
                ->orderBy('label')
                ->limit(50)
                ->get(),
            'verificationRequired' => (bool) setting('collaborator.payout_account_verification_required', true),
        ]);
    }

    /**
     * Mark a destination as checked — or take the mark away again.
     *
     * Unverifying is deliberately the same route: a destination that turns out to be wrong has to be
     * stoppable by whoever noticed, and making that a different screen means it does not happen.
     */
    public function verify(Request $request, CollaboratorPayoutAccount $account): RedirectResponse
    {
        $verified = $request->boolean('verified', true);

        $validated = $request->validate([
            'verified' => ['nullable', 'boolean'],
            'note' => [$verified ? 'nullable' : 'required', 'string', 'max:255'],
        ], [
            'note.required' => 'Removing verification stops money going here. Say what is wrong with it.',
        ]);

        CollaboratorPayoutAccount::query()->whereKey($account->getKey())->update([
            'is_verified' => $verified,
            'verified_by' => $verified ? $request->user()?->getKey() : null,
            'verified_at' => $verified ? now() : null,
            'updated_at' => now(),
        ]);

        $account->refresh();

        $this->activity->record(
            CollaboratorActivityEvent::PayoutRequest,
            $account->collaborator,
            $account,
            [
                'transition' => $verified ? 'payout_account_verified' : 'payout_account_unverified',
                // The masked form, never the decrypted one: an audit row is read by more people than
                // the screen it describes (INV-C6).
                'account' => $account->maskedAccount(),
            ],
            $validated['note'] ?? null,
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => $verified
                ? sprintf('%s is verified. Payouts may be sent there.', $account->maskedAccount())
                : sprintf('%s is no longer verified. Nothing will be sent there until somebody checks it again.', $account->maskedAccount()),
        ]);
    }
}
