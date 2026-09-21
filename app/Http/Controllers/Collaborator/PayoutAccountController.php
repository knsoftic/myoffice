<?php

declare(strict_types=1);

namespace App\Http\Controllers\Collaborator;

use App\Enums\CollaboratorActivityEvent;
use App\Enums\PayoutMethod;
use App\Http\Controllers\Collaborator\Concerns\ResolvesOwnCollaborator;
use App\Http\Controllers\Controller;
use App\Models\Collaborator\CollaboratorPayoutAccount;
use App\Services\Collaborator\CollaboratorActivityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Where a partner's own money should go — `collaborator.payout-accounts.*` (phase-10-12 §7.5).
 *
 * **A new destination is never verified** (§55). The partner supplies it; somebody in the office
 * checks it; only then may money be sent there, when
 * `collaborator.payout_account_verification_required` is on. A self-verifying account would make the
 * column decorative and the protection imaginary.
 *
 * Details are encrypted at rest and shown masked afterwards — including back to the partner who typed
 * them, because this screen's output is read over shoulders and in screenshots like any other.
 */
final class PayoutAccountController extends Controller
{
    use ResolvesOwnCollaborator;

    public function __construct(
        private readonly CollaboratorActivityService $activity,
    ) {}

    public function index(Request $request): View
    {
        $collaborator = $this->ownCollaborator($request);

        return view('collaborator.payout-accounts.index', [
            'collaborator' => $collaborator,
            'accounts' => CollaboratorPayoutAccount::query()
                ->where('collaborator_id', $collaborator->getKey())
                ->orderByDesc('is_default')
                ->orderBy('label')
                ->get(),
            'methods' => PayoutMethod::cases(),
            'verificationRequired' => (bool) setting('collaborator.payout_account_verification_required', true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $collaborator = $this->ownCollaborator($request);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'method' => ['required', Rule::enum(PayoutMethod::class)],
            'account_title' => ['required', 'string', 'max:150'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['required', 'string', 'min:6', 'max:34'],
            'is_default' => ['nullable', 'boolean'],
        ], [
            'account_title.required' => 'The name on the account, exactly as the bank holds it — a transfer '
                .'to a mismatched name is returned, and returning one is the most painful path in this system.',
        ]);

        $number = preg_replace('/\s+/', '', (string) $validated['account_number']);

        $account = DB::transaction(function () use ($collaborator, $validated, $number, $request): CollaboratorPayoutAccount {
            $makeDefault = (bool) ($validated['is_default'] ?? false)
                || CollaboratorPayoutAccount::query()->where('collaborator_id', $collaborator->getKey())->doesntExist();

            if ($makeDefault) {
                // `default_guard` makes two defaults physically impossible, so the old one is cleared
                // inside the same transaction rather than left for the insert to collide with.
                CollaboratorPayoutAccount::query()
                    ->where('collaborator_id', $collaborator->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            $row = new CollaboratorPayoutAccount;

            $row->forceFill([
                'collaborator_id' => $collaborator->getKey(),
                'label' => $validated['label'],
                'method' => $validated['method'],
                'account_title' => $validated['account_title'],
                'bank_name' => $validated['bank_name'] ?? null,
                // `encrypted:array`, never a string: nothing can accidentally log "the account" as one
                // readable value, and the map is what a transfer form actually needs.
                'details_encrypted' => array_filter([
                    'account_number' => $number,
                    'bank_name' => $validated['bank_name'] ?? null,
                    'account_title' => $validated['account_title'],
                ]),
                'account_last4' => mb_substr($number, -4),
                'is_default' => $makeDefault,
                // Never self-verified (§55): the partner supplies it, the office checks it.
                'is_verified' => false,
                'status' => 'active',
                'created_by' => $request->user()?->getKey(),
            ])->save();

            return $row->refresh();
        }, 3);

        $this->activity->record(
            CollaboratorActivityEvent::PayoutRequest,
            $collaborator,
            $account,
            ['transition' => 'payout_account_added', 'account' => $account->maskedAccount()],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was added. The office will check it before anything is sent there.',
                $account->maskedAccount()),
        ]);
    }
}
