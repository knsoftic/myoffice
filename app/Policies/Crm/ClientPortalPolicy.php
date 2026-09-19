<?php

declare(strict_types=1);

namespace App\Policies\Crm;

use App\Models\User;
use App\Support\ClientPortalRegistry;
use App\Support\Modules;
use Illuminate\Auth\Access\Response;
use Throwable;

/**
 * May this user open a client-panel section (phase-05 §9.2 "Dashboard", §9.3, [D-P5-1], D28)?
 *
 * Not a model policy: it is registered as a gate ability (see the phase-05 integration list) and answers for a
 * section key. The three conditions of §9.3, in the order that decides the status code:
 *
 *   1. the section is registered in `ClientPortalRegistry` — else **404** (an unregistered section has no route
 *      and no nav item, §11 test 80);
 *   2. its module is enabled — else **404** (a registered section whose module is off disappears the same way);
 *   3. the user holds its `client_portal.*` permission — else 403.
 *
 * Which client's rows the section then shows is `ClientContext`'s business, never this check's.
 */
final class ClientPortalPolicy
{
    /** The gate ability name this method is registered under. */
    public const ABILITY = 'client-portal-section';

    public function section(User $user, string $key): Response|bool
    {
        try {
            $section = app(ClientPortalRegistry::class)->section($key);
        } catch (Throwable) {
            $section = null;
        }

        if ($section === null) {
            return Response::denyAsNotFound();
        }

        $module = $section->module();

        if ($module !== null && $module !== '' && ! Modules::enabled($module)) {
            return Response::denyAsNotFound();
        }

        return $user->can($section->permission());
    }
}
