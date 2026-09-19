<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClientPortal;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPortal\UpdateClientProfileRequest;
use App\Services\Crm\ClientPortalService;
use App\Services\Crm\Exceptions\CrmRuleException;
use App\Support\ClientContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The client's own company profile — `client.profile.edit`, `client.profile.update` (phase-05 §6.9, §8.10 Profile,
 * §9.2, test 81), `can:client_portal.profile`.
 *
 * The editable set is the §6.9 whitelist enforced by `UpdateClientProfileRequest`; tax numbers, status, payment terms,
 * currency and the account manager are shown **read-only** with a "contact your account manager" note. The client is
 * always `ClientContext`'s — `ClientPolicy::viewOwn` / `updateOwnProfile` answer 404 for anything else.
 */
final class ProfileController extends Controller
{
    use ServesClientPortal;

    public function __construct(
        private readonly ClientPortalService $portal,
    ) {}

    public function edit(Request $request): View
    {
        $this->authorize('client_portal.profile');

        $client = $this->client();
        $this->authorize('viewOwn', $client);

        $client->loadMissing('accountManager:id,name,email');

        return view('client.profile.edit', array_merge($this->portalViewData($request, $client), [
            'contact' => $this->contact(),
            'isContact' => $this->contact() !== null,
            // Shown, never editable here (§8.10).
            'readOnly' => [
                'client_code' => $client->client_code,
                'status' => $client->status,
                'tax_number' => $client->tax_number,
                'sales_tax_number' => $client->sales_tax_number,
                'payment_terms_days' => $client->payment_terms_days,
                'currency' => $client->currency,
                'account_manager' => $client->accountManager?->name,
            ],
        ]));
    }

    public function update(UpdateClientProfileRequest $request): RedirectResponse
    {
        $this->authorize('client_portal.profile');

        $client = $this->client();
        $this->authorize('updateOwnProfile', $client);

        try {
            $this->portal->updateProfile($client, $request->toData(), $this->contact());
        } catch (CrmRuleException $exception) {
            return back()
                ->withInput()
                ->withErrors($exception->errors())
                ->with('toast', ['type' => 'error', 'message' => (string) (collect($exception->errors())->flatten()->first() ?? $exception->getMessage())]);
        }

        app(ClientContext::class)->forget();

        return redirect()
            ->route('client.profile.edit')
            ->with('toast', ['type' => 'success', 'message' => 'Your profile was saved.']);
    }
}
