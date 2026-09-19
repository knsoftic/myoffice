<?php

declare(strict_types=1);

namespace App\Policies\Crm;

use App\Enums\Ability;
use App\Models\Crm\LeadActivity;
use App\Models\User;
use App\Policies\Crm\Concerns\ChecksCrmPermissions;
use Illuminate\Auth\Access\Response;
use Throwable;

/**
 * Who may see or change a timeline row (phase-05 §9.1, §9.3, §6.1).
 *
 * **Isolation follows the lead.** Every method resolves the parent lead (without the visibility scope, trash
 * included) and delegates to `LeadPolicy::view()`: a user who cannot see the lead gets **404** on its timeline
 * (§11 test 9).
 *
 * **System rows are sealed:** `update` and `delete` are false for them, for everyone the policy decides for (§11
 * test 32); the model hook refuses the write as well, which also covers Super Admin.
 *
 * **Manual rows** (§6.1 `updateActivity`, test 33) need `leads.edit` on a live lead, and then:
 *   · the author may change or remove their own row only inside `crm.activity_edit_window_minutes`;
 *   · a `leads.edit` holder may change or remove **someone else's** row at any time.
 */
final class LeadActivityPolicy
{
    use ChecksCrmPermissions;

    /** Used when `crm.activity_edit_window_minutes` cannot be read (the §5 default). */
    public const DEFAULT_EDIT_WINDOW_MINUTES = 1440;

    public function __construct(
        private readonly LeadPolicy $leads,
    ) {}

    public function view(User $user, LeadActivity $activity): Response|bool
    {
        $lead = $activity->resolveLead();

        if ($lead === null) {
            return Response::denyAsNotFound();
        }

        return $this->leads->view($user, $lead);
    }

    public function update(User $user, LeadActivity $activity): Response|bool
    {
        return $this->changeManualRow($user, $activity);
    }

    public function delete(User $user, LeadActivity $activity): Response|bool
    {
        return $this->changeManualRow($user, $activity);
    }

    private function changeManualRow(User $user, LeadActivity $activity): Response|bool
    {
        $visible = $this->view($user, $activity);

        if ($visible !== true) {
            return $visible;
        }

        if ($activity->isSystem() || $this->isTrashed($activity)) {
            return false;
        }

        $lead = $activity->resolveLead();

        if (! $this->holds($user, LeadPolicy::MODULE, Ability::Edit) || $this->isTrashed($lead)) {
            return false;
        }

        if (! $activity->isAuthoredBy($user)) {
            return true;
        }

        return $activity->isWithinEditWindow(self::editWindowMinutes());
    }

    private static function editWindowMinutes(): int
    {
        try {
            $minutes = setting('crm.activity_edit_window_minutes', self::DEFAULT_EDIT_WINDOW_MINUTES);
        } catch (Throwable) {
            $minutes = self::DEFAULT_EDIT_WINDOW_MINUTES;
        }

        return is_numeric($minutes) ? max(0, (int) $minutes) : self::DEFAULT_EDIT_WINDOW_MINUTES;
    }
}
