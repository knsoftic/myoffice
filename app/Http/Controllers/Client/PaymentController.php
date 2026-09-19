<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ServesClientPortal;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPortal\ClientPortalListRequest;
use Illuminate\Contracts\View\View;

/**
 * Payments received from the client — `client.payments.index` (phase-05 §7, §8.10, §9.2, tests 71-73), filled by the
 * financial spine's `payments` section.
 *
 * `project_payments.client_id = ClientContext::clientId()` with an explicit column list that omits `collaborator_id`,
 * `collaborator_referral_id` and every commission column (spine §9, restated unchanged). A voided receipt is shown as
 * voided, never hidden.
 */
final class PaymentController extends Controller
{
    use ServesClientPortal;

    public function index(ClientPortalListRequest $request): View
    {
        $this->authorize('client_portal.payments');

        return $this->sectionList($request, 'payments');
    }
}
