<?php

declare(strict_types=1);

namespace App\Policies\Crm;

use App\Models\Crm\LeadConversion;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may see and supersede a conversion record (phase-05 §2.4, §9.3).
 *
 * A conversion is visible exactly when its lead is (`LeadPolicy::view()`, 404 outside the §9 scope). Superseding
 * needs what converting needs — `leads.edit` **and** `clients.create` on a reachable lead (§6.4). The record is
 * append-only evidence (D19): `update` and `delete` are **always false**, and the model refuses both writes for
 * Super Admin too.
 */
final class LeadConversionPolicy
{
    public function __construct(
        private readonly LeadPolicy $leads,
    ) {}

    public function view(User $user, LeadConversion $conversion): Response|bool
    {
        $lead = $conversion->resolveLead();

        if ($lead === null) {
            return Response::denyAsNotFound();
        }

        return $this->leads->view($user, $lead);
    }

    /**
     * Only a live conversion can be superseded; a superseded one stays as it is.
     */
    public function supersede(User $user, LeadConversion $conversion): Response|bool
    {
        $lead = $conversion->resolveLead();

        if ($lead === null) {
            return Response::denyAsNotFound();
        }

        $visible = $this->leads->view($user, $lead);

        if ($visible !== true) {
            return $visible;
        }

        if (! $conversion->isActive()) {
            return false;
        }

        return $this->leads->convert($user, $lead);
    }

    public function update(User $user, LeadConversion $conversion): bool
    {
        return false;
    }

    public function delete(User $user, LeadConversion $conversion): bool
    {
        return false;
    }

    public function forceDelete(User $user, LeadConversion $conversion): bool
    {
        return false;
    }
}
