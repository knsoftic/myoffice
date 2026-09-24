<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Concerns;

use App\Enums\PanelType;
use Illuminate\Http\Request;

/**
 * What the four portal panels' support screens share (phase-19-23 §7.6, §8, §9.4).
 *
 * **One controller per subject, not one per subject per panel.** A client, a student, a teacher and
 * a collaborator all raise tickets, sit in meetings and send messages, and the *rules* differ by
 * person rather than by panel — §9.4 scopes on who you are, and `MessagingMatrix` on who you may
 * talk to. Four copies of each controller would be twelve files agreeing about that until the day
 * one of them was edited.
 *
 * **The panel is read from the route name**, the same trick `NotificationController` uses. Every
 * route below is registered unprefixed in `routes/portal-support.php` and prefixed by the including
 * group, so `route($this->panel($request).'.tickets.show', …)` resolves to the caller's own panel
 * without this code knowing which one it is. Parsing the URL instead would break the first time
 * somebody deployed the application in a subdirectory.
 *
 * **The view is resolved the same way**, with a shared partial underneath: `student.tickets.index`
 * is four lines of layout that `@include`s `support.tickets._index`. The alternative is the drift
 * §7.7 warned about, arriving one panel at a time.
 */
trait ServesPortalSupport
{
    protected function panel(Request $request): string
    {
        $name = (string) ($request->route()?->getName() ?? '');
        $prefix = strtok($name, '.') ?: PanelType::Admin->value;

        return in_array($prefix, array_column(PanelType::cases(), 'value'), true)
            ? $prefix
            : PanelType::Admin->value;
    }

    /** `student.tickets.index` from `tickets.index`. */
    protected function route(Request $request, string $name, mixed $parameters = []): string
    {
        return route($this->panel($request).'.'.$name, $parameters);
    }

    /** The panel's own view for a screen — `student.tickets.index`. */
    protected function screen(Request $request, string $name): string
    {
        return $this->panel($request).'.'.$name;
    }

    /**
     * This panel's permission for a portal ability.
     *
     * `client_portal.support_tickets`, `student_portal.support_tickets`, and so on — the shape every
     * portal module in `PermissionRegistry` already uses. Built rather than listed, because a list
     * would have to be extended by hand the next time a panel is added and the symptom would be a
     * screen that silently 403s.
     */
    protected function ability(Request $request, string $ability): string
    {
        return $this->panel($request).'_portal.'.$ability;
    }
}
